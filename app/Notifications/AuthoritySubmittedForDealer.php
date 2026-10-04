<?php

namespace App\Notifications;

use App\Models\Application;
use App\Models\BrandingSetting;
use App\Notifications\Concerns\BrandedMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AuthoritySubmittedForDealer extends Notification implements ShouldQueue
{
    use BrandedMailMessage;
    use Queueable;

    public function __construct(
        public Application $application,
        public BrandingSetting $branding,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $submittedAt = $this->application->authority_submitted_at?->format('d M Y H:i');
        $reference = $this->application->authority_reference ?: 'not captured';

        return $this->newBrandedMailMessage($this->branding)
            ->subject($this->brandedSubject($this->branding, 'Submitted to authority', $this->application->reference))
            ->line($this->application->reference.' was handed to the authority on '.($submittedAt ?: 'today').'.')
            ->line('Authority reference: '.$reference)
            ->line('We will update you when the authority confirms the outcome.')
            ->action('Track application', $this->portalUrl('/applications/'.$this->application->id));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'application_reference' => $this->application->reference,
            'authority_reference' => $this->application->authority_reference,
            'authority_submitted_at' => $this->application->authority_submitted_at?->toIso8601String(),
            'event' => 'application.authority_submitted',
        ];
    }
}
