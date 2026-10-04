<?php

namespace App\Services;

use App\Enums\ApplicationStage;
use App\Enums\DatafixStatus;
use App\Enums\DocumentStatus;
use App\Enums\RequestType;
use App\Enums\VehicleCategory;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\ClientAccount;
use App\Models\Payment;
use App\Models\Quote;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Single source of truth for the dealership operations workspace.
 *
 * Every count on the dashboard, every per-dealership aggregate, and every
 * row in the Outstanding tasks list comes from this class so counters,
 * dealership totals and filtered lists cannot disagree.
 *
 * Eligibility rules mirror the server-side guards in TransitionApplication
 * so what the dashboard labels "Ready to submit" is exactly what the
 * authority-submit action will accept.
 */
class OperationsWorkloadService
{
    /** @var list<ApplicationStage> */
    private const TERMINAL_STAGES = [
        ApplicationStage::Completed,
        ApplicationStage::Cancelled,
        ApplicationStage::Archived,
    ];

    public const TAB_APPROVALS = 'approvals';

    public const TAB_READY_TO_SUBMIT = 'ready_to_submit';

    public const TAB_WAITING_ON_DEALERSHIP = 'waiting_on_dealership';

    public const TAB_ALL = 'all';

    /**
     * Brief-aligned tab identifiers. These are the primary views the
     * administrator uses. The older identifiers above remain so existing
     * URLs keep working as sub-filters inside the outstanding tab.
     */
    public const TAB_OUTSTANDING = 'outstanding';

    public const TAB_SUBMISSION_PACKS = 'submission_packs';

    public const TAB_AWAITING_RETURN = 'awaiting_return';

    public const TAB_RETURNED_HANDOVER = 'returned_handover';

    public const KIND_DOCUMENT = 'document';

    public const KIND_PAYMENT = 'payment';

    public const KIND_ALL = 'all';

    public const WAITING_DRAFT_OR_CORRECTIONS = 'draft_or_corrections';

    public const WAITING_QUOTE_SENT = 'quote_sent';

    public const WAITING_PAYMENT_PENDING = 'payment_pending';

    public const WAITING_ALL = 'all';

    public const SCOPE_OUTSTANDING = 'outstanding';

    public const SCOPE_ACTIVE = 'active';

    /**
     * Is this application eligible for the authority submission action?
     *
     * Mirrors the guard in TransitionApplication for
     * PaymentVerified -> SubmittedToAuthority,
     * DatafixInProgress -> SubmittedToAuthority, and
     * AuthorityQuery -> SubmittedToAuthority (re-submission after a query).
     */
    public function isReadyForAuthority(Application $application): bool
    {
        $stage = $application->stage;

        $resubmitFromQuery = $stage === ApplicationStage::AuthorityQuery;

        if (! in_array($stage, [
            ApplicationStage::PaymentVerified,
            ApplicationStage::DatafixInProgress,
            ApplicationStage::AuthorityQuery,
        ], true)) {
            return false;
        }

        $missingDoc = $application->documents()
            ->where('required', true)
            ->where('status', '!=', DocumentStatus::Accepted)
            ->exists();

        if ($missingDoc) {
            return false;
        }

        $hasVerifiedPayment = $application->payments()
            ->whereNotNull('verified_at')
            ->exists();

        if (! $hasVerifiedPayment && ! $resubmitFromQuery) {
            return false;
        }

        if ($this->needsDatafix($application) && $application->datafix_status !== DatafixStatus::Completed) {
            return false;
        }

        return true;
    }

    /**
     * Four compact counters for the top of the operations workspace.
     *
     * Each counter returns a URL so the dashboard can route clicks to the
     * matching filtered Outstanding tasks tab. Clicking a count never
     * approves or submits anything on its own.
     *
     * @return list<array{key: string, label: string, count: int, tone: string, url: string, description: string}>
     */
    public function counters(): array
    {
        return [
            [
                'key' => 'outstanding',
                'label' => 'Outstanding tasks',
                'count' => $this->outstandingTaskCount(),
                'tone' => 'warning',
                'url' => $this->tabUrl(self::TAB_OUTSTANDING),
                'description' => 'Documents, department queries and anything else waiting on the licensing company.',
            ],
            [
                'key' => 'submission_packs',
                'label' => 'Ready to prepare/print',
                'count' => $this->readyForAuthorityApplications()->count(),
                'tone' => 'info',
                'url' => $this->tabUrl(self::TAB_SUBMISSION_PACKS),
                'description' => 'All required documents accepted. Prepare a submission pack and print.',
            ],
            [
                'key' => 'awaiting_return',
                'label' => 'Submitted, awaiting return',
                'count' => $this->awaitingReturnApplications()->count(),
                'tone' => 'neutral',
                'url' => $this->tabUrl(self::TAB_AWAITING_RETURN),
                'description' => 'With the licensing department. Track days outstanding and follow up.',
            ],
            [
                'key' => 'returned_handover',
                'label' => 'Returned, awaiting handover',
                'count' => $this->returnedHandoverApplications()->count(),
                'tone' => 'info',
                'url' => $this->tabUrl(self::TAB_RETURNED_HANDOVER),
                'description' => 'Documents or discs returned by the department. Hand over to the customer.',
            ],
        ];
    }

    /**
     * Combined outstanding count for the top counter. Covers every row the
     * outstanding tab lists, so the counter and the destination tab agree.
     */
    private function outstandingTaskCount(): int
    {
        $applicationIds = collect();
        $applicationIds = $applicationIds->merge($this->awaitingDocumentApprovalTasks()->pluck('application_id'));

        if (FeatureFlags::paymentTrackingRequired()) {
            $applicationIds = $applicationIds->merge($this->paymentVerificationTasks()->pluck('application_id'));
        }

        $applicationIds = $applicationIds->merge(
            $this->awaitingReturnApplications()
                ->where(function (Builder $q): void {
                    $q->whereNotNull('due_at')->where('due_at', '<', now());
                })
                ->pluck('id')
        );

        $applicationIds = $applicationIds->merge($this->authorityQueryApplications()->pluck('id'));

        return $applicationIds->unique()->count();
    }

    /**
     * Applications submitted to the licensing department and now awaiting
     * physical return of the documents or disc. Authority queries live
     * alongside these because they are a form of "still with the department".
     */
    public function awaitingReturnApplications(): Builder
    {
        return Application::query()
            ->whereIn('stage', [
                ApplicationStage::SubmittedToAuthority,
                ApplicationStage::AuthorityQuery,
            ]);
    }

