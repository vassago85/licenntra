<?php

namespace App\Http\Controllers;

use App\Actions\RecordAudit;
use App\Models\DocumentVersion;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentDownloadController extends Controller
{
    public function __invoke(DocumentVersion $version, RecordAudit $audit): StreamedResponse
    {
        $document = $version->applicationDocument;

        if ($document === null) {
            abort(404);
        }

        Gate::authorize('download', $document);

        $audit->handle(
            request()->user(),
            $document,
            'document.downloaded',
            'Document downloaded.',
            null,
            ['version_id' => $version->id],
        );

        return Storage::disk('documents')->download($version->storage_path, $version->original_filename);
    }
}
