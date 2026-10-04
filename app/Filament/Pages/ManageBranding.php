<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\OnlyConfigurators;
use App\Models\BrandingSetting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
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
use UnitEnum;

class ManageBranding extends Page
{
    use OnlyConfigurators;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaintBrush;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Branding';

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'Branding';

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
                Section::make('Identity')
                    ->description('What your clients see across the portal, email and PDF deliverables.')
                    ->icon(Heroicon::OutlinedBuildingOffice)
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('company_name')
                                ->label('Company name')
                                ->required()
                                ->maxLength(120)
                                ->helperText('Appears in the header, email, and application PDFs.'),
                            TextInput::make('reference_prefix')
                                ->label('Reference prefix')
                                ->required()
                                ->maxLength(12)
                                ->helperText('Example: SUS gives SUS-2026-00001.')
                                ->extraAttributes(['class' => 'font-mono']),
                        ]),
                        FileUpload::make('logo_path')
                            ->label('Logo')
                            ->image()
                            ->imageEditor()
                            ->disk('public')
                            ->directory('branding')
                            ->visibility('public')
                            ->maxSize(1024)
                            ->helperText('PNG or SVG. Up to 1 MB.'),
                        ColorPicker::make('primary_colour')
                            ->label('Primary colour')
                            ->required()
                            ->regex('/^#[0-9A-Fa-f]{6}$/')
                            ->helperText('Used for buttons, badges and the sign-in page.'),
                    ]),

                Section::make('Support contact')
                    ->description('Shown to clients when they need help.')
                    ->icon(Heroicon::OutlinedUserCircle)
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('support_email')
                                ->label('Support email')
                                ->email()
                                ->maxLength(160),
                            TextInput::make('support_phone')
                                ->label('Support phone')
                                ->tel()
                                ->maxLength(40),
                        ]),
                        TextInput::make('address')
                            ->label('Street address')
                            ->maxLength(500),
                    ]),
            ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();

        BrandingSetting::current()->update($state);

        $this->form->fill($this->currentData());

        Notification::make()
            ->title('Branding saved')
            ->body('Clients will see the new values the next time they load a page.')
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
                ->label('Save branding')
                ->submit('save')
                ->keyBindings(['mod+s']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function currentData(): array
    {
        $branding = BrandingSetting::current();

        return [
            'company_name' => $branding->company_name,
            'primary_colour' => $branding->primary_colour,
            'support_email' => $branding->support_email,
            'support_phone' => $branding->support_phone,
            'address' => $branding->address,
            'reference_prefix' => $branding->reference_prefix,
            'logo_path' => $branding->logo_path,
        ];
    }
}
