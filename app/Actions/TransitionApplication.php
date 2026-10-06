<?php

namespace App\Actions;

use App\Enums\ApplicationStage;
use App\Enums\DatafixStatus;
use App\Enums\DocumentStatus;
use App\Enums\QuoteStatus;
use App\Enums\RequestType;
use App\Enums\VehicleCategory;
use App\Exceptions\InvalidTransition;
use App\Models\Application;
use App\Models\Payment;
use App\Models\StageHistory;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\FeatureFlags;
use App\Services\NotificationDispatcher;
use App\Support\RegisterNumber;
use App\Support\Vin;
use Illuminate\Support\Facades\DB;

class TransitionApplication
{
    public function __construct(
        private RecordAudit $audit,
        private NotificationDispatcher $notifications,
    ) {}

    public function handle(Application $application, ApplicationStage $to, ?User $actor, ?string $reason = null, bool $isSystem = false): Application
    {
        $from = $application->stage;

        if (! $from->canTransitionTo($to)) {
            throw new InvalidTransition("Cannot move {$from->value} to {$to->value}.");
        }

        if ($this->isManualBilling($application, $from, $to, $isSystem)) {
            $this->authorizeManualBilling($actor);

            return DB::transaction(fn (): Application => $this->billWithoutPaymentCheck($application));
        }

        $this->authorize($from, $to, $actor, $isSystem);
        $this->guard($application, $from, $to, $reason);

        return DB::transaction(function () use ($application, $from, $to, $actor, $reason, $isSystem): Application {
            if ($to === ApplicationStage::PaymentPending) {
                $application->fee_snapshot = app(CalculateFees::class)->billingSnapshot($application);
            }

            $warningHours = SystemSetting::current()->warningHoursFor($to);

            $application->stage = $to;
            $application->due_at = $warningHours === null ? null : now()->addHours($warningHours);

            if ($to === ApplicationStage::Submitted) {
                $application->submitted_at = now();

                // Record the actual client user who clicked Submit. Reviewers and
                // staff can also move Draft to Submitted on a dealership's behalf;
                // in that case the actor is still the real submitter for audit.
                if ($actor !== null && $application->submitted_by_id === null) {
                    $application->submitted_by_id = $actor->id;
                }
            }

            if ($to === ApplicationStage::Cancelled) {
                $application->cancelled_reason = $reason;
            }

            if ($to === ApplicationStage::AuthorityQuery) {
                $application->authority_query_resolved_at = null;
                $application->authority_query_resolution = null;
            }

            // Stamp the metering timestamp used by the platform billing
            // counter the first time the application reaches Completed.
            // Guarded with isset() so if an app is somehow transitioned
            // back and forth we don't move the goalposts on an already-
            // billed transaction.
            if ($to === ApplicationStage::Completed && $application->completed_at === null) {
                $application->completed_at = now();
            }

            $application->save();

            StageHistory::query()->create([
                'application_id' => $application->id,
                'from_stage' => $from,
                'to_stage' => $to,
                'user_id' => $actor?->id,
                'reason' => $reason,
            ]);

            $this->audit->handle(
                $actor,
                $application,
                'application.stage_changed',
                "Stage {$from->label()} to {$to->label()}.",
                ['stage' => $from->value],
                ['stage' => $to->value, 'reason' => $reason],
                $isSystem,
            );

            if ($to === ApplicationStage::ChangesRequested) {
                $this->notifications->changesRequested($application, $reason);
            } elseif ($to === ApplicationStage::ReadyForCollection) {
                $this->notifications->readyForCollection($application);
            }

            $application = $application->refresh();

            if ($to === ApplicationStage::PaymentPending && $application->billsWithoutPaymentCheck()) {
                return $this->billWithoutPaymentCheck($application);
            }

            return $application;
        });
    }

    /**
     * Records the fee as owed (an unsettled on-account entry) and advances to
     * PaymentVerified so downstream stages are unchanged. The entry stays
     * outstanding until finance marks the application's invoice paid.
     */
    private function billWithoutPaymentCheck(Application $application): Application
    {
        $amountCents = (int) ($application->fee_snapshot['total_cents'] ?? 0);
        $onStatement = (bool) $application->clientAccount?->isOnAccount();

        $payment = $application->payments()->create([
            'amount_cents' => $amountCents,
            'method' => $onStatement ? Payment::METHOD_ACCOUNT_STATEMENT : Payment::METHOD_INVOICE,
            'reference' => $onStatement ? 'On statement' : 'To invoice',
            'on_account' => true,
            'verified_at' => now(),
            'statement_settled_at' => null,
        ]);

        $this->audit->handle(
            null,
            $payment,
            'payment.on_account',
            $onStatement
                ? 'Fee added to '.$application->clientAccount->name.' statement.'
                : 'Fee billed for invoicing; payment tracking is off.',
            null,
            ['amount_cents' => $amountCents],
            isSystem: true,
        );

        return $this->handle($application, ApplicationStage::PaymentVerified, null, isSystem: true);
    }

