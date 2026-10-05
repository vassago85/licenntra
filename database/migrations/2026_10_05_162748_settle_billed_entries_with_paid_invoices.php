<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Marking an invoice paid now settles the application's billed entries.
 * Invoices paid before that change left their entries unsettled, which would
 * show as still owed; settle them as of the invoice's paid date.
 */
return new class extends Migration
{
    public function up(): void
    {
        $paidInvoices = DB::table('invoices')
            ->whereNotNull('paid_at')
            ->orderBy('paid_at')
            ->get(['application_id', 'paid_at', 'paid_by_user_id']);

        foreach ($paidInvoices as $invoice) {
            DB::table('payments')
                ->where('application_id', $invoice->application_id)
                ->where('on_account', true)
                ->whereNull('statement_settled_at')
                ->update([
                    'statement_settled_at' => $invoice->paid_at,
                    'settled_by' => $invoice->paid_by_user_id,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        //
    }
};
