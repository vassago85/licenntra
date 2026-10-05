<?php

namespace App\Notifications;

use App\Models\Application;
use App\Models\BrandingSetting;
use App\Models\DeliverableDocument;
use App\Notifications\Concerns\BrandedMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Branded "here are your documents" email a dealer sends straight to the
 * vehicle owner once the licensing company returns the NaTIS cert and
 * licence disc. The deliverables are attached as PDFs/images - these are
 * the owner's own documents, so attaching them is appropriate (same
 * posture a dealership would use when emailing from Outlook).
 */
class DeliverablesForCustomer extends Notification implements ShouldQueue
{
    use BrandedMailMessage;
    use Queueable;

    /**
     * @param  Collection<int, DeliverableDocument>  $deliverables
     */
    public function __construct(
        public Application $application,
        public Collection $deliverables,
        public BrandingSetting $branding,
        public string $dealershipName,
        public ?string $dealerMessage = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $vehicleLine = $this->vehicleOneLiner();

        $message = $this->newBrandedMailMessage($this->branding)
            ->subject($this->brandedSubject($this->branding, 'Your vehicle documents', $this->application->reference))
            ->line('Please find your vehicle documents from '.$this->dealershipName.' attached.');

        if ($vehicleLine !== null) {
            $message->line($vehicleLine);
        }

        if ($this->dealerMessage !== null && trim($this->dealerMessage) !== '') {
            $message->line('')
                ->line('Message from '.$this->dealershipName.':')
                ->line($this->dealerMessage);
        }

        $message->line('Reference: '.$this->application->reference);

        foreach ($this->deliverables as $deliverable) {
            $absolutePath = Storage::disk('documents')->path($deliverable->storage_path);

            if (! is_file($absolutePath)) {
                continue;
            }

            $message->attach($absolutePath, [
                'as' => $this->filenameFor($deliverable),
                'mime' => $deliverable->mime,
            ]);
        }

        return $message;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'application_reference' => $this->application->reference,
            'deliverable_ids' => $this->deliverables->pluck('id')->all(),
            'event' => 'deliverable.sent_to_customer',
        ];
    }

    /**
     * Human-friendly one-line vehicle summary when the application has a
     * vehicle attached. Falls back to null so the email just skips the
     * line instead of printing an empty bullet.
     */
    private function vehicleOneLiner(): ?string
    {
        $vehicle = $this->application->vehicle;

        if ($vehicle === null) {
            return null;
        }

        $parts = array_filter([
            trim((string) ($vehicle->make ?? '')) !== '' ? $vehicle->make : null,
            trim((string) ($vehicle->model ?? '')) !== '' ? $vehicle->model : null,
            $vehicle->vehicle_register_number !== null && $vehicle->vehicle_register_number !== ''
                ? '('.$vehicle->vehicle_register_number.')'
                : null,
        ]);

        if ($parts === []) {
            return null;
        }

        return 'Vehicle: '.implode(' ', $parts);
    }

    /**
     * Give the attachment a human-readable file name - mail clients then
     * show "NaTIS registration certificate.pdf" instead of a uuid.
     */
    private function filenameFor(DeliverableDocument $deliverable): string
    {
        $label = $deliverable->displayLabel();
        $extension = match ($deliverable->mime) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => pathinfo($deliverable->original_filename, PATHINFO_EXTENSION) ?: 'pdf',
        };

        return $label.'.'.$extension;
    }
}
