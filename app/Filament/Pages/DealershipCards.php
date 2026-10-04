<?php

namespace App\Filament\Pages;

use App\Actions\SubmitToAuthority;
use App\Enums\ApplicationStage;
use App\Models\Application;
use App\Models\ClientAccount;
use App\Models\User;
use App\Services\OperationsWorkloadService;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Session;

/**
 * Per-dealership card board.
 *
 * Internal staff talk to dealerships about a specific vehicle (quoted by
 * registration number or VIN), not by application reference. This page
 * narrows the view to one dealership and surfaces every active application
 * as a card with the registration number as the hero field.
 *
 * The next action per card is derived from the stage and gated against
 * the viewing user's role (reviewer / finance / admin).
 */
class DealershipCards extends Page
{
    protected string $view = 'filament.pages.dealership-cards';

    protected static ?string $title = 'Dealership board';

    protected static ?string $navigationLabel = 'Dealership board';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::Squares2x2;

    protected static ?int $navigationSort = 2;

    protected static bool $shouldRegisterNavigation = false;

    public ?int $account_id = null;

    public ?string $search = null;

    public ?string $stage = null;

    public bool $overdue = false;

    public bool $mine = false;

    public ?int $submit = null;

    public string $viewMode = 'cards';

    public function mount(): void
    {
        $this->account_id = request()->integer('account_id') ?: null;
        $this->search = request()->string('search')->value() ?: null;
        $this->stage = request()->string('stage')->value() ?: null;
        $this->overdue = (bool) request()->integer('overdue');
        $this->mine = (bool) request()->integer('mine');

        $this->viewMode = $this->normaliseView(
            request()->string('view')->value() ?: Session::get('dealership_cards.view', 'cards'),
        );

        abort_unless($this->account(), 404);
    }

    public function updatedViewMode(string $value): void
    {
        $this->viewMode = $this->normaliseView($value);
        Session::put('dealership_cards.view', $this->viewMode);
    }

    private function normaliseView(string $value): string
    {
        return in_array($value, ['table', 'cards'], true) ? $value : 'cards';
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->is_active
            && $user->hasAnyRole(['super_admin', 'customer_admin', 'reviewer', 'finance', 'auditor']);
    }

    public function getTitle(): string
    {
        $account = $this->account();

        return $account ? $account->name : (string) static::$title;
    }

    public function getHeading(): string
    {
        return $this->getTitle();
    }

    public function getSubheading(): ?string
    {
        $account = $this->account();

        if ($account === null) {
            return null;
        }

        $parts = array_filter([
            $account->type?->label(),
            $account->isOnAccount()
                ? 'On statement'.($account->payment_terms_days ? ' ('.$account->payment_terms_days.'-day terms)' : '')
                : 'Pay per transaction',
        ]);

        return implode(' · ', $parts);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $account = $this->account();
        $service = app(OperationsWorkloadService::class);
        $user = auth()->user();

        $filters = [
            'search' => $this->search,
            'stage' => $this->stage,
            'overdue' => $this->overdue,
            'assigned_to' => $this->mine && $user?->hasRole('reviewer') ? $user->id : null,
        ];

        $cards = $service->applicationCards($account, $filters);

        $stageOptions = collect(ApplicationStage::cases())
            ->reject(fn (ApplicationStage $s): bool => $s->isTerminal())
            ->mapWithKeys(fn (ApplicationStage $s): array => [$s->value => $s->label()])
            ->all();

        return [
            'account' => $account,
            'cards' => $cards,
            'viewMode' => $this->viewMode,
            'stageOptions' => $stageOptions,
            'reviewerPicker' => $user?->hasRole('reviewer') ?? false,
            'canReviewDocuments' => $user?->hasAnyRole(['reviewer', 'customer_admin', 'super_admin']) ?? false,
            'canVerifyPayments' => $user?->hasAnyRole(['finance', 'customer_admin', 'super_admin']) ?? false,
            'canSubmitToAuthority' => $user?->hasAnyRole(['reviewer', 'customer_admin', 'super_admin']) ?? false,
            'canBuildQuotes' => $user?->hasAnyRole(['reviewer', 'customer_admin', 'super_admin']) ?? false,
            'allDealerships' => ClientAccount::query()->orderBy('name')->pluck('name', 'id'),
            'summary' => [
                'total' => $cards->count(),
                'overdue' => $cards->where('overdue', true)->count(),
                'payment_owed' => $cards->where('payment_owed', true)->count(),
                'payment_awaiting_verification' => $cards->where('payment_awaiting_verification', true)->count(),
                'ready_for_authority' => $cards->where('is_ready_for_authority', true)->count(),
            ],
        ];
    }

    public function submitToAuthorityAction(): Action
    {
        return Action::make('submitToAuthority')
            ->label('Submit to authority')
            ->icon(Heroicon::PaperAirplane)
            ->color('primary')
            ->visible(fn (): bool => $this->userCanSubmitToAuthority())
            ->modalHeading(fn (array $arguments): string => 'Submit '.$this->applicationFromArguments($arguments)?->reference.' to authority')
            ->schema([
                TextInput::make('authority_reference')
                    ->label('Authority reference')
                    ->placeholder('e.g. GP-2026-00123')
                    ->required()
                    ->maxLength(255),
                DateTimePicker::make('authority_submitted_at')
                    ->label('Submitted at')
                    ->seconds(false)
                    ->default(fn () => now())
                    ->maxDate(fn (): \DateTimeInterface => now())
                    ->required(),
            ])
            ->action(function (array $arguments, array $data): void {
                abort_unless($this->userCanSubmitToAuthority(), 403);

                $application = $this->applicationFromArguments($arguments);

                if ($application === null) {
                    return;
                }

                app(SubmitToAuthority::class)->handle(
                    $application,
                    auth()->user(),
                    (string) $data['authority_reference'],
                    Carbon::parse($data['authority_submitted_at']),
                );
            })
            ->modalSubmitActionLabel('Submit to authority')
            ->successNotificationTitle('Submitted to authority');
    }

    private function userCanSubmitToAuthority(): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->is_active
            && $user->hasAnyRole(['reviewer', 'customer_admin', 'super_admin']);
    }

    private function applicationFromArguments(array $arguments): ?Application
    {
        $id = (int) ($arguments['application'] ?? 0);

        return $id > 0 ? Application::query()->find($id) : null;
    }

    private function account(): ?ClientAccount
    {
        if ($this->account_id === null) {
            return null;
        }

        return ClientAccount::query()->find($this->account_id);
    }
}
