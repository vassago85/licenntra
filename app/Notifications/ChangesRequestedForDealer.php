<?php

namespace App\Notifications;

use App\Models\Application;
use App\Models\BrandingSetting;
use App\Notifications\Concerns\BrandedMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ChangesRequestedForDealer extends Notification implements ShouldQueue
{
    use BrandedMailMessage;
    use Queueable;

    public function __construct(
        public Application $application,
        public ?string $reason,
        public BrandingSetting $branding,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = $this->newBrandedMailMessage($this->branding)
            ->subject($this->brandedSubject($this->branding, 'Changes requested', $this->application->reference))
            ->line('Our operations team requested changes on '.$this->application->reference.'.');

        if (filled($this->reason)) {
            $message->line('Operations note: '.$this->reason);
        }

        return $message
            ->line('Open the application to replace the flagged document(s) and resubmit.')
            ->action('Open application', $this->portalUrl('/applications/'.$this->application->id.'/edit'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'application_reference' => $this->application->reference,
            'reason' => $this->reason,
            'event' => 'application.changes_requested',
        ];
    }
}