    /**
     * Applications that reached PaymentPending before payment tracking was
     * switched off (or before the client moved onto a statement) have no
     * payment to verify; staff bill them by hand instead.
     */
    private function isManualBilling(Application $application, ApplicationStage $from, ApplicationStage $to, bool $isSystem): bool
    {
        return ! $isSystem
            && $from === ApplicationStage::PaymentPending
            && $to === ApplicationStage::PaymentVerified
            && $application->billsWithoutPaymentCheck()
            && $application->payments()->doesntExist();
    }

    private function authorizeManualBilling(?User $actor): void
    {
        if ($actor === null || ! $actor->is_active || ! $actor->hasAnyRole(['owner', 'reviewer', 'finance'])) {
            throw new InvalidTransition('You cannot bill this application.');
        }
    }

    private function authorize(ApplicationStage $from, ApplicationStage $to, ?User $actor, bool $isSystem): void
    {
        if ($isSystem && in_array($to, [
            ApplicationStage::DocumentReview,
            ApplicationStage::QuoteAccepted,
            ApplicationStage::PaymentVerified,
            ApplicationStage::Completed,
            ApplicationStage::Archived,
        ], true)) {
            return;
        }

        if ($actor === null) {
            throw new InvalidTransition('An actor is required for this stage change.');
        }

        if (! $actor->is_active) {
            throw new InvalidTransition('This user is inactive.');
        }

        if ($actor->hasAnyRole(['owner'])) {
            return;
        }

        if ($from === ApplicationStage::QuoteSent && $to === ApplicationStage::QuoteAccepted && ! $actor->canAcceptQuotes()) {
            throw new InvalidTransition('This account cannot accept quotes.');
        }

        $allowed = match ($from->value.'>'.$to->value) {
            'draft>submitted', 'changes_requested>document_review', 'quote_sent>cancelled' => ['customer_admin', 'customer_user'],
            'quote_sent>quote_accepted' => ['customer_admin', 'customer_user'],
            'payment_pending>payment_verified' => ['finance'],
            default => ['reviewer'],
        };

        if ($to === ApplicationStage::Cancelled && $from->isBeforeAuthority()) {
            $allowed = ['customer_admin', 'reviewer'];
        }

        if (! $actor->hasAnyRole($allowed)) {
            throw new InvalidTransition('You cannot make this stage change.');
        }
    }

