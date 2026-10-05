<?php

namespace App\Actions;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Marks an invoice as paid. Finance uses this once the money has landed
 * (either against the dealership's monthly statement or an up-front
 * transaction). An optional reference captures the EFT / statement line
 * so the record ties back to accounting. Paying the invoice settles the
 * application's billed-but-unpaid entries, which moves them from
 * outstanding to received on the overview.
 */
class MarkInvoicePaid
{
    public function __construct(private RecordAudit $audit) {}

    public function handle(Invoice $invoice, User $actor, ?string $reference = null): Invoice
    {
        if ($invoice->isPaid()) {
            return $invoice;
        }

        $reference = $reference !== null ? trim($reference) : null;

        return DB::transaction(function () use ($invoice, $actor, $reference): Invoice {
            $invoice->forceFill([
                'paid_at' => Carbon::now(),
                'paid_by_user_id' => $actor->id,
                'paid_reference' => $reference !== null && $reference !== '' ? $reference : null,
            ])->save();

            $invoice->application?->payments()
                ->outstandingOnStatement()
                ->update(['statement_settled_at' => Carbon::now(), 'settled_by' => $actor->id]);

            $this->audit->handle(
                $actor,
                $invoice->application,
                'invoice.paid',
                "Invoice {$invoice->invoice_number} marked paid.",
                null,
                [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'paid_reference' => $invoice->paid_reference,
                ],
            );

            return $invoice->refresh();
        });
    }
}
