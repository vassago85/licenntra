<?php

namespace App\Jobs;

use App\Actions\RecordAudit;
use App\Enums\QuoteStatus;
use App\Models\Quote;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExpireQuotes implements ShouldQueue
{
    use Queueable;

    public function handle(RecordAudit $audit): void
    {
        Quote::query()
            ->where('status', QuoteStatus::Sent)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->orderBy('id')
            ->each(function (Quote $quote) use ($audit): void {
                $quote->status = QuoteStatus::Expired;
                $quote->save();

                $audit->handle(null, $quote, 'quote.expired', 'Quote expired.', null, null, true);
            });
    }
}
