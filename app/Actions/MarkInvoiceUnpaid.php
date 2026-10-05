<?php

namespace App\Actions;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Reverses a prior "Mark paid" when finance realises the money never
 * actually arrived (bounced EFT, allocated to the wrong invoice). Clears
 * paid_at / paid_by / paid_reference and writes an audit entry. Once the
 * application has no paid invoice left, its billed entries are owed again.
 */
class MarkInvoiceUnpaid
{
    public function __construct(private RecordAudit $audit) {}

    public function handle(Invoice $invoice, User $actor): Invoice
    {
        if (! $invoice->isPaid()) {
            return $invoice;
        }

        return DB::transaction(function () use ($invoice, $actor): Invoice {
            $previous = [
                'paid_at' => $invoice->paid_at?->toIso8601String(),
                'paid_by_user_id' => $invoice->paid_by_user_id,
                'paid_reference' => $invoice->paid_reference,
            ];

            $invoice->forceFill([
                'paid_at' => null,
                'paid_by_user_id' => null,
                'paid_reference' => null,
            ])->save();

            $application = $invoice->application;

            if ($application !== null && $application->invoices()->paid()->doesntExist()) {
                $application->payments()
                    ->where('on_account', true)
                    ->whereNotNull('statement_settled_at')
                    ->update(['statement_settled_at' => null, 'settled_by' => null]);
            }

            $this->audit->handle(
                $actor,
                $invoice->application,
                'invoice.unpaid',
                "Invoice {$invoice->invoice_number} marked unpaid.",
                $previous,
                [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                ],
            );

            return $invoice->refresh();
        });
    }
}
