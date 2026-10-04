<?php

namespace App\Notifications;

use App\Models\BrandingSetting;
use App\Models\FleetVehicleDocument;
use App\Notifications\Concerns\BrandedMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FleetLicenceReadyForReview extends Notification implements ShouldQueue
{
    use BrandedMailMessage;
    use Queueable;

    public function __construct(
        public FleetVehicleDocument $document,
        public BrandingSetting $branding,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $vehicle = $this->document->fleetVehicle;
        $fleetName = $vehicle?->clientAccount?->name ?? 'the fleet';
        $status = $this->document->ocr_status?->value ?? 'pending';

        $mail = $this->newBrandedMailMessage($this->branding)
            ->subject($this->brandedSubject($this->branding, 'Fleet licence ready for review', $fleetName))
            ->line('A fleet licence you uploaded has finished reading.');

        if ($this->document->ocr_expiry_candidate) {
            $mail->line('Suggested expiry: '.$this->document->ocr_expiry_candidate->format('Y-m-d'));
        } else {
            $mail->line('No expiry was found. You will need to type it in.');
        }

        return $mail->line('Status: '.$status)
            ->action('Open review', $this->portalUrl('/fleet-vehicles/review/'.$this->document->id));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'fleet_vehicle_document_id' => $this->document->id,
            'fleet_vehicle_id' => $this->document->fleet_vehicle_id,
            'event' => 'fleet_vehicle.ocr_completed',
        ];
    }
}
