<?php

namespace App\Actions;

use App\Enums\ApplicationStage;
use App\Enums\DatafixStatus;
use App\Enums\DocumentStatus;
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

        $this->authorize($from, $to, $actor, $isSystem);
        $this->guard($application, $from, $to, $reason);

        return DB::transaction(function () use ($application, $from, $to, $actor, $reason, $isSystem): Application {
            if ($to === ApplicationStage::PaymentPending) {
                $application->fee_snapshot = app(CalculateFees::class)->snapshot($application);
            }

            $application->stage = $to;
            $application->due_at = $to->isTerminal()
                ? null
                : now()->addHours(SystemSetting::current()->slaHoursFor($to->value));

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

            // Dealership (and any other on-account) clients are not blocked on
            // cash: the fee goes onto a running statement and the paperwork
            // keeps moving. Record a synthetic on-account payment and advance
            // straight to PaymentVerified so downstream stages are unchanged.
            if ($to === ApplicationStage::PaymentPending && $application->clientAccount?->isOnAccount()) {
                return $this->settleOnAccount($application);
            }

            return $application;
        });
    }

    private function settleOnAccount(Application $application): Application
    {
        $amountCents = (int) ($application->fee_snapshot['total_cents'] ?? 0);

        $payment = $application->payments()->create([
            'amount_cents' => $amountCents,
            'method' => Payment::METHOD_ACCOUNT_STATEMENT,
            'reference' => 'On statement',
            'on_account' => true,
            'verified_at' => now(),
            'statement_settled_at' => null,
        ]);

        $this->audit->handle(
            null,
            $payment,
            'payment.on_account',
            'Fee added to '.$application->clientAccount->name.' statement.',
            null,
            ['amount_cents' => $amountCents],
            isSystem: true,
        );

        return $this->handle($application, ApplicationStage::PaymentVerified, null, isSystem: true);
    }

    private function authorize(ApplicationStage $from, ApplicationStage $to, ?User $actor, bool $isSystem): void
    {
        if ($isSystem && in_array($to, [
            ApplicationStage::DocumentReview,
            ApplicationStage::PaymentVerified,
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

        if ($actor->hasAnyRole(['super_admin', 'customer_admin'])) {
            return;
        }

        if ($from === ApplicationStage::QuoteSent && $to === ApplicationStage::QuoteAccepted && ! $actor->canAcceptQuotes()) {
            throw new InvalidTransition('This account cannot accept quotes.');
        }

        $allowed = match ($from->value.'>'.$to->value) {
            'draft>submitted', 'changes_requested>document_review', 'quote_sent>cancelled' => ['client_admin', 'client_user'],
            'quote_sent>quote_accepted' => ['client_admin', 'client_user'],
            'payment_pending>payment_verified' => ['finance'],
            default => ['reviewer'],
        };

        if ($to === ApplicationStage::Cancelled && $from->isBeforeAuthority()) {
            $allowed = ['client_admin', 'reviewer'];
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
            // straight to PaymentPending for every request type.
            if (
                FeatureFlags::quotesEnabled()
                && $application->request_type?->needsQuoteByDefault()
                && $from === ApplicationStage::DocumentReview
            ) {
                throw new InvalidTransition('Imports and exports need a quote before payment.');
            }

            $missing = $application->documents()->where('required', true)->where('status', '!=', DocumentStatus::Accepted)->exists();

            if ($missing) {
                throw new InvalidTransition('Every required document must be accepted.');
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
