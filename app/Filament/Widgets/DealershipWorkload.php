<?php

namespace App\Filament\Widgets;

use App\Enums\BillingMode;
use App\Enums\ClientAccountType;
use App\Models\ClientAccount;
use App\Models\User;
use App\Services\OperationsWorkloadService;
use App\Support\Money;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Session;

/**
 * One row per client account, showing every bucket of outstanding work
 * for that account. Each count links to the Outstanding tasks list
 * scoped to that account and task kind so dashboard counts, dealership
 * row counts, and the filtered task lists always agree.
 */
class DealershipWorkload extends TableWidget
{
    protected static ?string $heading = 'Dealership workload';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -4;

    public function table(Table $table): Table
    {
        $service = app(OperationsWorkloadService::class);
        $reviewerId = $this->activeReviewerId();

        $rows = $this->currentRows($service);

        $accountIds = $rows->pluck('id')->all();

        $linkFor = function (string $tab, int $accountId, array $extra = []) use ($service, $reviewerId): string {
            $params = array_merge(['account_id' => $accountId], $extra);

            if ($reviewerId !== null) {
                $params['reviewer_id'] = $reviewerId;
            }

            return $service->tabUrl($tab, $params);
        };

        return $table
            ->query(fn (): Builder => ClientAccount::query()
                ->when(count($accountIds) === 0, fn (Builder $q) => $q->whereRaw('1 = 0'))
                ->whereIn('id', $accountIds))
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('No dealership action needed')
            ->emptyStateDescription('Clear the filter to see all accounts, including those with no outstanding work.')
            ->columns([
                TextColumn::make('name')
                    ->label('Dealership')
                    ->searchable()
                    ->description(fn (ClientAccount $record): ?string => $record->type instanceof ClientAccountType ? $record->type->label() : (string) $record->type)
                    ->url(fn (ClientAccount $record): string => $service->cardsUrl($record))
                    ->weight('medium')
                    ->color('primary')
                    ->tooltip('Open this dealership\'s card board')
                    ->wrap(),

                TextColumn::make('active_applications')
                    ->label('Active (apps)')
                    ->state(fn (ClientAccount $record): int => $this->findRow($rows, $record->id)['active_applications'] ?? 0)
                    ->alignEnd()
                    ->url(fn (ClientAccount $record): string => $linkFor(OperationsWorkloadService::TAB_ALL, (int) $record->id, ['scope' => OperationsWorkloadService::SCOPE_ACTIVE]))
                    ->extraAttributes(['class' => 'font-mono text-sm'])
                    ->tooltip('All non-terminal applications for this dealership (count of applications)'),

                TextColumn::make('awaiting_document_approval')
                    ->label('Docs to approve (apps)')
                    ->state(fn (ClientAccount $record): int => $this->findRow($rows, $record->id)['awaiting_document_approval'] ?? 0)
                    ->alignEnd()
                    ->url(fn (ClientAccount $record): string => $linkFor(OperationsWorkloadService::TAB_APPROVALS, (int) $record->id, ['kind' => OperationsWorkloadService::KIND_DOCUMENT]))
                    ->color(fn (ClientAccount $record): string => ($this->findRow($rows, $record->id)['awaiting_document_approval'] ?? 0) > 0 ? 'warning' : 'gray')
                    ->extraAttributes(['class' => 'font-mono text-sm'])
                    ->tooltip('Applications with required documents waiting for a reviewer'),

                TextColumn::make('waiting_on_dealership_docs')
                    ->label('Docs & corrections (apps)')
                    ->state(fn (ClientAccount $record): int => $this->findRow($rows, $record->id)['waiting_on_dealership_docs'] ?? 0)
                    ->alignEnd()
                    ->url(fn (ClientAccount $record): string => $linkFor(OperationsWorkloadService::TAB_WAITING_ON_DEALERSHIP, (int) $record->id, ['stage' => OperationsWorkloadService::WAITING_DRAFT_OR_CORRECTIONS]))
                    ->color(fn (ClientAccount $record): string => ($this->findRow($rows, $record->id)['waiting_on_dealership_docs'] ?? 0) > 0 ? 'info' : 'gray')
                    ->extraAttributes(['class' => 'font-mono text-sm'])
                    ->tooltip('Draft submissions and corrections the dealership still owes'),

                TextColumn::make('quotes_awaiting_dealership')
                    ->label('Quotes out (apps)')
                    ->state(fn (ClientAccount $record): int => $this->findRow($rows, $record->id)['quotes_awaiting_dealership'] ?? 0)
                    ->alignEnd()
                    ->url(fn (ClientAccount $record): string => $linkFor(OperationsWorkloadService::TAB_WAITING_ON_DEALERSHIP, (int) $record->id, ['stage' => OperationsWorkloadService::WAITING_QUOTE_SENT]))
                    ->color(fn (ClientAccount $record): string => ($this->findRow($rows, $record->id)['quotes_awaiting_dealership'] ?? 0) > 0 ? 'info' : 'gray')
                    ->extraAttributes(['class' => 'font-mono text-sm'])
                    ->tooltip('Sent quotes awaiting a dealership decision'),

                TextColumn::make('payments_owed_by_dealership')
                    ->label('Payments owed (apps)')
                    ->state(fn (ClientAccount $record): int => $this->findRow($rows, $record->id)['payments_owed_by_dealership'] ?? 0)
                    ->alignEnd()
                    ->url(fn (ClientAccount $record): string => $linkFor(OperationsWorkloadService::TAB_WAITING_ON_DEALERSHIP, (int) $record->id, ['stage' => OperationsWorkloadService::WAITING_PAYMENT_PENDING]))
                    ->color(fn (ClientAccount $record): string => ($this->findRow($rows, $record->id)['payments_owed_by_dealership'] ?? 0) > 0 ? 'info' : 'gray')
                    ->extraAttributes(['class' => 'font-mono text-sm'])
                    ->tooltip('Fee snapshotted, dealership has not paid yet (pay-per-transaction accounts only)'),

                TextColumn::make('statement_outstanding_cents')
                    ->label('On statement (R)')
                    ->state(function (ClientAccount $record) use ($rows): string {
                        $cents = $this->findRow($rows, $record->id)['statement_outstanding_cents'] ?? 0;

                        if ($cents === 0 && ! ($record->billing_mode === BillingMode::AccountStatement)) {
                            return '-';
                        }

                        return Money::rands($cents);
                    })
                    ->alignEnd()
                    ->color(function (ClientAccount $record) use ($rows): string {
                        $cents = $this->findRow($rows, $record->id)['statement_outstanding_cents'] ?? 0;

                        if ($cents === 0) {
                            return 'gray';
                        }

                        return $cents > 500000 ? 'warning' : 'info';
                    })
                    ->extraAttributes(['class' => 'font-mono text-sm'])
                    ->tooltip('Unsettled balance on the dealership\'s monthly statement. Dealerships are invoiced after the documents are handed over.'),

                TextColumn::make('payments_awaiting_verification')
                    ->label('To verify (payments)')
                    ->state(fn (ClientAccount $record): int => $this->findRow($rows, $record->id)['payments_awaiting_verification'] ?? 0)
                    ->alignEnd()
                    ->url(fn (ClientAccount $record): string => $linkFor(OperationsWorkloadService::TAB_APPROVALS, (int) $record->id, ['kind' => OperationsWorkloadService::KIND_PAYMENT]))
                    ->color(fn (ClientAccount $record): string => ($this->findRow($rows, $record->id)['payments_awaiting_verification'] ?? 0) > 0 ? 'warning' : 'gray')
                    ->extraAttributes(['class' => 'font-mono text-sm'])
                    ->tooltip('Payment uploaded, finance has not verified (count of payment rows)'),

                TextColumn::make('ready_for_authority_submission')
                    ->label('Ready to submit (apps)')
                    ->state(fn (ClientAccount $record): int => $this->findRow($rows, $record->id)['ready_for_authority_submission'] ?? 0)
                    ->alignEnd()
                    ->url(fn (ClientAccount $record): string => $linkFor(OperationsWorkloadService::TAB_READY_TO_SUBMIT, (int) $record->id))
                    ->color(fn (ClientAccount $record): string => ($this->findRow($rows, $record->id)['ready_for_authority_submission'] ?? 0) > 0 ? 'info' : 'gray')
                    ->extraAttributes(['class' => 'font-mono text-sm'])
                    ->tooltip('All checks passed. Submit to the authority with a reference and date.'),

                TextColumn::make('overdue')
                    ->label('Overdue (apps)')
                    ->state(fn (ClientAccount $record): int => $this->findRow($rows, $record->id)['overdue'] ?? 0)
                    ->alignEnd()
                    ->url(fn (ClientAccount $record): string => $linkFor(OperationsWorkloadService::TAB_ALL, (int) $record->id, ['overdue' => 1]))
                    ->color(fn (ClientAccount $record): string => ($this->findRow($rows, $record->id)['overdue'] ?? 0) > 0 ? 'danger' : 'gray')
                    ->extraAttributes(['class' => 'font-mono text-sm'])
                    ->tooltip('Applications past the stage SLA'),

                TextColumn::make('oldest_outstanding_at')
                    ->label('Oldest open task')
                    ->state(function (ClientAccount $record) use ($rows): string {
                        $value = $this->findRow($rows, $record->id)['oldest_outstanding_at'] ?? null;

                        return $value ? $value->diffForHumans() : '-';
                    })
                    ->color(function (ClientAccount $record) use ($rows): string {
                        $value = $this->findRow($rows, $record->id)['oldest_outstanding_at'] ?? null;

                        if ($value === null) {
                            return 'gray';
                        }

                        $days = $value->diffInDays(now());

                        return match (true) {
                            $days > 10 => 'danger',
                            $days > 5 => 'warning',
                            default => 'gray',
                        };
                    }),
            ])
            ->filters([
                Filter::make('search')
                    ->schema([TextInput::make('search')->label('Dealership name')->placeholder('e.g. Highveld')->live(debounce: 300)])
                    ->query(fn (Builder $q, array $data) => $q->when($data['search'] ?? null, fn (Builder $qq, string $term) => $qq->where('name', 'like', '%'.$term.'%')))
                    ->indicateUsing(fn (array $data): ?string => ($data['search'] ?? null) ? 'Name: '.$data['search'] : null),

                Filter::make('account_id')
                    ->schema([
                        Select::make('account_id')
                            ->label('Dealership')
                            ->placeholder('All dealerships')
                            ->options(fn (): array => ClientAccount::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable(),
                    ])
                    ->query(fn (Builder $q, array $data) => $q->when($data['account_id'] ?? null, fn (Builder $qq, int $id) => $qq->where('id', $id)))
                    ->indicateUsing(fn (array $data): ?string => ($data['account_id'] ?? null) ? 'One dealership' : null),

                Filter::make('reviewer_id')
                    ->schema([
                        Select::make('reviewer_id')
                            ->label('Assigned reviewer')
                            ->placeholder('Any reviewer')
                            ->options(function (): array {
                                return User::query()
                                    ->whereHas('roles', fn (Builder $q) => $q->where('name', 'reviewer'))
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all();
                            }),
                    ])
                    ->query(function (Builder $q, array $data) use ($service): Builder {
                        if (empty($data['reviewer_id'])) {
                            Session::forget('dealership_workload.reviewer_id');

                            return $q;
                        }

                        Session::put('dealership_workload.reviewer_id', (int) $data['reviewer_id']);
                        $filteredRows = $service->accountRows([
                            'reviewer_id' => (int) $data['reviewer_id'],
                            'needs_action_only' => Session::get('dealership_workload.needs_action_only', true),
                        ]);
                        $ids = $filteredRows->pluck('id')->all();

                        return $q->whereIn('id', count($ids) ? $ids : [0]);
                    })
                    ->indicateUsing(fn (array $data): ?string => ($data['reviewer_id'] ?? null) ? 'One reviewer' : null),

                Filter::make('needs_action_only')
                    ->schema([Toggle::make('needs_action_only')->label('Only accounts needing action')->default(true)])
                    ->default()
                    ->query(function (Builder $q, array $data) use ($service): Builder {
                        $enabled = (bool) ($data['needs_action_only'] ?? true);

                        Session::put('dealership_workload.needs_action_only', $enabled);

                        if (! $enabled) {
                            $filteredRows = $service->accountRows([
                                'needs_action_only' => false,
                                'reviewer_id' => Session::get('dealership_workload.reviewer_id') ? (int) Session::get('dealership_workload.reviewer_id') : null,
                            ]);
                            $ids = $filteredRows->pluck('id')->all();

                            return $q->whereIn('id', count($ids) ? $ids : [0]);
                        }

                        return $q;
                    })
                    ->indicateUsing(fn (array $data): ?string => ($data['needs_action_only'] ?? true) ? null : 'Showing idle accounts'),
            ])
            ->filtersFormWidth('md')
            ->persistFiltersInSession()
            ->defaultSort(fn (Builder $query) => $query, 'asc')
            ->modifyQueryUsing(function (Builder $query) use ($rows): Builder {
                $ordered = $rows->pluck('id')->map(fn ($id): int => (int) $id)->all();

                if (count($ordered) === 0) {
                    return $query;
                }

                $cases = [];

                foreach ($ordered as $position => $id) {
                    $cases[] = 'WHEN '.$id.' THEN '.$position;
                }

                return $query->orderByRaw('CASE id '.implode(' ', $cases).' END');
            });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function findRow(Collection $rows, int $accountId): array
    {
        return $rows->firstWhere('id', $accountId) ?? [];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function currentRows(OperationsWorkloadService $service): Collection
    {
        return $service->accountRows([
            'needs_action_only' => Session::get('dealership_workload.needs_action_only', true),
            'reviewer_id' => $this->activeReviewerId(),
        ]);
    }

    /**
     * Current reviewer-filter value. Returns null when the filter has been
     * cleared so the previous selection cannot keep accounts hidden.
     */
    private function activeReviewerId(): ?int
    {
        $value = Session::get('dealership_workload.reviewer_id');

        return $value ? (int) $value : null;
    }
}