    /**
     * Applications where the authority has raised a query. Reviewer must
     * resolve before the submission counts as still "in flight".
     */
    public function authorityQueryApplications(): Builder
    {
        return Application::query()->where('stage', ApplicationStage::AuthorityQuery);
    }

    /**
     * Applications where documents or disc have been returned by the
     * department and are ready to hand over to the customer.
     */
    public function returnedHandoverApplications(): Builder
    {
        return Application::query()->whereIn('stage', [
            ApplicationStage::Approved,
            ApplicationStage::ReadyForCollection,
        ]);
    }

    /**
     * Per-client-account aggregates for the Dealership workload table.
     *
     * Returns one row per client account that has active work, with
     * distinct-application counts (never per-document) and the oldest
     * outstanding task's timestamp.
     *
     * @param  array{search?: ?string, account_id?: ?int, reviewer_id?: ?int, needs_action_only?: bool}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function accountRows(array $filters = []): Collection
    {
        $needsActionOnly = $filters['needs_action_only'] ?? true;

        $accountsQuery = ClientAccount::query()
            ->when($filters['search'] ?? null, fn (Builder $q, string $term) => $q->where('name', 'like', '%'.$term.'%'))
            ->when($filters['account_id'] ?? null, fn (Builder $q, int $id) => $q->where('id', $id));

        $accounts = $accountsQuery->get();

        $activeByAccount = $this->activeApplicationCountsByAccount($filters['reviewer_id'] ?? null);
        $awaitingApprovalByAccount = $this->awaitingDocumentApprovalCountsByAccount($filters['reviewer_id'] ?? null);
        $waitingOnDealershipDocsByAccount = $this->waitingOnDealershipDocsCountsByAccount($filters['reviewer_id'] ?? null);
        $quoteDecisionByAccount = $this->quoteDecisionCountsByAccount($filters['reviewer_id'] ?? null);
        $paymentsOwedByAccount = $this->paymentsOwedCountsByAccount($filters['reviewer_id'] ?? null);
        $paymentVerificationByAccount = $this->paymentVerificationCountsByAccount($filters['reviewer_id'] ?? null);
        $readyToSubmitByAccount = $this->readyToSubmitCountsByAccount($filters['reviewer_id'] ?? null);
        $overdueByAccount = $this->overdueCountsByAccount($filters['reviewer_id'] ?? null);
        $oldestByAccount = $this->oldestOutstandingByAccount($filters['reviewer_id'] ?? null);
        $statementByAccount = $this->statementOutstandingByAccount($filters['reviewer_id'] ?? null);

        $rows = $accounts->map(function (ClientAccount $account) use (
            $activeByAccount,
            $awaitingApprovalByAccount,
            $waitingOnDealershipDocsByAccount,
            $quoteDecisionByAccount,
            $paymentsOwedByAccount,
            $paymentVerificationByAccount,
            $readyToSubmitByAccount,
            $overdueByAccount,
            $oldestByAccount,
            $statementByAccount,
        ): array {
            $id = (int) $account->id;

            return [
                'account' => $account,
                'id' => $id,
                'name' => $account->name,
                'type' => $account->type,
                'billing_mode' => $account->billing_mode,
                'active_applications' => $activeByAccount[$id] ?? 0,
                'awaiting_document_approval' => $awaitingApprovalByAccount[$id] ?? 0,
                'waiting_on_dealership_docs' => $waitingOnDealershipDocsByAccount[$id] ?? 0,
                'quotes_awaiting_dealership' => $quoteDecisionByAccount[$id] ?? 0,
                'payments_owed_by_dealership' => $paymentsOwedByAccount[$id] ?? 0,
                'payments_awaiting_verification' => $paymentVerificationByAccount[$id] ?? 0,
                'ready_for_authority_submission' => $readyToSubmitByAccount[$id] ?? 0,
                'statement_outstanding_cents' => $statementByAccount[$id] ?? 0,
                'overdue' => $overdueByAccount[$id] ?? 0,
                'oldest_outstanding_at' => $oldestByAccount[$id] ?? null,
            ];
        });

        if ($needsActionOnly) {
            $rows = $rows->filter(fn (array $row): bool => (
                ($row['awaiting_document_approval'] ?? 0) > 0
                || ($row['waiting_on_dealership_docs'] ?? 0) > 0
                || ($row['quotes_awaiting_dealership'] ?? 0) > 0
                || ($row['payments_owed_by_dealership'] ?? 0) > 0
                || ($row['payments_awaiting_verification'] ?? 0) > 0
                || ($row['ready_for_authority_submission'] ?? 0) > 0
                || ($row['statement_outstanding_cents'] ?? 0) > 0
                || ($row['overdue'] ?? 0) > 0
            ))->values();
        }

        return $rows->sortBy([
            ['overdue', 'desc'],
            ['oldest_outstanding_at', 'asc'],
        ])->values();
    }

    /**
     * Outstanding tasks for the tabbed list under the dealership workload.
     *
     * One row per task (not per application). Approvals and ready-to-submit
     * are staff tasks. Waiting-on-dealership is a view of blocked work.
     *
     * Sub-filters:
     *  - kind: 'document' | 'payment' | 'all' (approvals tab only)
     *  - stage: 'draft_or_corrections' | 'quote_sent' | 'payment_pending' | 'all'
     *           (waiting-on-dealership tab only)
     *  - scope: 'outstanding' | 'active' (all tab only; 'active' lists every
     *           non-terminal application, not just outstanding work)
     *
     * @param  array{account_id?: ?int, reviewer_id?: ?int, overdue?: ?bool, search?: ?string, kind?: ?string, stage?: ?string, scope?: ?string}  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function tasks(string $tab, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $tasks = match ($tab) {
            self::TAB_APPROVALS => $this->approvalTasks($filters),
            self::TAB_READY_TO_SUBMIT, self::TAB_SUBMISSION_PACKS => $this->submissionPackTasks($filters),
            self::TAB_WAITING_ON_DEALERSHIP => $this->waitingOnDealershipTasksForList($filters),
            self::TAB_OUTSTANDING => $this->outstandingTasks($filters),
            self::TAB_AWAITING_RETURN => $this->awaitingReturnTasks($filters),
            self::TAB_RETURNED_HANDOVER => $this->returnedHandoverTasks($filters),
            default => ($filters['scope'] ?? self::SCOPE_OUTSTANDING) === self::SCOPE_ACTIVE
                ? $this->activeApplicationsTasks($filters)
                : $this->allOutstandingTasks($filters),
        };

        $tasks = $tasks->sortBy([
            ['overdue', 'desc'],
            ['waiting_since', 'asc'],
        ])->values();

        $page = (int) request()->integer('page', 1);
        $perPage = max(1, $perPage);
        $total = $tasks->count();
        $items = $tasks->forPage($page, $perPage)->values();

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'query' => request()->query(),
            ],
        );
    }

    // ------------------------------------------------------------------
    // Primary builders used by every count and the task lists.
    // ------------------------------------------------------------------

    /**
     * Required documents awaiting a reviewer decision, scoped to active applications.
     */
    public function awaitingDocumentApprovalTasks(): Builder
    {
        return ApplicationDocument::query()
            ->where('required', true)
            ->whereIn('status', [DocumentStatus::Uploaded, DocumentStatus::AwaitingReview])
            ->whereHas('application', fn (Builder $q) => $this->scopeActive($q));
    }

