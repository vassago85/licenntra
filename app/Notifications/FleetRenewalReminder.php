<?php

namespace App\Notifications;

use App\Models\BrandingSetting;
use App\Models\FleetVehicle;
use App\Notifications\Concerns\BrandedMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FleetRenewalReminder extends Notification implements ShouldQueue
{
    use BrandedMailMessage;
    use Queueable;

    /**
     * @param  Collection<int, FleetVehicle>  $vehicles
     */
    public function __construct(
        public Collection $vehicles,
        public Carbon $month,
        public BrandingSetting $branding,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $monthLabel = $this->month->format('F Y');
        $mail = $this->newBrandedMailMessage($this->branding)
            ->subject($this->brandedSubject($this->branding, 'Vehicles up for renewal', $monthLabel))
            ->line('The following vehicles have motor-vehicle licences that expire in '.$monthLabel.'.');

        foreach ($this->vehicles as $vehicle) {
            $register = $vehicle->vehicle_register_number ?? '(no register number)';
            $expiry = $vehicle->licence_expires_on?->format('Y-m-d') ?? '(no expiry)';
            $renewUrl = $this->portalUrl('/applications/create?prefill_fleet_vehicle='.$vehicle->id);
            $mail->line('• '.$register.' — expires '.$expiry.' — '.$renewUrl);
        }

        return $mail->line('Each link opens a renewal already filled in from the vehicle record. A commercial renewal requires a new certificate of fitness.')
            ->action('Open fleet vehicles', $this->portalUrl('/fleet-vehicles'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'fleet_vehicle_ids' => $this->vehicles->pluck('id')->all(),
            'month' => $this->month->format('Y-m'),
            'event' => 'fleet.renewal_reminder',
        ];
    }
}
