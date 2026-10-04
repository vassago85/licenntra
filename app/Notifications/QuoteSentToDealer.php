<?php

namespace App\Notifications;

use App\Models\Application;
use App\Models\BrandingSetting;
use App\Models\Quote;
use App\Notifications\Concerns\BrandedMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class QuoteSentToDealer extends Notification implements ShouldQueue
{
    use BrandedMailMessage;
    use Queueable;

    public function __construct(
        public Application $application,
        public Quote $quote,
        public BrandingSetting $branding,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $total = 'R'.number_format($this->quote->clientTotalCents() / 100, 2, '.', ' ');
        $expires = $this->quote->expires_at?->format('d M Y');

        return $this->newBrandedMailMessage($this->branding)
            ->subject($this->brandedSubject($this->branding, 'Quote sent', $this->application->reference))
            ->line('A quote is ready for '.$this->application->reference.'.')
            ->line('Client total: '.$total.($expires ? ' | Expires '.$expires : ''))
            ->action('Review and accept quote', $this->portalUrl('/applications/'.$this->application->id));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'application_reference' => $this->application->reference,
            'quote_id' => $this->quote->id,
            'event' => 'quote.sent',
        ];
    }
}
