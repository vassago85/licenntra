<?php

namespace App\Http\Controllers;

use App\Actions\RecordAudit;
use App\Models\ApplicationDocument;
use App\Models\BusinessClientDocument;
use App\Models\DocumentVersion;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a single document version to the browser. A DocumentVersion
 * belongs either to an {@see ApplicationDocument} (uploaded
 * on an application) or a {@see BusinessClientDocument}
 * (uploaded on a business client record for re-use). This controller
 * dispatches to the right authorization check and audit event based on
 * which parent the version is attached to.
 */
class DocumentDownloadController extends Controller
{
    public function __invoke(DocumentVersion $version, RecordAudit $audit): StreamedResponse
    {
        $applicationDocument = $version->applicationDocument;

        if ($applicationDocument !== null) {
            Gate::authorize('download', $applicationDocument);

            if (request()->boolean('inline')) {
                $audit->handle(
                    request()->user(),
                    $applicationDocument,
                    'document.viewed',
                    'Document viewed.',
                    null,
                    ['version_id' => $version->id],
                );

                return Storage::disk('documents')->response($version->storage_path, $version->original_filename, ['Content-Type' => $version->mime]);
            }

            $audit->handle(
                request()->user(),
                $applicationDocument,
                'document.downloaded',
                'Document downloaded.',
                null,
                ['version_id' => $version->id],
            );

            return Storage::disk('documents')->download($version->storage_path, $version->original_filename);
        }

        $businessClientDocument = $version->businessClientDocument;

        if ($businessClientDocument !== null) {
            $businessClient = $businessClientDocument->businessClient;

            if ($businessClient === null) {
                abort(404);
            }

            // Downloading a business-client-attached document rides on
            // the same policy as viewing the parent record: dealer must
            // own the business client (or it must be a shared title
            // holder) OR be licensing staff. The BusinessClient global
            // scope already filters model binding so cross-dealer
            // requests hit 404 before this point - but we re-authorise
            // defensively in case the version id is tampered with.
            Gate::authorize('view', $businessClient);

            $audit->handle(
                request()->user(),
                $businessClient,
                'business_client_document.downloaded',
                'Business client document downloaded.',
                null,
                [
                    'version_id' => $version->id,
                    'document_type_id' => $businessClientDocument->document_type_id,
                ],
            );

            return Storage::disk('documents')->download($version->storage_path, $version->original_filename);
        }

        abort(404);
    }
}
