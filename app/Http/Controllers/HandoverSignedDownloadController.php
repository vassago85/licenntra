<?php

namespace App\Http\Controllers;

use App\Actions\RecordAudit;
use App\Models\DocumentHandover;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HandoverSignedDownloadController extends Controller
{
    public function __invoke(DocumentHandover $handover, RecordAudit $audit): StreamedResponse
    {
        Gate::authorize('view', $handover);

        if ($handover->signed_file_path === null) {
            abort(404);
        }

        $audit->handle(
            request()->user(),
            $handover,
            'handover.signed_downloaded',
            'Signed hand-over scan downloaded.',
            null,
            ['filename' => $handover->signed_file_original_name],
        );

        return Storage::disk('documents')->download(
            $handover->signed_file_path,
            $handover->signed_file_original_name ?? 'handover.pdf',
        );
    }
}
