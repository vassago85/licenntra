<?php

namespace App\Actions;

use App\Models\Application;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stores a tax invoice uploaded by the licensing company against an
 * application. Mirrors the deliverable-upload pipeline (PDF/JPG/PNG, 15 MB,
 * private documents disk) and insists the application is at a stage that
 * can carry an invoice. The invoice amount itself is read from the
 * application's fee snapshot — invoices never store their own total.
 */
class StoreInvoice
{
    public function __construct(private RecordAudit $audit) {}

    public function handle(
        Application $application,
        UploadedFile $file,
        string $invoiceNumber,
        User $actor,
    ): Invoice {
        $invoiceNumber = trim($invoiceNumber);

        if ($invoiceNumber === '') {
            throw ValidationException::withMessages([
                'invoice_number' => 'Enter the invoice number.',
            ]);
        }

        if (mb_strlen($invoiceNumber) > 40) {
            throw ValidationException::withMessages([
                'invoice_number' => 'Invoice number is limited to 40 characters.',
            ]);
        }

        if (! $application->stage->canCarryInvoice()) {
            throw ValidationException::withMessages([
                'invoice' => 'The application has not reached a stage that can carry an invoice yet.',
            ]);
        }

        if (Invoice::query()->where('invoice_number', $invoiceNumber)->exists()) {
            throw ValidationException::withMessages([
                'invoice_number' => 'That invoice number is already in use.',
            ]);
        }

        $path = $file->getRealPath();

        if ($path === false || ! is_file($path)) {
            throw ValidationException::withMessages([
                'invoice' => 'The file could not be read.',
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
                'invoice' => 'Upload a PDF, JPG, or PNG.',
            ]);
        }

        $size = $file->getSize() ?: filesize($path);

        if ($size === false || $size > 15 * 1024 * 1024) {
            throw ValidationException::withMessages([
                'invoice' => 'The file must be 15 MB or smaller.',
            ]);
        }

        $hash = hash_file('sha256', $path);
        $storedName = Str::uuid()->toString().'.'.$allowed[$mime];
        $directory = 'invoices/'.$application->id;
        $stored = $file->storeAs($directory, $storedName, 'documents');

        if ($stored === false) {
            throw ValidationException::withMessages([
                'invoice' => 'The file could not be stored.',
            ]);
        }

        $invoice = DB::transaction(fn (): Invoice => Invoice::query()->create([
            'application_id' => $application->id,
            'invoice_number' => $invoiceNumber,
            'storage_path' => $stored,
            'original_filename' => $file->getClientOriginalName(),
            'mime' => $mime,
            'size_bytes' => (int) $size,
            'sha256' => $hash,
            'uploaded_by_id' => $actor->id,
            'uploaded_at' => Carbon::now(),
        ]));

        $this->audit->handle(
            $actor,
            $application,
            'invoice.uploaded',
            "Invoice {$invoiceNumber} uploaded.",
            null,
            [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoiceNumber,
                'sha256' => $hash,
                'mime' => $mime,
                'size' => (int) $size,
            ],
        );

        return $invoice;
    }
}
