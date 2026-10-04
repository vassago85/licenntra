<?php

namespace App\Notifications;

use App\Models\BrandingSetting;
use App\Notifications\Concerns\BrandedMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Deliberately not queued. The admin clicks "Send test email" and expects
 * the result in a few seconds, not after the queue worker picks it up.
 */
class MailgunTestNotification extends Notification
{
    use BrandedMailMessage;
    use Queueable;

    public function __construct(public BrandingSetting $branding) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->newBrandedMailMessage($this->branding)
            ->subject($this->brandedSubject($this->branding, 'Mailgun test', 'delivery works'))
            ->line('This is a test email from Licentra.')
            ->line('If you received this, the Mailgun credentials are configured correctly.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'notifications.test',
        ];
    }
}
