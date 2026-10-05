<?php

namespace App\Actions;

use App\Enums\HandoverStatus;
use App\Models\DocumentHandover;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ConfirmDocumentHandover
{
    public function __construct(private RecordAudit $audit) {}

    public function handle(User $actor, DocumentHandover $handover): DocumentHandover
    {
        if (! $handover->isPending()) {
            throw ValidationException::withMessages([
                'handover' => 'This hand-over has already been confirmed.',
            ]);
        }

        if ($handover->applications()->count() === 0) {
            throw ValidationException::withMessages([
                'handover' => 'Add at least one application before confirming the hand-over.',
            ]);
        }

        if (trim((string) $handover->counterparty_name) === '') {
            throw ValidationException::withMessages([
                'counterparty_name' => 'Capture the name of the person signing on behalf of the authority first.',
            ]);
        }

        if (trim((string) $handover->dealer_person_name) === '') {
            throw ValidationException::withMessages([
                'dealer_person_name' => 'Capture the name of the dealership staff member on the counter first.',
            ]);
        }

        $handover->forceFill([
            'status' => HandoverStatus::Completed,
            'confirmed_by_id' => $actor->id,
            'confirmed_at' => now(),
        ])->save();

        $this->audit->handle(
            $actor,
            $handover,
            'handover.confirmed',
            'Hand-over confirmed on-screen by both parties.',
            null,
            [
                'direction' => $handover->direction->value,
                'application_count' => $handover->applications()->count(),
            ],
        );

        return $handover->refresh();
    }
}
