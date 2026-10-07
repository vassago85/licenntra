<?php

namespace App\Actions;

use App\Models\Application;
use App\Models\SubmissionPack;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Records operations' explicit confirmation that the pack waiting to be
 * lodged was physically printed. Opening the print preview is audited
 * separately and never counts as printing.
 */
class MarkSubmissionPackPrinted
{
    public function __construct(
        private RecordAudit $audit,
    ) {}

    public function handle(Application $application, User $actor): SubmissionPack
    {
        if (! $actor->is_active || ! $actor->hasAnyRole(['reviewer', 'owner'])) {
            throw ValidationException::withMessages([
                'pack' => 'Only operations can mark a submission pack as printed.',
            ]);
        }

        $pack = $application->currentSubmissionPack();

        if ($pack === null) {
            throw ValidationException::withMessages([
                'pack' => $application->reference.': there is no unlodged pack listing the current documents. Prepare the pack again before printing it.',
            ]);
        }

        if ($pack->printed_at !== null) {
            return $pack;
        }

        $pack->forceFill(['printed_at' => now(), 'printed_by_id' => $actor->id])->save();

        $this->audit->handle(
            $actor,
            $application,
            'submission_pack.printed',
            'Submission pack #'.$pack->id.' marked as printed.',
            null,
            ['submission_pack_id' => $pack->id],
        );

        return $pack;
    }
}
