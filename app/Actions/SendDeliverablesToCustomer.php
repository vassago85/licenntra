<?php

namespace App\Actions;

use App\Models\Application;
use App\Models\BrandingSetting;
use App\Models\DeliverableDocument;
use App\Models\User;
use App\Notifications\DeliverablesForCustomer;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Sends one or more application deliverables (NaTIS cert, licence disc,
 * etc.) straight to the vehicle owner's email on behalf of the dealer.
 * Validates that every requested deliverable actually belongs to the
 * given application so a tampered form post can't attach another
 * customer's documents.
 */
class SendDeliverablesToCustomer
{
    public function __construct(private RecordAudit $audit) {}

    /**
     * @param  list<int>  $deliverableIds
     */
    public function handle(
        Application $application,
        User $actor,
        string $recipientEmail,
        array $deliverableIds,
        ?string $dealerMessage = null,
    ): int {
        $data = validator(
            [
                'recipient_email' => trim($recipientEmail),
                'deliverable_ids' => array_values(array_unique(array_map('intval', $deliverableIds))),
                'dealer_message' => $dealerMessage !== null ? trim($dealerMessage) : null,
            ],
            [
                'recipient_email' => ['required', 'email:rfc', 'max:254'],
                'deliverable_ids' => ['required', 'array', 'min:1', 'max:10'],
                'deliverable_ids.*' => ['integer', Rule::exists('deliverable_documents', 'id')
                    ->where('application_id', $application->id)],
                'dealer_message' => ['nullable', 'string', 'max:2000'],
            ],
            [
                'deliverable_ids.required' => 'Pick at least one document to send.',
                'deliverable_ids.min' => 'Pick at least one document to send.',
                'deliverable_ids.*.exists' => 'One of the documents does not belong to this application.',
            ],
        )->validate();

        $deliverables = DeliverableDocument::query()
            ->where('application_id', $application->id)
            ->whereIn('id', $data['deliverable_ids'])
            ->get();

        if ($deliverables->count() !== count($data['deliverable_ids'])) {
            throw ValidationException::withMessages([
                'deliverable_ids' => 'One of the documents could not be loaded.',
            ]);
        }

        $branding = BrandingSetting::current();
        $dealershipName = $application->clientAccount?->name ?? $branding->company_name;

        Notification::route('mail', $data['recipient_email'])
            ->notify(new DeliverablesForCustomer(
                application: $application,
                deliverables: $deliverables,
                branding: $branding,
                dealershipName: $dealershipName,
                dealerMessage: $data['dealer_message'],
            ));

        $maskedEmail = $this->maskEmail($data['recipient_email']);

        $this->audit->handle(
            $actor,
            $application,
            'deliverable.sent_to_customer',
            'Deliverables emailed to '.$maskedEmail.'.',
            null,
            [
                'recipient_email_masked' => $maskedEmail,
                'deliverable_ids' => $deliverables->pluck('id')->all(),
                'deliverable_kinds' => $deliverables->pluck('kind')
                    ->map(fn ($kind) => $kind->value ?? (string) $kind)
                    ->all(),
                'dealership' => $dealershipName,
            ],
        );

        return $deliverables->count();
    }

    /**
     * Hide the local part of the recipient email in the audit payload so
     * it survives POPIA review - "paul.c@example.com" becomes
     * "p...c@example.com". The full address is only ever used at send
     * time inside the notification pipeline.
     */
    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);

        if (count($parts) !== 2) {
            return Str::of($email)->mask('*', 1, max(1, Str::length($email) - 2))->value();
        }

        [$local, $domain] = $parts;
        $local = (string) $local;

        if (Str::length($local) <= 2) {
            return $local[0].'...@'.$domain;
        }

        return $local[0].'...'.$local[Str::length($local) - 1].'@'.$domain;
    }
}
