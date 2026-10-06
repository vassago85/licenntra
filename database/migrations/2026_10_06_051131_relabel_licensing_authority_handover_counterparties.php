<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Hand-overs are between the licensing company and its client, so rows
     * saved with the old "Licensing authority" default name the licensing
     * company from branding instead.
     */
    public function up(): void
    {
        $companyName = DB::table('branding_settings')->orderBy('id')->value('company_name') ?: 'Licentra';

        DB::table('document_handovers')
            ->where(function ($query): void {
                $query->where('counterparty_company', 'Licensing authority')
                    ->orWhereNull('counterparty_company');
            })
            ->update(['counterparty_company' => $companyName]);
    }

    public function down(): void
    {
        //
    }
};