    /**
     * Payment rows uploaded but not yet verified, scoped to active applications.
     */
    public function paymentVerificationTasks(): Builder
    {
        return Payment::query()
            ->whereNull('verified_at')
            ->whereHas('application', fn (Builder $q) => $this->scopeActive($q));
    }

    /**
     * Applications that would pass the authority-submission guard right now.
     */
    public function readyForAuthorityApplications(): Builder
    {
        return Application::query()
            ->whereIn('stage', [
                ApplicationStage::PaymentVerified,
                ApplicationStage::DatafixInProgress,
                ApplicationStage::AuthorityQuery,
            ])
            ->whereDoesntHave('documents', function (Builder $q): void {
                $q->where('required', true)
                    ->where('status', '!=', DocumentStatus::Accepted);
            })
            ->where(function (Builder $q): void {
                $q->whereHas('payments', fn (Builder $p) => $p->whereNotNull('verified_at'))
                    ->orWhere('stage', ApplicationStage::AuthorityQuery);
            })
            ->where(function (Builder $q): void {
                $q->where(function (Builder $inner): void {
                    $inner->whereNotIn('vehicle_category', [VehicleCategory::Commercial->value])
                        ->whereNotIn('request_type', [RequestType::DataChange->value]);
                })->orWhere('datafix_status', DatafixStatus::Completed);
            });
    }

    /**
     * Applications where the next action is the dealership's: draft submission,
     * document corrections, quote acceptance, or payment capture.
     *
     * Quote and payment stages are only included when their respective
     * feature flags are on - with quotes disabled, QuoteSent is historical
     * only and never surfaced; with payment tracking off, PaymentPending
     * never surfaces because the fee has been auto-settled on-account or
     * is managed outside Licentra.
     */
    public function waitingOnDealershipApplications(): Builder
    {
        $stages = [ApplicationStage::Draft, ApplicationStage::ChangesRequested];

        if (FeatureFlags::quotesEnabled()) {
            $stages[] = ApplicationStage::QuoteSent;
        }

        if (FeatureFlags::paymentTrackingRequired()) {
            $stages[] = ApplicationStage::PaymentPending;
        }

        return Application::query()
            ->whereIn('stage', $stages)
            ->whereDoesntHave('payments', fn (Builder $q) => $q->whereNotNull('verified_at'));
    }

    /**
     * Applications where the dealership still owes payment.
     *
     * This is explicitly distinct from paymentVerificationTasks() which is
     * about finance verifying an already-uploaded payment. Payment owed is
     * a dealership action: the fee has been snapshotted, no payment row
     * exists yet, and we are waiting for the dealership to transfer.
     */
    public function paymentsOwedByDealershipApplications(): Builder
    {
        return Application::query()
            ->where('stage', ApplicationStage::PaymentPending)
            ->whereDoesntHave('payments');
    }

