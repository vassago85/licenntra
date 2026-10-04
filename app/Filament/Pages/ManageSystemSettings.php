<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\OnlyConfigurators;
use App\Models\SystemSetting;
use App\Services\NotificationDispatcher;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class ManageSystemSettings extends Page
{
    use OnlyConfigurators;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'System settings';

    protected static ?int $navigationSort = 20;

    protected static ?string $title = 'System settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill($this->currentData());
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getFormContentComponent(),
        ]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make($this->getFormActions())
                    ->sticky()
                    ->key('form-actions'),
            ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Finance')
                    ->description('Applied to new fee snapshots. Existing applications keep the fees they were quoted.')
                    ->icon(Heroicon::OutlinedCurrencyDollar)
                    ->schema([
                        TextInput::make('vat_percent')
                            ->label('VAT (%)')
                            ->numeric()
                            ->step(0.01)
                            ->minValue(0)
                            ->maxValue(100)
                            ->required()
                            ->helperText('South African standard rate is 15%. Shown on fee snapshots and quotes.'),
                    ]),

                Section::make('Security')
                    ->description('Session and sign-in policy for everyone in this deployment.')
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('idle_timeout_minutes')
                                ->label('Idle timeout (minutes)')
                                ->numeric()
                                ->minValue(5)
                                ->maxValue(240)
                                ->required()
                                ->helperText('How long a session may sit idle before sign-out.'),
                            TextInput::make('absolute_timeout_minutes')
                                ->label('Absolute timeout (minutes)')
                                ->numeric()
                                ->minValue(30)
                                ->maxValue(1440)
                                ->required()
                                ->helperText('Hard sign-out after this long, even if the session is active.'),
                        ]),
                        Toggle::make('enforce_client_two_factor')
                            ->label('Require two-factor for client users')
                            ->helperText('When on, client admins and client users must set up TOTP or a passkey before they can submit work.')
                            ->inline(false),
                    ]),

                Section::make('Retention')
                    ->description('How long you keep business-client identity and address documents between jobs.')
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->schema([
                        Grid::make(2)->schema([
                            TagsInput::make('retention_period_options')
                                ->label('Months a client can choose from')
                                ->placeholder('Add a value')
                                ->helperText('Clients tick one of these when they save a business client.')
                                ->splitKeys(['Tab', ',']),
                            TextInput::make('retention_max_months')
                                ->label('Maximum retention (months)')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(120)
                                ->required()
                                ->helperText('Hard ceiling. Client selections above this are refused.'),
                        ]),
                        TextInput::make('archive_after_days')
                            ->label('Archive completed work after (days)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(3650)
                            ->required()
                            ->helperText('Applications in Completed are moved to Archived after this many days.'),
                        TextInput::make('retention_wording_version')
                            ->label('Consent wording version')
                            ->maxLength(20)
                            ->required()
                            ->helperText('Bump when you change the wording below. Previous consents stay on the old version.'),
                        Textarea::make('retention_wording')
                            ->label('Consent wording')
                            ->rows(4)
                            ->required()
                            ->helperText('Shown to the client when they confirm the retention period. Plain text.'),
                    ]),

                Section::make('Email (Mailgun)')
                    ->description('Outgoing mail provider. Values set here override the matching MAILGUN_* and MAIL_FROM_* entries in .env at runtime.')
                    ->icon(Heroicon::OutlinedEnvelope)
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('mailgun_domain')
                                ->label('Mailgun domain')
                                ->placeholder('mg.example.co.za')
                                ->maxLength(190)
                                ->helperText('The verified sending domain in your Mailgun dashboard.'),
                            TextInput::make('mailgun_endpoint')
                                ->label('API endpoint')
                                ->placeholder('api.mailgun.net or api.eu.mailgun.net')
                                ->maxLength(190)
                                ->helperText('Use the EU endpoint if your Mailgun account is EU-hosted.'),
                        ]),
                        TextInput::make('mailgun_secret')
                            ->label('Mailgun API key')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->placeholder($this->mailgunSecretPlaceholder())
                            ->helperText('Leave blank to keep the stored key. Only overwritten when a new value is typed.'),
                        Grid::make(2)->schema([
                            TextInput::make('mail_from_address')
                                ->label('From address')
                                ->email()
                                ->maxLength(190)
                                ->placeholder('no-reply@example.co.za')
                                ->helperText('All workflow emails will come from this address.'),
                            TextInput::make('mail_from_name')
                                ->label('From name')
                                ->maxLength(190)
                                ->placeholder('Your licensing company')
                                ->helperText('Defaults to the branding company name when blank.'),
                        ]),
                        Toggle::make('notifications_enabled')
                            ->label('Send workflow notifications')
                            ->helperText('Master switch. When off, no quote, payment, or submission emails are dispatched. Test emails still work.')
                            ->inline(false),
                    ]),
            ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();

        $options = array_values(array_filter(array_map(
            static fn ($value): int => (int) $value,
            (array) ($state['retention_period_options'] ?? []),
        ), static fn (int $value): bool => $value > 0));

        if ($options === []) {
            throw ValidationException::withMessages([
                'data.retention_period_options' => 'Add at least one retention period.',
            ]);
        }

        if ($state['idle_timeout_minutes'] >= $state['absolute_timeout_minutes']) {
            throw ValidationException::withMessages([
                'data.absolute_timeout_minutes' => 'The absolute timeout must be longer than the idle timeout.',
            ]);
        }

        if (max($options) > $state['retention_max_months']) {
            throw ValidationException::withMessages([
                'data.retention_period_options' => 'A retention option is above the maximum.',
            ]);
        }

        $payload = [
            'vat_basis_points' => (int) round(((float) $state['vat_percent']) * 100),
            'idle_timeout_minutes' => (int) $state['idle_timeout_minutes'],
            'absolute_timeout_minutes' => (int) $state['absolute_timeout_minutes'],
            'retention_period_options' => $options,
            'retention_max_months' => (int) $state['retention_max_months'],
            'archive_after_days' => (int) $state['archive_after_days'],
            'enforce_client_two_factor' => (bool) ($state['enforce_client_two_factor'] ?? false),
            'retention_wording_version' => (string) $state['retention_wording_version'],
            'retention_wording' => (string) $state['retention_wording'],
            'mailgun_domain' => $this->emptyToNull($state['mailgun_domain'] ?? null),
            'mailgun_endpoint' => $this->emptyToNull($state['mailgun_endpoint'] ?? null),
            'mail_from_address' => $this->emptyToNull($state['mail_from_address'] ?? null),
            'mail_from_name' => $this->emptyToNull($state['mail_from_name'] ?? null),
            'notifications_enabled' => (bool) ($state['notifications_enabled'] ?? false),
        ];

        $newSecret = $this->emptyToNull($state['mailgun_secret'] ?? null);
        if ($newSecret !== null) {
            $payload['mailgun_secret'] = $newSecret;
        }

        SystemSetting::current()->update($payload);

        $this->form->fill($this->currentData());

        Notification::make()
            ->title('System settings saved')
            ->success()
            ->send();
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Save settings')
                ->submit('save')
                ->keyBindings(['mod+s']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function currentData(): array
    {
        $settings = SystemSetting::current();

        return [
            'vat_percent' => round($settings->vat_basis_points / 100, 2),
            'idle_timeout_minutes' => $settings->idle_timeout_minutes,
            'absolute_timeout_minutes' => $settings->absolute_timeout_minutes,
            'enforce_client_two_factor' => (bool) $settings->enforce_client_two_factor,
            'retention_period_options' => array_map(
                static fn ($value): string => (string) $value,
                $settings->retention_period_options ?? [],
            ),
            'retention_max_months' => $settings->retention_max_months,
            'archive_after_days' => $settings->archive_after_days,
            'retention_wording_version' => (string) $settings->retention_wording_version,
            'retention_wording' => (string) $settings->retention_wording,
            'mailgun_domain' => (string) ($settings->mailgun_domain ?? ''),
            'mailgun_endpoint' => (string) ($settings->mailgun_endpoint ?? ''),
            'mailgun_secret' => '',
            'mail_from_address' => (string) ($settings->mail_from_address ?? ''),
            'mail_from_name' => (string) ($settings->mail_from_name ?? ''),
            'notifications_enabled' => (bool) $settings->notifications_enabled,
        ];
    }

    private function mailgunSecretPlaceholder(): string
    {
        return SystemSetting::current()->hasMailgunCredentials()
            ? 'Stored. Type to replace.'
            : 'Paste the Mailgun API key';
    }

    private function emptyToNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('send_test_email')
                ->label('Send test email')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('gray')
                ->schema([
                    TextInput::make('recipient')
                        ->label('Send to')
                        ->email()
                        ->required()
                        ->default(auth()->user()?->email),
                ])
                ->action(function (array $data): void {
                    try {
                        app(NotificationDispatcher::class)->sendTestEmail($data['recipient']);
                    } catch (\Throwable $exception) {
                        Notification::make()
                            ->title('Test email failed')
                            ->body($exception->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Test email sent')
                        ->body('Delivery dispatched to '.$data['recipient'].'. Watch your inbox and Mailgun logs.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
