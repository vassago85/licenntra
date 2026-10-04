<?php

namespace App\Notifications;

use App\Models\Application;
use App\Models\BrandingSetting;
use App\Models\Payment;
use App\Notifications\Concerns\BrandedMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentVerifiedForDealer extends Notification implements ShouldQueue
{
    use BrandedMailMessage;
    use Queueable;

    public function __construct(
        public Application $application,
        public Payment $payment,
        public BrandingSetting $branding,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $amount = 'R'.number_format($this->payment->amount_cents / 100, 2, '.', ' ');

        return $this->newBrandedMailMessage($this->branding)
            ->subject($this->brandedSubject($this->branding, 'Payment verified', $this->application->reference))
            ->line('Finance verified a payment of '.$amount.' on '.$this->application->reference.'.')
            ->line('Reference: '.($this->payment->reference ?: 'not supplied'))
            ->line('Your application now moves to the next step.')
            ->action('View application', $this->portalUrl('/applications/'.$this->application->id));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'application_reference' => $this->application->reference,
            'payment_id' => $this->payment->id,
            'amount_cents' => $this->payment->amount_cents,
            'event' => 'payment.verified',
        ];
    }
}
