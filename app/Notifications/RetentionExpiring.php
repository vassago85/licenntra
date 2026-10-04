<?php

namespace App\Notifications;

use App\Models\BusinessClient;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RetentionExpiring extends Notification
{
    use Queueable;

    public function __construct(public BusinessClient $businessClient) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $expires = $this->businessClient->retention_expires_at?->timezone(config('app.timezone'))->format('d M Y');

        return (new MailMessage)
            ->subject('Retention period ending: '.$this->businessClient->business_name)
            ->line($this->businessClient->business_name.' is due to leave retention on '.$expires.'.')
            ->line('Extend the consent or let the record lapse. A legal hold stops deletion.')
            ->action('Open business clients', url('/business-clients'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'business_client_id' => $this->businessClient->id,
            'retention_expires_at' => $this->businessClient->retention_expires_at?->toDateTimeString(),
        ];
    }
}
