<?php

namespace App\Actions;

use App\Enums\QuoteStatus;
use App\Models\Application;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateQuote
{
    public function __construct(private RecordAudit $audit) {}

    /**
     * @param  list<array{description: string, client_price_cents: int, internal_cost_cents?: int}>  $lines
     */
    public function handle(Application $application, User $actor, array $lines, ?Carbon $expiresAt, ?string $clientNotes, ?string $internalNotes): Quote
    {
        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => 'Add at least one quote line.',
            ]);
        }

        return DB::transaction(function () use ($application, $actor, $lines, $expiresAt, $clientNotes, $internalNotes): Quote {
            $quote = Quote::query()->create([
                'application_id' => $application->id,
                'status' => QuoteStatus::Draft,
                'expires_at' => $expiresAt,
                'client_notes' => $clientNotes,
                'internal_notes' => $internalNotes,
                'created_by' => $actor->id,
            ]);

            foreach ($lines as $line) {
                $description = trim((string) ($line['description'] ?? ''));

                if ($description === '') {
                    throw ValidationException::withMessages([
                        'lines' => 'Every quote line needs a description.',
                    ]);
                }

                $quote->lines()->create([
                    'description' => $description,
                    'client_price_cents' => (int) ($line['client_price_cents'] ?? 0),
                    'internal_cost_cents' => (int) ($line['internal_cost_cents'] ?? 0),
                ]);
            }

            $this->audit->handle($actor, $quote, 'quote.created', 'Quote drafted.', null, [
                'lines' => count($lines),
                'expires_at' => $expiresAt?->toDateTimeString(),
            ]);

            return $quote->refresh();
        });
    }
}
