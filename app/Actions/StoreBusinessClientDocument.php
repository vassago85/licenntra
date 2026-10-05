<?php

namespace App\Actions;

use App\Jobs\InspectDocument;
use App\Jobs\ScanDocument;
use App\Models\BusinessClient;
use App\Models\BusinessClientDocument;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Upload (or replace) a document attached to a {@see BusinessClient}
 * record - BRN certificate, proxy ID, proof of address, supporting
 * paperwork etc. Mirrors {@see StoreDocument} but writes to the
 * business_client_documents / document_versions tables and audits
 * against the BusinessClient instead of an ApplicationDocument so the
 * dealer can see which record was touched.
 */
class StoreBusinessClientDocument
{
    public function __construct(private RecordAudit $audit) {}

    public function handle(
        BusinessClient $businessClient,
        DocumentType $documentType,
        UploadedFile $file,
        User $actor,
    ): DocumentVersion {
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
        $directory = 'business-clients/'.$businessClient->id;
        $stored = $file->storeAs($directory, $storedName, 'documents');

        if ($stored === false) {
            throw ValidationException::withMessages([
                'upload' => 'The file could not be stored.',
            ]);
        }

        $version = DB::transaction(function () use (
            $businessClient,
            $documentType,
            $actor,
            $file,
            $mime,
            $size,
            $hash,
            $stored,
        ): DocumentVersion {
            // One row per (business_client, document_type) - a dealer who
            // uploads a replacement BRN just adds a new version to the
            // existing shell and the shell's current_version_id points
            // at the newest. Old versions stay queryable for audit.
            $document = BusinessClientDocument::query()->firstOrCreate(
                [
                    'business_client_id' => $businessClient->id,
                    'document_type_id' => $documentType->id,
                ],
                [],
            );

            $version = DocumentVersion::query()->create([
                'business_client_document_id' => $document->id,
                'storage_path' => $stored,
                'original_filename' => $file->getClientOriginalName(),
                'mime' => $mime,
                'size' => $size,
                'sha256' => $hash,
                'scan_status' => 'pending',
                'uploaded_by' => $actor->id,
            ]);

            $document->current_version_id = $version->id;
            $document->save();

            return $version;
        });

        ScanDocument::dispatch($version->id);
        InspectDocument::dispatch($version->id);

        $this->audit->handle(
            $actor,
            $businessClient,
            'business_client_document.stored',
            'Business client document uploaded and queued for scanning.',
            null,
            [
                'document_type_code' => $documentType->code,
                'sha256' => $hash,
                'mime' => $mime,
                'size' => $size,
            ],
        );

        return $version;
    }
}
