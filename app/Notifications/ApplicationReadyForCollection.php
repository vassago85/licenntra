<?php

namespace App\Notifications;

use App\Models\Application;
use App\Models\BrandingSetting;
use App\Notifications\Concerns\BrandedMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApplicationReadyForCollection extends Notification implements ShouldQueue
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
        return $this->newBrandedMailMessage($this->branding)
            ->subject($this->brandedSubject($this->branding, 'Ready for collection', $this->application->reference))
            ->line($this->application->reference.' is ready for collection.')
            ->line($this->application->deliverableLabel().' is on hand.')
            ->line('Pop in or arrange a courier to collect at your convenience.')
            ->action('View application', $this->portalUrl('/applications/'.$this->application->id));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'application_reference' => $this->application->reference,
            'event' => 'application.ready_for_collection',
        ];
    }
}
