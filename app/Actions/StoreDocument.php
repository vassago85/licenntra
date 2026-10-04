<?php

namespace App\Actions;

use App\Enums\DocumentStatus;
use App\Jobs\InspectDocument;
use App\Jobs\ScanDocument;
use App\Models\ApplicationDocument;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StoreDocument
{
    public function __construct(private RecordAudit $audit) {}

    public function handle(ApplicationDocument $document, UploadedFile $file, User $actor): DocumentVersion
    {
        $path = $file->getRealPath();

        if ($path === false || ! is_file($path)) {
            throw ValidationException::withMessages([
                'upload' => 'The file could not be read.',
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
                'upload' => 'Upload a PDF, JPG, or PNG.',
            ]);
        }

        $size = $file->getSize() ?: filesize($path);

        if ($size === false || $size > 15 * 1024 * 1024) {
            throw ValidationException::withMessages([
                'upload' => 'The file must be 15 MB or smaller.',
            ]);
        }

        $hash = hash_file('sha256', $path);
        $storedName = Str::uuid()->toString().'.'.$allowed[$mime];
        $directory = 'applications/'.$document->application_id;
        $stored = $file->storeAs($directory, $storedName, 'documents');

        if ($stored === false) {
            throw ValidationException::withMessages([
                'upload' => 'The file could not be stored.',
            ]);
        }

        $version = DB::transaction(function () use ($document, $actor, $file, $mime, $size, $hash, $stored): DocumentVersion {
            $version = DocumentVersion::query()->create([
                'application_document_id' => $document->id,
                'storage_path' => $stored,
                'original_filename' => $file->getClientOriginalName(),
                'mime' => $mime,
                'size' => $size,
                'sha256' => $hash,
                'scan_status' => 'pending',
                'uploaded_by' => $actor->id,
            ]);

            $document->status = DocumentStatus::Scanning;
            $document->linked_version_id = $version->id;
            $document->source = 'upload';
            $document->rejection_reason = null;
            $document->reviewer_comment = null;
            $document->save();

            return $version;
        });

        ScanDocument::dispatch($version->id);
        InspectDocument::dispatch($version->id);

        $this->audit->handle(
            $actor,
            $document,
            'document.stored',
            'Document uploaded and queued for scanning.',
            null,
            ['sha256' => $hash, 'mime' => $mime, 'size' => $size],
        );

        return $version;
    }
}
