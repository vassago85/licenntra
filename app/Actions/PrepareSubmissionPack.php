<?php

namespace App\Actions;

use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\SubmissionPack;
use App\Models\User;
use App\Services\OperationsWorkloadService;
use Illuminate\Validation\ValidationException;

/**
 * Freezes the document versions going to the licensing department into a
 * pack the office prints and lodges. Reuses the latest pack while it still
 * matches the current versions so reprinting never creates duplicates.
 */
class PrepareSubmissionPack
{
    public function __construct(
        private OperationsWorkloadService $workload,
        private RecordAudit $audit,
    ) {}

    public function handle(Application $application, User $actor): SubmissionPack
    {
        if (! $actor->is_active || ! $actor->hasAnyRole(['reviewer', 'owner'])) {
            throw ValidationException::withMessages([
                'pack' => 'Only operations can prepare a submission pack.',
            ]);
        }

        $blockers = $this->workload->authorityBlockers($application);

        if ($blockers !== []) {
            throw ValidationException::withMessages([
                'pack' => $application->reference.': '.implode(' ', $blockers),
            ]);
        }

        $existing = $application->currentSubmissionPack();

        if ($existing !== null && $existing->submitted_at === null) {
            return $existing;
        }

        $documents = $application->packDocuments();

        $pack = $application->submissionPacks()->create([
            'prepared_by_id' => $actor->id,
            'manifest' => [
                'documents' => $documents->map(fn (ApplicationDocument $document): array => [
                    'document_id' => $document->id,
                    'label' => $document->label(),
                    'required' => (bool) $document->required,
                    'version_id' => (int) $document->currentVersion->id,
                    'original_filename' => $document->currentVersion->original_filename,
                    'mime' => $document->currentVersion->mime,
                    'size' => (int) $document->currentVersion->size,
                    'sha256' => (string) $document->currentVersion->sha256,
                    'uploaded_at' => $document->currentVersion->created_at?->toIso8601String(),
                    'requires_original' => $document->requiresOriginal(),
                    'original_received_at' => $document->original_received_at?->toIso8601String(),
                ])->values()->all(),
            ],
        ]);

        $this->audit->handle(
            $actor,
            $application,
            'submission_pack.prepared',
            sprintf('Submission pack #%d prepared with %d %s.', $pack->id, $pack->documentCount(), $pack->documentCount() === 1 ? 'document' : 'documents'),
            null,
            ['submission_pack_id' => $pack->id, 'version_ids' => $pack->versionIds()],
        );

        return $pack;
    }
}
