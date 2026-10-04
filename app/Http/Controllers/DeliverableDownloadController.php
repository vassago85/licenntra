<?php

namespace App\Http\Controllers;

use App\Actions\RecordAudit;
use App\Models\DeliverableDocument;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeliverableDownloadController extends Controller
{
    public function __invoke(DeliverableDocument $deliverable, RecordAudit $audit): StreamedResponse
    {
        Gate::authorize('download', $deliverable);

        $audit->handle(
            request()->user(),
            $deliverable,
            'deliverable.downloaded',
            "Deliverable downloaded: {$deliverable->displayLabel()}.",
            null,
            ['kind' => $deliverable->kind->value],
        );

        return Storage::disk('documents')->download($deliverable->storage_path, $deliverable->original_filename);
    }
}
