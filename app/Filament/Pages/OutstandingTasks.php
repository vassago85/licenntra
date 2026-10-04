<?php

namespace App\Filament\Pages;

use App\Actions\SubmitToAuthority;
use App\Enums\Province;
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

class OutstandingTasks extends Page
{
    protected string $view = 'filament.pages.outstanding-tasks';

    protected static ?string $title = 'Outstanding tasks';

    protected static ?string $navigationLabel = 'Outstanding tasks';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::ClipboardDocumentCheck;

    protected static ?int $navigationSort = 1;

    public ?string $tab = OperationsWorkloadService::TAB_OUTSTANDING;

    public ?string $search = null;

    public ?int $account_id = null;

    public ?int $submitted_by_id = null;

    public ?int $reviewer_id = null;

    public ?string $province = null;

    public bool $overdue = false;

    public ?int $submit = null;

    public ?string $kind = null;

    public ?string $stage = null;

    public ?string $scope = null;

    public string $viewMode = 'table';

    public function mount(): void
    {
        $this->tab = request()->string('tab', OperationsWorkloadService::TAB_OUTSTANDING)->value();
        $this->search = request()->string('search')->value() ?: null;
        $this->account_id = request()->integer('account_id') ?: null;
        $this->submitted_by_id = request()->integer('submitted_by_id') ?: null;
        $this->reviewer_id = request()->integer('reviewer_id') ?: null;
        $this->province = request()->string('province')->value() ?: null;
        $this->overdue = (bool) request()->integer('overdue');
        $this->submit = request()->integer('submit') ?: null;
        $this->kind = request()->string('kind')->value() ?: null;
        $this->stage = request()->string('stage')->value() ?: null;
        $this->scope = request()->string('scope')->value() ?: null;

        $this->viewMode = $this->normaliseView(
            request()->string('view')->value() ?: Session::get('outstanding_tasks.view', 'table'),
        );
    }

    public function updatedViewMode(string $value): void
    {
        $this->viewMode = $this->normaliseView($value);
        Session::put('outstanding_tasks.view', $this->viewMode);
    }

    /**
     * When the customer filter changes, drop any submitting-user selection
     * that is not part of the newly-selected customer. Keeps the two
     * selects in a consistent state.
     */
    public function updatedAccountId(): void
    {
        if ($this->submitted_by_id === null || $this->account_id === null) {
            return;
        }

        $belongs = User::query()
            ->where('id', $this->submitted_by_id)
            ->where('client_account_id', $this->account_id)
            ->exists();

        if (! $belongs) {
            $this->submitted_by_id = null;
        }
    }

    private function normaliseView(string $value): string
    {
        return in_array($value, ['table', 'cards'], true) ? $value : 'table';
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $service = app(OperationsWorkloadService::class);
        $user = auth()->user();

        $filters = [
            'account_id' => $this->account_id,
            'submitted_by_id' => $this->submitted_by_id,
            'reviewer_id' => $this->reviewer_id,
            'province' => $this->province,
            'overdue' => $this->overdue,
            'search' => $this->search,
            'kind' => $this->kind,
            'stage' => $this->stage,
            'scope' => $this->scope,
        ];

        $tasks = $service->tasks($this->validTab(), $filters, perPage: 25);

        $counts = [
            OperationsWorkloadService::TAB_OUTSTANDING => $service->tasks(OperationsWorkloadService::TAB_OUTSTANDING, $filters, perPage: 10000)->total(),
            OperationsWorkloadService::TAB_SUBMISSION_PACKS => $service->tasks(OperationsWorkloadService::TAB_SUBMISSION_PACKS, $filters, perPage: 10000)->total(),
            OperationsWorkloadService::TAB_AWAITING_RETURN => $service->tasks(OperationsWorkloadService::TAB_AWAITING_RETURN, $filters, perPage: 10000)->total(),
            OperationsWorkloadService::TAB_RETURNED_HANDOVER => $service->tasks(OperationsWorkloadService::TAB_RETURNED_HANDOVER, $filters, perPage: 10000)->total(),
        ];

        return [
            'tasks' => $tasks,
            'tab' => $this->validTab(),
            'counts' => $counts,
            'viewMode' => $this->viewMode,
            'tabLabels' => [
                OperationsWorkloadService::TAB_OUTSTANDING => 'Outstanding tasks',
                OperationsWorkloadService::TAB_SUBMISSION_PACKS => 'Submission packs',
                OperationsWorkloadService::TAB_AWAITING_RETURN => 'Awaiting return',
                OperationsWorkloadService::TAB_RETURNED_HANDOVER => 'Returned / ready for handover',
            ],
            'accounts' => ClientAccount::query()->orderBy('name')->pluck('name', 'id'),
            'reviewers' => User::query()
                ->whereHas('roles', fn ($q) => $q->where('name', 'reviewer'))
                ->orderBy('name')
                ->pluck('name', 'id'),
            'submittingUsers' => $this->submittingUserOptions(),
            'provinces' => collect(Province::cases())->mapWithKeys(fn (Province $p): array => [$p->value => $p->label()])->all(),
            'canReviewDocuments' => $user !== null && $user->hasAnyRole(['reviewer', 'customer_admin', 'super_admin']),
            'canVerifyPayments' => $user !== null && $user->hasAnyRole(['finance', 'customer_admin', 'super_admin']),
            'canSubmitToAuthority' => $user !== null && $user->hasAnyRole(['reviewer', 'customer_admin', 'super_admin']),
        ];
    }

    /**
     * The submitting-user dropdown narrows to the selected customer's users
     * when one is picked. Otherwise lists every client user with recorded
     * submissions so filtering remains useful.
     *
     * @return array<int, string>
     */
    private function submittingUserOptions(): array
    {
        $query = User::query()
            ->whereNotNull('client_account_id')
            ->orderBy('name');

        if ($this->account_id !== null) {
            $query->where('client_account_id', $this->account_id);
        } else {
            $query->whereIn(
                'id',
                Application::query()->whereNotNull('submitted_by_id')->distinct()->pluck('submitted_by_id')
            );
        }

        return $query->pluck('name', 'id')->all();
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->is_active
            && $user->hasAnyRole(['super_admin', 'customer_admin', 'reviewer', 'finance']);
    }

    public function submitToAuthorityAction(): Action
    {
        return Action::make('submitToAuthority')
            ->label('Submit to authority')
            ->icon(Heroicon::PaperAirplane)
            ->color('primary')
            ->visible(fn (): bool => $this->userCanSubmitToAuthority())
            ->modalHeading(fn (array $arguments): string => 'Submit '.$this->applicationFromArguments($arguments)?->reference.' to authority')
            ->modalDescription('Capture the authority reference and the submission date. The application will move to "At the authority" and the handover will be audited.')
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

    private function validTab(): string
    {
        return in_array($this->tab, [
            OperationsWorkloadService::TAB_OUTSTANDING,
            OperationsWorkloadService::TAB_SUBMISSION_PACKS,
            OperationsWorkloadService::TAB_AWAITING_RETURN,
            OperationsWorkloadService::TAB_RETURNED_HANDOVER,
            // Legacy aliases - older URLs/counters still work.
            OperationsWorkloadService::TAB_APPROVALS,
            OperationsWorkloadService::TAB_READY_TO_SUBMIT,
            OperationsWorkloadService::TAB_WAITING_ON_DEALERSHIP,
            OperationsWorkloadService::TAB_ALL,
        ], true) ? $this->tab : OperationsWorkloadService::TAB_OUTSTANDING;
    }
}
