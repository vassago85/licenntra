<?php

namespace App\Http\Controllers;

use App\Actions\RecordAudit;
use App\Models\Invoice;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceDownloadController extends Controller
{
    public function __invoke(Invoice $invoice, RecordAudit $audit): StreamedResponse
    {
        Gate::authorize('download', $invoice);

        $audit->handle(
            request()->user(),
            $invoice->application,
            'invoice.downloaded',
            "Invoice {$invoice->invoice_number} downloaded.",
            null,
            ['invoice_id' => $invoice->id, 'invoice_number' => $invoice->invoice_number],
        );

        return Storage::disk('documents')->download($invoice->storage_path, $invoice->original_filename);
    }
}