    private function guard(Application $application, ApplicationStage $from, ApplicationStage $to, ?string $reason): void
    {
        if ($to === ApplicationStage::Cancelled && ($reason === null || trim($reason) === '')) {
            throw new InvalidTransition('A cancellation reason is required.');
        }

        if ($from === ApplicationStage::Draft && $to === ApplicationStage::Submitted) {
            $this->guardSubmission($application);
        }

        if ($from === ApplicationStage::DocumentReview && $to === ApplicationStage::ChangesRequested) {
            $rejected = $application->documents()->where('status', DocumentStatus::Rejected)->exists();
            $clientNote = $application->notes()->where('visibility', 'client')->exists();

            if (! $rejected && ! $clientNote) {
                throw new InvalidTransition('Reject a document or add a client-visible note first.');
            }
        }

        if ($from === ApplicationStage::ChangesRequested && $to === ApplicationStage::DocumentReview) {
            $stillRejected = $application->documents()->where('status', DocumentStatus::Rejected)->exists();

            if ($stillRejected) {
                throw new InvalidTransition('Replace every rejected document first.');
            }
        }

        if ($to === ApplicationStage::QuoteSent) {
            $quote = $application->quotes()->where('status', 'draft')->latest('id')->first();

            if ($quote === null || $quote->lines()->doesntExist() || $quote->expires_at === null) {
                throw new InvalidTransition('The quote needs lines and an expiry date.');
            }
        }

        if ($to === ApplicationStage::QuoteAccepted) {
            $quote = $application->quotes()->where('status', 'sent')->latest('id')->first();

            if ($quote === null || ($quote->expires_at !== null && $quote->expires_at->isPast())) {
                throw new InvalidTransition('The quote has expired.');
            }
        }

        if ($to === ApplicationStage::PaymentPending) {
            // Only enforce the quote-first rule when quoting is enabled for
            // this deployment. When quotes are off, DocumentReview advances
            // straight to PaymentPending for every request type. Clients on
            // a standing agreement are already priced, so they skip it too.
            if (
                FeatureFlags::quotesEnabled()
                && $application->request_type?->needsQuoteByDefault()
                && $from === ApplicationStage::DocumentReview
                && ! $application->clientAccount?->has_standing_agreement
            ) {
                throw new InvalidTransition('Imports and exports need a quote before payment.');
            }

            $missing = $application->documents()->where('required', true)->where('status', '!=', DocumentStatus::Accepted)->exists();

            if ($missing) {
                throw new InvalidTransition('Every required document must be accepted.');
            }

            $quoted = $application->quotes()->where('status', QuoteStatus::Accepted)->exists();

            if (! $quoted && app(CalculateFees::class)->versionInEffect($application) === null) {
                throw new InvalidTransition('No fee table is in effect today for '.($application->province?->label() ?? 'this province').'. Approve a current fee table before billing.');
            }

            $unpriced = $quoted ? [] : app(CalculateFees::class)->snapshot($application)['unpriced'];

            if ($unpriced !== []) {
                throw new InvalidTransition('The fees are incomplete: '.$unpriced[0].' Fix the fee table or the vehicle details before billing.');
            }
        }

        if ($from === ApplicationStage::PaymentPending && $to === ApplicationStage::PaymentVerified) {
            $payment = $application->payments()->latest('id')->first();
            $expected = (int) ($application->fee_snapshot['total_cents'] ?? 0);

            if ($payment === null || ($payment->amount_cents !== $expected && blank($payment->override_reason))) {
                throw new InvalidTransition('The payment must match the fee snapshot, or an override reason is required.');
            }
        }

        if ($to === ApplicationStage::DatafixInProgress) {
            $commercial = $application->vehicle_category === VehicleCategory::Commercial;
            $dataChange = $application->request_type === RequestType::DataChange;

            if (! $commercial && ! $dataChange) {
                throw new InvalidTransition('Datafix applies to commercial vehicles and data changes.');
            }

            if ($application->datafix_status !== DatafixStatus::Ready) {
                throw new InvalidTransition('Datafix is not ready.');
            }
        }

        if ($to === ApplicationStage::SubmittedToAuthority && $from === ApplicationStage::PaymentVerified) {
            $needsDatafix = $application->vehicle_category === VehicleCategory::Commercial
                || $application->request_type === RequestType::DataChange;

            if ($needsDatafix && $application->datafix_status !== DatafixStatus::Completed) {
                throw new InvalidTransition('Complete the datafix before sending this vehicle to the authority.');
            }
        }

        if ($from === ApplicationStage::DatafixInProgress && $to === ApplicationStage::SubmittedToAuthority) {
            if ($application->datafix_status !== DatafixStatus::Completed) {
                throw new InvalidTransition('Complete the datafix before sending this vehicle to the authority.');
            }
        }

        if ($to === ApplicationStage::AuthorityQuery && blank($reason)) {
            throw new InvalidTransition('An authority query needs a note.');
        }

        if ($from === ApplicationStage::AuthorityQuery && $to === ApplicationStage::SubmittedToAuthority && $application->authority_query_resolved_at === null) {
            throw new InvalidTransition('Record how the department query was resolved before resubmitting.');
        }

        if ($from === ApplicationStage::Approved && $to === ApplicationStage::ReadyForCollection && $application->authority_returned_at === null) {
            throw new InvalidTransition('Record the physical receipt of the returned documents first.');
        }
    }

    private function guardSubmission(Application $application): void
    {
        $vehicle = $application->vehicle;

        if ($vehicle === null) {
            throw new InvalidTransition('Add the vehicle before submitting.');
        }

        try {
            Vin::from((string) $vehicle->vin);
            RegisterNumber::from((string) $vehicle->vehicle_register_number);
        } catch (\InvalidArgumentException $exception) {
            throw new InvalidTransition($exception->getMessage());
        }

        if ($application->request_type === null || $application->vehicle_category === null || $application->owner_type === null || $application->province === null) {
            throw new InvalidTransition('Request, vehicle category, owner and province are required.');
        }

        if ($application->request_type === RequestType::NewRegistration && $application->service_type === null) {
            throw new InvalidTransition('Choose register only, or register and license.');
        }

        $missing = $application->documents()->where('required', true)->whereIn('status', [
            DocumentStatus::Missing,
            DocumentStatus::Rejected,
        ])->exists();

        if ($missing) {
            throw new InvalidTransition('Upload every required document before submitting.');
        }
    }
}
