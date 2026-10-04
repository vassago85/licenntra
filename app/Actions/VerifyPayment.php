<?php

namespace App\Actions;

use App\Enums\ApplicationStage;
use App\Models\Application;
use App\Models\Payment;
use App\Models\User;
use App\Services\NotificationDispatcher;
use Illuminate\Support\Facades\DB;

class VerifyPayment
{
    public function __construct(
        private RecordAudit $audit,
        private NotificationDispatcher $notifications,
    ) {}

    public function handle(Application $application, User $actor, int $amountCents, string $method, string $reference, ?string $overrideReason = null): Payment
    {
        return DB::transaction(function () use ($application, $actor, $amountCents, $method, $reference, $overrideReason): Payment {
            $payment = $application->payments()->create([
                'amount_cents' => $amountCents,
                'method' => $method,
                'reference' => $reference,
                'override_reason' => $overrideReason,
                'verified_by' => $actor->id,
                'verified_at' => now(),
            ]);

            app(TransitionApplication::class)->handle($application, ApplicationStage::PaymentVerified, $actor);

            $this->audit->handle($actor, $payment, 'payment.verified', 'Payment verified.', null, [
                'amount_cents' => $amountCents,
                'reference' => $reference,
            ]);

            $this->notifications->paymentVerified($application->refresh(), $payment);

            return $payment->refresh();
        });
    }
}
