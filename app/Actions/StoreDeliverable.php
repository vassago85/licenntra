<?php

namespace App\Actions;

use App\Enums\DeliverableKind;
use App\Models\Application;
use App\Models\DeliverableDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stores a document that the licensing company received back from the
 * authority (NaTIS certificate, licence disc scan, etc.) against an
 * application so the originating dealership can retrieve it once the
 * transaction is completed.
 */
class StoreDeliverable
{
    public function __construct(private RecordAudit $audit) {}

    public function handle(
        Application $application,
        UploadedFile $file,
        DeliverableKind $kind,
        User $actor,
        ?string $label = null,
        ?string $handoverNotes = null,
    ): DeliverableDocument {
        $path = $file->getRealPath();

        if ($path === false || ! is_file($path)) {
            throw ValidationException::withMessages([
                'deliverable' => 'The file could not be read.',
            ]);
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $allowed = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
        ];

        if (! is_string($mime) || ! isset($allowed[$mime])) {
            throw ValidationException::withMessages([
                'deliverable' => 'Upload a PDF, JPG, or PNG.',
            ]);
        }

        $size = $file->getSize() ?: filesize($path);

        if ($size === false || $size > 15 * 1024 * 1024) {
            throw ValidationException::withMessages([
                'deliverable' => 'The file must be 15 MB or smaller.',
            ]);
        }

        $hash = hash_file('sha256', $path);
        $storedName = Str::uuid()->toString().'.'.$allowed[$mime];
        $directory = 'deliverables/'.$application->id;
        $stored = $file->storeAs($directory, $storedName, 'documents');

        if ($stored === false) {
            throw ValidationException::withMessages([
                'deliverable' => 'The file could not be stored.',
            ]);
        }

        $deliverable = DB::transaction(fn (): DeliverableDocument => DeliverableDocument::query()->create([
            'application_id' => $application->id,
            'kind' => $kind->value,
            'label' => $label !== null && $label !== '' ? $label : null,
            'storage_path' => $stored,
            'original_filename' => $file->getClientOriginalName(),
            'mime' => $mime,
            'size_bytes' => (int) $size,
            'sha256' => $hash,
            'uploaded_by_id' => $actor->id,
            'uploaded_at' => Carbon::now(),
            'handover_notes' => $handoverNotes !== null && $handoverNotes !== '' ? $handoverNotes : null,
        ]));

        $this->audit->handle(
            $actor,
            $application,
            'deliverable.stored',
            "Deliverable uploaded: {$deliverable->displayLabel()}.",
            null,
            [
                'deliverable_id' => $deliverable->id,
                'kind' => $kind->value,
                'sha256' => $hash,
                'mime' => $mime,
                'size' => (int) $size,
            ],
        );

        return $deliverable;
    }
}