    /**
     * Quotes still awaiting a dealership decision. Returns an empty
     * query when quoting is disabled for this deployment so counters,
     * per-account aggregates and the Outstanding tasks list stay in sync.
     */
    public function quoteDecisionTasks(): Builder
    {
        $query = Quote::query()
            ->where('status', 'sent')
            ->whereHas('application', fn (Builder $q) => $this->scopeActive($q));

        if (! FeatureFlags::quotesEnabled()) {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    /**
     * Applications past their stage SLA that still need work.
     */
    public function overdueApplications(): Builder
    {
        return Application::query()
            ->whereNotIn('stage', array_map(fn (ApplicationStage $s) => $s->value, self::TERMINAL_STAGES))
            ->whereNotNull('due_at')
            ->where('due_at', '<', now());
    }

    // ------------------------------------------------------------------
    // Combined counter + per-account aggregates.
    // Keep in sync with the primary builders above.
    // ------------------------------------------------------------------

    /**
     * Distinct application count for the "Waiting on dealership" counter.
     * One application never counts twice even if it has both a sent quote
     * and a pending payment, so this agrees with the Outstanding tasks tab.
     */
    public function waitingOnDealershipDistinctCount(): int
    {
        $dealerAppIds = $this->waitingOnDealershipApplications()->pluck('id')->all();
        $quoteAppIds = $this->quoteDecisionTasks()->pluck('application_id')->all();

        return count(array_unique(array_merge($dealerAppIds, $quoteAppIds)));
    }

    /** @return array<int, int> */
    private function activeApplicationCountsByAccount(?int $reviewerId): array
    {
        return $this->scopeActive(Application::query())
            ->when($reviewerId, fn (Builder $q, int $id) => $q->where('assigned_reviewer_id', $id))
            ->selectRaw('client_account_id, COUNT(*) AS c')
            ->groupBy('client_account_id')
            ->pluck('c', 'client_account_id')
            ->map(fn ($v): int => (int) $v)
            ->all();
    }

    /** @return array<int, int> */
    private function awaitingDocumentApprovalCountsByAccount(?int $reviewerId): array
    {
        return Application::query()
            ->whereIn('id', $this->awaitingDocumentApprovalTasks()
                ->when($reviewerId, fn (Builder $q, int $id) => $q->whereHas('application', fn (Builder $a) => $a->where('assigned_reviewer_id', $id)))
                ->pluck('application_id'))
            ->selectRaw('client_account_id, COUNT(DISTINCT id) AS c')
            ->groupBy('client_account_id')
            ->pluck('c', 'client_account_id')
            ->map(fn ($v): int => (int) $v)
            ->all();
    }

    /** @return array<int, int> */
    private function waitingOnDealershipDocsCountsByAccount(?int $reviewerId): array
    {
        return Application::query()
            ->whereIn('stage', [ApplicationStage::Draft, ApplicationStage::ChangesRequested])
            ->when($reviewerId, fn (Builder $q, int $id) => $q->where('assigned_reviewer_id', $id))
            ->selectRaw('client_account_id, COUNT(DISTINCT id) AS c')
            ->groupBy('client_account_id')
            ->pluck('c', 'client_account_id')
            ->map(fn ($v): int => (int) $v)
            ->all();
    }

    /** @return array<int, int> */
    private function quoteDecisionCountsByAccount(?int $reviewerId): array
    {
        $applicationIds = $this->quoteDecisionTasks()
            ->when($reviewerId, fn (Builder $q, int $id) => $q->whereHas('application', fn (Builder $a) => $a->where('assigned_reviewer_id', $id)))
            ->pluck('application_id');

        return Application::query()
            ->whereIn('id', $applicationIds)
            ->selectRaw('client_account_id, COUNT(DISTINCT id) AS c')
            ->groupBy('client_account_id')
            ->pluck('c', 'client_account_id')
            ->map(fn ($v): int => (int) $v)
            ->all();
    }

    /** @return array<int, int> */
    private function paymentVerificationCountsByAccount(?int $reviewerId): array
    {
        $applicationIds = $this->paymentVerificationTasks()
            ->when($reviewerId, fn (Builder $q, int $id) => $q->whereHas('application', fn (Builder $a) => $a->where('assigned_reviewer_id', $id)))
            ->pluck('application_id');

        return Application::query()
            ->whereIn('id', $applicationIds)
            ->selectRaw('client_account_id, COUNT(DISTINCT id) AS c')
            ->groupBy('client_account_id')
            ->pluck('c', 'client_account_id')
            ->map(fn ($v): int => (int) $v)
            ->all();
    }

    /** @return array<int, int> */
    private function paymentsOwedCountsByAccount(?int $reviewerId): array
    {
        return $this->paymentsOwedByDealershipApplications()
            ->when($reviewerId, fn (Builder $q, int $id) => $q->where('assigned_reviewer_id', $id))
            ->selectRaw('client_account_id, COUNT(DISTINCT id) AS c')
            ->groupBy('client_account_id')
            ->pluck('c', 'client_account_id')
            ->map(fn ($v): int => (int) $v)
            ->all();
    }

    /**
     * Sum (in cents) of unsettled on-account payments per client account.
     * These are statement balances for dealerships on account billing - they
     * never sit in PaymentPending because the fee is auto-moved onto the
     * statement when the application leaves document review.
     *
     * @return array<int, int>
     */
    public function statementOutstandingByAccount(?int $reviewerId = null): array
    {
        return Payment::query()
            ->outstandingOnStatement()
            ->join('applications', 'applications.id', '=', 'payments.application_id')
            ->when($reviewerId, fn (Builder $q, int $id) => $q->where('applications.assigned_reviewer_id', $id))
            ->selectRaw('applications.client_account_id AS cid, SUM(payments.amount_cents) AS total')
            ->groupBy('applications.client_account_id')
            ->pluck('total', 'cid')
            ->map(fn ($v): int => (int) $v)
            ->all();
    }

    /** @return array<int, int> */
    private function readyToSubmitCountsByAccount(?int $reviewerId): array
    {
        return $this->readyForAuthorityApplications()
            ->when($reviewerId, fn (Builder $q, int $id) => $q->where('assigned_reviewer_id', $id))
            ->selectRaw('client_account_id, COUNT(DISTINCT id) AS c')
            ->groupBy('client_account_id')
            ->pluck('c', 'client_account_id')
            ->map(fn ($v): int => (int) $v)
            ->all();
    }

    /** @return array<int, int> */
    private function overdueCountsByAccount(?int $reviewerId): array
    {
        return $this->overdueApplications()
            ->when($reviewerId, fn (Builder $q, int $id) => $q->where('assigned_reviewer_id', $id))
            ->selectRaw('client_account_id, COUNT(DISTINCT id) AS c')
            ->groupBy('client_account_id')
            ->pluck('c', 'client_account_id')
            ->map(fn ($v): int => (int) $v)
            ->all();
    }

    /** @return array<int, Carbon> */
    private function oldestOutstandingByAccount(?int $reviewerId): array
    {
        $rows = $this->scopeActive(Application::query())
            ->when($reviewerId, fn (Builder $q, int $id) => $q->where('assigned_reviewer_id', $id))
            ->selectRaw('client_account_id, MIN(updated_at) AS oldest')
            ->groupBy('client_account_id')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->client_account_id] = $row->oldest ? Carbon::parse($row->oldest) : null;
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Task row materialisation (one row per task, not per application).
    // ------------------------------------------------------------------

    /**
     * @param  array{account_id?: ?int, reviewer_id?: ?int, overdue?: ?bool, search?: ?string, kind?: ?string}  $filters
     */
    private function approvalTasks(array $filters): Collection
    {
        $kind = $filters['kind'] ?? self::KIND_ALL;
        $rows = collect();

        if (in_array($kind, [self::KIND_ALL, self::KIND_DOCUMENT], true)) {
            $documents = $this->awaitingDocumentApprovalTasks()
                ->with(['application:id,reference,client_account_id,assigned_reviewer_id,submitted_by_id,province,stage,due_at,updated_at',
                    'application.clientAccount:id,name,type',
                    'application.reviewer:id,name',
                    'application.submittedBy:id,name',
                    'application.vehicle:id,application_id,vehicle_register_number,vin',
                    'documentType:id,code,name'])
                ->get();

            foreach ($documents as $document) {
                if (! $this->matchesFilters($document->application, $filters)) {
                    continue;
                }

                $rows->push($this->buildDocumentReviewRow($document));
            }
        }

        if (
            in_array($kind, [self::KIND_ALL, self::KIND_PAYMENT], true)
            && FeatureFlags::paymentTrackingRequired()
        ) {
            $payments = $this->paymentVerificationTasks()
                ->with(['application:id,reference,client_account_id,assigned_reviewer_id,submitted_by_id,province,stage,due_at,updated_at',
                    'application.clientAccount:id,name,type',
                    'application.reviewer:id,name',
                    'application.submittedBy:id,name',
                    'application.vehicle:id,application_id,vehicle_register_number,vin'])
                ->get();

            foreach ($payments as $payment) {
                if (! $this->matchesFilters($payment->application, $filters)) {
                    continue;
                }

                $rows->push($this->buildPaymentVerificationRow($payment));
            }
        }

        return $rows;
    }

    /** @param  array{account_id?: ?int, reviewer_id?: ?int, overdue?: ?bool, search?: ?string, submitted_by_id?: ?int, province?: ?string}  $filters */
    private function readyToSubmitTasks(array $filters): Collection
    {
        $applications = $this->readyForAuthorityApplications()
            ->with(['clientAccount:id,name,type', 'reviewer:id,name', 'submittedBy:id,name', 'vehicle:id,application_id,vehicle_register_number,vin'])
            ->get();

        $rows = collect();

        foreach ($applications as $application) {
            if (! $this->matchesFilters($application, $filters)) {
                continue;
            }

            $rows->push($this->buildReadyToSubmitRow($application));
        }

        return $rows;
    }

    /** @param  array{account_id?: ?int, reviewer_id?: ?int, overdue?: ?bool, search?: ?string, submitted_by_id?: ?int, province?: ?string, stage?: ?string}  $filters */
    private function waitingOnDealershipTasksForList(array $filters): Collection
    {
        $stages = $this->waitingStagesForSubFilter($filters['stage'] ?? self::WAITING_ALL);

        if ($stages === []) {
            return collect();
        }

        $applications = Application::query()
            ->whereIn('stage', $stages)
            ->with(['clientAccount:id,name,type', 'reviewer:id,name', 'submittedBy:id,name', 'vehicle:id,application_id,vehicle_register_number,vin'])
            ->get();

        $rows = collect();

        foreach ($applications as $application) {
            if (! $this->matchesFilters($application, $filters)) {
                continue;
            }

            $rows->push($this->buildWaitingOnDealershipRow($application));
        }

        return $rows;
    }

    /**
     * Translate the ?stage=... query param into the matching application stages.
     *
     * When quoting or payment tracking is disabled, those stages are not
     * returned - so an operator cannot filter into an empty-by-design list.
     *
     * @return list<ApplicationStage>
     */
    private function waitingStagesForSubFilter(string $subFilter): array
    {
        $quoteStages = FeatureFlags::quotesEnabled() ? [ApplicationStage::QuoteSent] : [];
        $paymentStages = FeatureFlags::paymentTrackingRequired() ? [ApplicationStage::PaymentPending] : [];

        return match ($subFilter) {
            self::WAITING_DRAFT_OR_CORRECTIONS => [ApplicationStage::Draft, ApplicationStage::ChangesRequested],
            self::WAITING_QUOTE_SENT => $quoteStages,
            self::WAITING_PAYMENT_PENDING => $paymentStages,
            default => array_values(array_merge(
                [ApplicationStage::Draft, ApplicationStage::ChangesRequested],
                $quoteStages,
                $paymentStages,
            )),
        };
    }

    /** @param  array{account_id?: ?int, reviewer_id?: ?int, overdue?: ?bool, search?: ?string}  $filters */
    private function allOutstandingTasks(array $filters): Collection
    {
        return $this->approvalTasks($filters)
            ->concat($this->readyToSubmitTasks($filters))
            ->concat($this->waitingOnDealershipTasksForList($filters));
    }

    /**
     * Everything that currently needs the licensing company's attention:
     * documents to review, payments to verify (when tracked), authority
     * queries to resolve, and submitted-but-overdue applications.
     *
     * @param  array{account_id?: ?int, reviewer_id?: ?int, overdue?: ?bool, search?: ?string, submitted_by_id?: ?int, province?: ?string}  $filters
     */
    private function outstandingTasks(array $filters): Collection
    {
        $rows = $this->approvalTasks(array_merge($filters, ['kind' => self::KIND_ALL]));

        foreach ($this->authorityQueryApplications()
            ->with(['clientAccount:id,name,type', 'reviewer:id,name', 'submittedBy:id,name', 'vehicle:id,application_id,vehicle_register_number,vin'])
            ->get() as $application) {
            if (! $this->matchesFilters($application, $filters)) {
                continue;
            }

            $rows->push($this->buildAuthorityQueryRow($application));
        }

        // Overdue awaiting-return rows surface in the outstanding tab too
        // because an overdue return is a licensing-company follow-up task.
        foreach ($this->awaitingReturnApplications()
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->with(['clientAccount:id,name,type', 'reviewer:id,name', 'submittedBy:id,name', 'vehicle:id,application_id,vehicle_register_number,vin'])
            ->get() as $application) {
            if (! $this->matchesFilters($application, $filters)) {
                continue;
            }

            $rows->push($this->buildFollowUpReturnRow($application));
        }

        return $rows;
    }

    /** @param  array{account_id?: ?int, reviewer_id?: ?int, overdue?: ?bool, search?: ?string, submitted_by_id?: ?int, province?: ?string}  $filters */
    private function submissionPackTasks(array $filters): Collection
    {
        $applications = $this->readyForAuthorityApplications()
            ->with(['clientAccount:id,name,type', 'reviewer:id,name', 'submittedBy:id,name', 'vehicle:id,application_id,vehicle_register_number,vin'])
            ->get();

        $rows = collect();

        foreach ($applications as $application) {
            if (! $this->matchesFilters($application, $filters)) {
                continue;
            }

            $rows->push($this->buildSubmissionPackRow($application));
        }

        return $rows;
    }

    /** @param  array{account_id?: ?int, reviewer_id?: ?int, overdue?: ?bool, search?: ?string, submitted_by_id?: ?int, province?: ?string}  $filters */
    private function awaitingReturnTasks(array $filters): Collection
    {
        $applications = $this->awaitingReturnApplications()
            ->with(['clientAccount:id,name,type', 'reviewer:id,name', 'submittedBy:id,name', 'vehicle:id,application_id,vehicle_register_number,vin'])
            ->get();

        $rows = collect();

        foreach ($applications as $application) {
            if (! $this->matchesFilters($application, $filters)) {
                continue;
            }

            $rows->push(
                $application->stage === ApplicationStage::AuthorityQuery
                    ? $this->buildAuthorityQueryRow($application)
                    : $this->buildFollowUpReturnRow($application),
            );
        }

        return $rows;
    }

    /** @param  array{account_id?: ?int, reviewer_id?: ?int, overdue?: ?bool, search?: ?string, submitted_by_id?: ?int, province?: ?string}  $filters */
    private function returnedHandoverTasks(array $filters): Collection
    {
        $applications = $this->returnedHandoverApplications()
            ->with(['clientAccount:id,name,type', 'reviewer:id,name', 'submittedBy:id,name', 'vehicle:id,application_id,vehicle_register_number,vin'])
            ->get();

        $rows = collect();

        foreach ($applications as $application) {
            if (! $this->matchesFilters($application, $filters)) {
                continue;
            }

            $rows->push(
                $application->stage === ApplicationStage::Approved
                    ? $this->buildRecordReturnRow($application)
                    : $this->buildArrangeHandoverRow($application),
            );
        }

        return $rows;
    }

    /**
     * Flat list of every active application for the "Active" dashboard column.
     * Includes applications that have no outstanding dealership or staff task
     * (e.g. SubmittedToAuthority, Approved, ReadyForCollection) so a dealership's
     * total active workload is visible from the drill-down.
     *
     * @param  array{account_id?: ?int, reviewer_id?: ?int, overdue?: ?bool, search?: ?string}  $filters
     */
    private function activeApplicationsTasks(array $filters): Collection
    {
        $applications = $this->scopeActive(Application::query())
            ->with(['clientAccount:id,name,type', 'reviewer:id,name', 'submittedBy:id,name', 'vehicle:id,application_id,vehicle_register_number,vin'])
            ->get();

        $rows = collect();

        foreach ($applications as $application) {
            if (! $this->matchesFilters($application, $filters)) {
                continue;
            }

            $rows->push($this->buildActiveApplicationRow($application));
        }

        return $rows;
    }

    // ------------------------------------------------------------------
    // Row builders.
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function buildDocumentReviewRow(ApplicationDocument $document): array
    {
        $application = $document->application;
        $hadPrior = $document->versions()->count() > 1;
        $label = $hadPrior
            ? 'Review replacement '.strtolower($document->documentType?->name ?? 'document')
            : 'Review '.strtolower($document->documentType?->name ?? 'document');

        return [
            'kind' => 'approval.document',
            'task_key' => 'document:'.$document->id,
            'label' => $label,
            'blocker' => null,
            'application' => $application,
            'account' => $application->clientAccount,
            'reviewer' => $application->reviewer,
            'submitted_by' => $application->submittedBy,
            'vehicle_registration' => $application->vehicle?->vehicle_register_number,
            'vehicle_vin' => $application->vehicle?->vin,
            'waiting_since' => $document->updated_at ?? $document->created_at,
            'due_at' => $application->due_at,
            'overdue' => $this->isOverdue($application),
            'action_label' => 'Open document review',
            'action_url' => route('review.show', ['application' => $application->id]),
        ];
    }

    /** @return array<string, mixed> */
    private function buildPaymentVerificationRow(Payment $payment): array
    {
        $application = $payment->application;

        return [
            'kind' => 'approval.payment',
            'task_key' => 'payment:'.$payment->id,
            'label' => 'Verify payment',
            'blocker' => $payment->reference ? 'Reference '.$payment->reference : null,
            'application' => $application,
            'account' => $application->clientAccount,
            'reviewer' => $application->reviewer,
            'submitted_by' => $application->submittedBy,
            'vehicle_registration' => $application->vehicle?->vehicle_register_number,
            'vehicle_vin' => $application->vehicle?->vin,
            'waiting_since' => $payment->created_at,
            'due_at' => $application->due_at,
            'overdue' => $this->isOverdue($application),
            'action_label' => 'Open payment',
            'action_url' => route('finance.payments', ['application' => $application->id]),
        ];
    }

    /** @return array<string, mixed> */
    private function buildActiveApplicationRow(Application $application): array
    {
        return [
            'kind' => 'active',
            'task_key' => 'active:'.$application->id,
            'label' => $application->stage?->label() ?? '-',
            'blocker' => null,
            'application' => $application,
            'account' => $application->clientAccount,
            'reviewer' => $application->reviewer,
            'submitted_by' => $application->submittedBy,
            'vehicle_registration' => $application->vehicle?->vehicle_register_number,
            'vehicle_vin' => $application->vehicle?->vin,
            'waiting_since' => $application->updated_at,
            'due_at' => $application->due_at,
            'overdue' => $this->isOverdue($application),
            'action_label' => 'Open application',
            'action_url' => route('review.show', ['application' => $application->id]),
        ];
    }

    /** @return array<string, mixed> */
    private function buildReadyToSubmitRow(Application $application): array
    {
        return [
            'kind' => 'ready_to_submit',
            'task_key' => 'ready:'.$application->id,
            'label' => 'Submit approved application to authority',
            'blocker' => null,
            'application' => $application,
            'account' => $application->clientAccount,
            'reviewer' => $application->reviewer,
            'submitted_by' => $application->submittedBy,
            'vehicle_registration' => $application->vehicle?->vehicle_register_number,
            'vehicle_vin' => $application->vehicle?->vin,
            'waiting_since' => $application->updated_at,
            'due_at' => $application->due_at,
            'overdue' => $this->isOverdue($application),
            'action_label' => 'Submit to authority',
            'action_url' => route('filament.admin.pages.outstanding-tasks', [
                'tab' => self::TAB_READY_TO_SUBMIT,
                'submit' => $application->id,
            ]),
        ];
    }

    /**
     * "Prepare submission pack" row. In chunk 1 the pack preview is not
     * built yet, so the action opens the review workspace. Chunk 2 swaps
     * this URL to the pack preview page.
     *
     * @return array<string, mixed>
     */
    private function buildSubmissionPackRow(Application $application): array
    {
        return [
            'kind' => 'prepare_pack',
            'task_key' => 'pack:'.$application->id,
            'label' => 'Prepare submission pack',
            'blocker' => 'All required documents accepted; ready to assemble.',
            'application' => $application,
            'account' => $application->clientAccount,
            'reviewer' => $application->reviewer,
            'submitted_by' => $application->submittedBy,
            'vehicle_registration' => $application->vehicle?->vehicle_register_number,
            'vehicle_vin' => $application->vehicle?->vin,
            'waiting_since' => $application->updated_at,
            'due_at' => $application->due_at,
            'overdue' => $this->isOverdue($application),
            'action_label' => 'Open application',
            'action_url' => route('review.show', ['application' => $application->id]),
        ];
    }

    /**
     * "Follow up on an outstanding return" row for apps at the department.
     *
     * @return array<string, mixed>
     */
    private function buildFollowUpReturnRow(Application $application): array
    {
        return [
            'kind' => 'follow_up_return',
            'task_key' => 'followup:'.$application->id,
            'label' => 'Follow up on outstanding return',
            'blocker' => $application->authority_submitted_at
                ? 'Submitted '.$application->authority_submitted_at->diffForHumans()
                : 'With the licensing department.',
            'application' => $application,
            'account' => $application->clientAccount,
            'reviewer' => $application->reviewer,
            'submitted_by' => $application->submittedBy,
            'vehicle_registration' => $application->vehicle?->vehicle_register_number,
            'vehicle_vin' => $application->vehicle?->vin,
            'waiting_since' => $application->authority_submitted_at ?? $application->updated_at,
            'due_at' => $application->due_at,
            'overdue' => $this->isOverdue($application),
            'action_label' => 'Open application',
            'action_url' => route('review.show', ['application' => $application->id]),
        ];
    }

    /**
     * "Resolve a department query" row.
     *
     * @return array<string, mixed>
     */
    private function buildAuthorityQueryRow(Application $application): array
    {
        return [
            'kind' => 'resolve_query',
            'task_key' => 'query:'.$application->id,
            'label' => 'Resolve a department query',
            'blocker' => 'Authority raised a query. Resolve and resubmit.',
            'application' => $application,
            'account' => $application->clientAccount,
            'reviewer' => $application->reviewer,
            'submitted_by' => $application->submittedBy,
            'vehicle_registration' => $application->vehicle?->vehicle_register_number,
            'vehicle_vin' => $application->vehicle?->vin,
            'waiting_since' => $application->updated_at,
            'due_at' => $application->due_at,
            'overdue' => $this->isOverdue($application),
            'action_label' => 'Open application',
            'action_url' => route('review.show', ['application' => $application->id]),
        ];
    }

    /**
     * "Record returned documents/disc" row for apps the department has approved.
     *
     * @return array<string, mixed>
     */
    private function buildRecordReturnRow(Application $application): array
    {
        return [
            'kind' => 'record_return',
            'task_key' => 'return:'.$application->id,
            'label' => 'Record returned documents/disc',
            'blocker' => 'Authority approved. Capture physical receipt of documents.',
            'application' => $application,
            'account' => $application->clientAccount,
            'reviewer' => $application->reviewer,
            'submitted_by' => $application->submittedBy,
            'vehicle_registration' => $application->vehicle?->vehicle_register_number,
            'vehicle_vin' => $application->vehicle?->vin,
            'waiting_since' => $application->updated_at,
            'due_at' => $application->due_at,
            'overdue' => $this->isOverdue($application),
            'action_label' => 'Open application',
            'action_url' => route('review.show', ['application' => $application->id]),
        ];
    }

    /**
     * "Arrange customer handover" row for apps where return has been recorded.
     *
     * @return array<string, mixed>
     */
    private function buildArrangeHandoverRow(Application $application): array
    {
        return [
            'kind' => 'arrange_handover',
            'task_key' => 'handover:'.$application->id,
            'label' => 'Arrange customer handover',
            'blocker' => 'Documents ready. Hand over to the customer.',
            'application' => $application,
            'account' => $application->clientAccount,
            'reviewer' => $application->reviewer,
            'submitted_by' => $application->submittedBy,
            'vehicle_registration' => $application->vehicle?->vehicle_register_number,
            'vehicle_vin' => $application->vehicle?->vin,
            'waiting_since' => $application->updated_at,
            'due_at' => $application->due_at,
            'overdue' => $this->isOverdue($application),
            'action_label' => 'Open application',
            'action_url' => route('review.show', ['application' => $application->id]),
        ];
    }

    /** @return array<string, mixed> */
    private function buildWaitingOnDealershipRow(Application $application): array
    {
        [$label, $blocker] = $this->waitingOnDealershipLabel($application);

        return [
            'kind' => 'waiting_on_dealership',
            'task_key' => 'wod:'.$application->id,
            'label' => $label,
            'blocker' => $blocker,
            'application' => $application,
            'account' => $application->clientAccount,
            'reviewer' => $application->reviewer,
            'submitted_by' => $application->submittedBy,
            'vehicle_registration' => $application->vehicle?->vehicle_register_number,
            'vehicle_vin' => $application->vehicle?->vin,
            'waiting_since' => $application->updated_at,
            'due_at' => $application->due_at,
            'overdue' => $this->isOverdue($application),
            'action_label' => 'Open application',
            'action_url' => route('review.show', ['application' => $application->id]),
        ];
    }

    /** @return array{0: string, 1: ?string} */
    private function waitingOnDealershipLabel(Application $application): array
    {
        return match ($application->stage) {
            ApplicationStage::Draft => ['Await customer submission', 'Draft not yet submitted'],
            ApplicationStage::ChangesRequested => ['Request missing documents or corrections', 'Reviewer requested changes'],
            ApplicationStage::QuoteSent => ['Await customer quote acceptance', 'Quote sent, no decision yet'],
            ApplicationStage::PaymentPending => ['Await customer payment', 'Fee snapshot taken, awaiting payment'],
            default => ['Await customer action', null],
        };
    }

    // ------------------------------------------------------------------
    // Dealership card board. One card per active application for the
    // selected dealership. Field staff work by registration number or VIN
    // so each card leads with those; the next action is derived from the
    // application stage and gated against the viewing user's role.
    // ------------------------------------------------------------------

    /**
     * @param  array{search?: ?string, stage?: ?string, assigned_to?: ?int, overdue?: ?bool}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function applicationCards(ClientAccount $account, array $filters = []): Collection
    {
        $query = $this->scopeActive(Application::query())
            ->where('client_account_id', $account->id)
            ->with([
                'reviewer:id,name',
                'vehicle:id,application_id,vehicle_register_number,vin',
                'documents' => fn ($q) => $q->select(['id', 'application_id', 'required', 'status']),
                'payments' => fn ($q) => $q->select(['id', 'application_id', 'verified_at', 'on_account', 'statement_settled_at']),
            ]);

        if (! empty($filters['assigned_to'])) {
            $query->where('assigned_reviewer_id', (int) $filters['assigned_to']);
        }

        if (! empty($filters['overdue'])) {
            $query->whereNotNull('due_at')->where('due_at', '<', now());
        }

        if (! empty($filters['stage'])) {
            $stage = ApplicationStage::tryFrom((string) $filters['stage']);

            if ($stage !== null && ! $stage->isTerminal()) {
                $query->where('stage', $stage);
            }
        }

        $applications = $query->get();

        $rows = $applications->map(fn (Application $application): array => $this->buildApplicationCard($application));

        if (! empty($filters['search'])) {
            $needle = strtolower((string) $filters['search']);
            $rows = $rows->filter(function (array $card) use ($needle): bool {
                $haystack = strtolower(implode(' ', array_filter([
                    $card['application']->reference,
                    $card['vehicle_registration'],
                    $card['vehicle_vin'],
                ])));

                return $needle === '' || str_contains($haystack, $needle);
            })->values();
        }

        return $rows->sortBy([
            ['overdue', 'desc'],
            ['waiting_since', 'asc'],
        ])->values();
    }

    /**
     * One card row: rego/VIN hero, stage tone, document/payment progress,
     * and the single "next action" with its destination URL. The view
     * decides whether to actually render the button based on the viewing
     * user's role.
     *
     * @return array<string, mixed>
     */
    private function buildApplicationCard(Application $application): array
    {
        $requiredDocs = $application->documents->where('required', true);
        $acceptedDocs = $requiredDocs->where('status', DocumentStatus::Accepted)->count();
        $rejectedDocs = $requiredDocs->where('status', DocumentStatus::Rejected)->count();
        $pendingDocs = $requiredDocs->whereIn('status', [DocumentStatus::Uploaded, DocumentStatus::AwaitingReview])->count();

        $hasVerifiedPayment = $application->payments->whereNotNull('verified_at')->isNotEmpty();
        $hasPendingPayment = $application->payments->whereNull('verified_at')->isNotEmpty();
        $paymentOwed = $application->stage === ApplicationStage::PaymentPending && ! $hasPendingPayment && ! $hasVerifiedPayment;
        $onStatement = $application->payments
            ->filter(fn ($p) => (bool) $p->on_account && $p->statement_settled_at === null)
            ->isNotEmpty();

        [$actionKey, $actionLabel, $actionUrl, $blocker] = $this->nextActionFor($application, $pendingDocs, $hasPendingPayment, $paymentOwed);

        return [
            'application' => $application,
            'account' => $application->clientAccount,
            'reviewer' => $application->reviewer,
            'vehicle_registration' => $application->vehicle?->vehicle_register_number,
            'vehicle_vin' => $application->vehicle?->vin,
            'stage' => $application->stage,
            'stage_label' => $application->stage?->label() ?? '-',
            'stage_tone' => $application->stage?->tone() ?? 'neutral',
            'request_type' => $application->request_type,
            'request_type_label' => $application->request_type?->label() ?? '-',
            'waiting_since' => $application->updated_at,
            'due_at' => $application->due_at,
            'overdue' => $this->isOverdue($application),
            'documents_required' => $requiredDocs->count(),
            'documents_accepted' => $acceptedDocs,
            'documents_pending' => $pendingDocs,
            'documents_rejected' => $rejectedDocs,
            'payment_owed' => $paymentOwed,
            'payment_awaiting_verification' => $hasPendingPayment,
            'payment_verified' => $hasVerifiedPayment,
            'payment_on_statement' => $onStatement,
            'is_ready_for_authority' => $this->isReadyForAuthority($application),
            'next_action_key' => $actionKey,
            'next_action_label' => $actionLabel,
            'next_action_url' => $actionUrl,
            'blocker' => $blocker,
        ];
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string, 3: ?string}
     */
    private function nextActionFor(
        Application $application,
        int $pendingDocs,
        bool $hasPendingPayment,
        bool $paymentOwed,
    ): array {
        $openApp = route('review.show', ['application' => $application->id]);

        return match (true) {
            $pendingDocs > 0 => ['review_docs', 'Review documents', $openApp, "{$pendingDocs} document(s) waiting"],
            $hasPendingPayment => ['verify_payment', 'Verify payment', route('finance.payments', ['application' => $application->id]), 'Payment uploaded, needs verification'],
            $paymentOwed => ['open_app', 'Chase payment', $openApp, 'Dealership owes payment'],
            $application->stage === ApplicationStage::QuoteSent => ['open_app', 'Chase quote decision', $openApp, 'Quote sent, no decision yet'],
            $application->stage === ApplicationStage::QuoteRequired => ['open_quote', 'Build quote', route('applications.quote', ['application' => $application->id]), 'Quote required'],
            $application->stage === ApplicationStage::ChangesRequested => ['open_app', 'Chase corrections', $openApp, 'Changes requested from dealership'],
            $application->stage === ApplicationStage::Draft => ['open_app', 'Open draft', $openApp, 'Draft not yet submitted'],
            $this->isReadyForAuthority($application) => ['submit_authority', 'Submit to authority', route('filament.admin.pages.outstanding-tasks', ['tab' => self::TAB_READY_TO_SUBMIT, 'submit' => $application->id]), 'Ready for authority submission'],
            default => ['open_app', 'Open application', $openApp, null],
        };
    }

    /**
     * URL for the dealership card board of a given account.
     */
    public function cardsUrl(ClientAccount $account): string
    {
        try {
            return route('filament.admin.pages.dealership-cards', ['account_id' => $account->id]);
        } catch (\Throwable) {
            return '#';
        }
    }

    // ------------------------------------------------------------------
    // Shared helpers.
    // ------------------------------------------------------------------

    public function tabUrl(string $tab, array $extra = []): string
    {
        try {
            return route('filament.admin.pages.outstanding-tasks', array_merge(['tab' => $tab], $extra));
        } catch (\Throwable) {
            return '#';
        }
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('stage', array_map(fn (ApplicationStage $s) => $s->value, self::TERMINAL_STAGES));
    }

    public function needsDatafix(Application $application): bool
    {
        return $application->vehicle_category === VehicleCategory::Commercial
            || $application->request_type === RequestType::DataChange;
    }

    private function isOverdue(Application $application): bool
    {
        return $application->due_at !== null
            && ! in_array($application->stage, self::TERMINAL_STAGES, true)
            && $application->due_at->isPast();
    }

    /** @param  array{account_id?: ?int, reviewer_id?: ?int, overdue?: ?bool, search?: ?string, submitted_by_id?: ?int, province?: ?string}  $filters */
    private function matchesFilters(?Application $application, array $filters): bool
    {
        if ($application === null) {
            return false;
        }

        if (! empty($filters['account_id']) && (int) $application->client_account_id !== (int) $filters['account_id']) {
            return false;
        }

        if (! empty($filters['reviewer_id']) && (int) $application->assigned_reviewer_id !== (int) $filters['reviewer_id']) {
            return false;
        }

        if (! empty($filters['submitted_by_id']) && (int) $application->submitted_by_id !== (int) $filters['submitted_by_id']) {
            return false;
        }

        if (! empty($filters['province']) && (string) ($application->province?->value) !== (string) $filters['province']) {
            return false;
        }

        if (! empty($filters['overdue']) && ! $this->isOverdue($application)) {
            return false;
        }

        if (! empty($filters['search'])) {
            $needle = strtolower((string) $filters['search']);
            $haystack = strtolower(implode(' ', array_filter([
                $application->reference,
                $application->clientAccount?->name,
                $application->submittedBy?->name,
                $application->vehicle?->vehicle_register_number,
                $application->vehicle?->vin,
            ])));

            if ($needle !== '' && ! str_contains($haystack, $needle)) {
                return false;
            }
        }

        return true;
    }
}
