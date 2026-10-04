<?php

namespace App\Actions;

use App\Models\Application;
use App\Models\DatafixRecord;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ConfirmDatafix
{
    public function __construct(private RecordAudit $audit) {}

    public function handle(Application $application, User $actor, int $tareKg, string $bodyType, int $gvmKg, ?string $choice = null): DatafixRecord
    {
        $bodyType = trim($bodyType);

        if ($tareKg < 0 || $gvmKg < 0 || $bodyType === '') {
            throw ValidationException::withMessages([
                'datafix' => 'Tare, body type, and GVM are required.',
            ]);
        }

        $record = DatafixRecord::query()->firstOrCreate(
            ['application_id' => $application->id],
            ['status' => $application->datafix_status],
        );

        if ($record->client_tare_kg === null && $record->client_body_type === null && $record->client_gvm_kg === null) {
            $record->client_tare_kg = $application->vehicle?->tare_kg;
            $record->client_body_type = $application->vehicle?->body_type;
            $record->client_gvm_kg = $application->vehicle?->gvm_kg;
        }

        $differs = $this->numberDiffers($record->client_tare_kg, $tareKg)
            || $this->textDiffers($record->client_body_type, $bodyType)
            || $this->numberDiffers($record->client_gvm_kg, $gvmKg);

        if ($differs && ! in_array($choice, ['client', 'reviewer'], true)) {
            throw ValidationException::withMessages([
                'value_choice' => 'The client value differs. Choose the client value or your value.',
            ]);
        }

        $useClient = $choice === 'client';
        $record->tare_kg = $useClient && $record->client_tare_kg !== null ? $record->client_tare_kg : $tareKg;
        $record->body_type = $useClient && filled($record->client_body_type) ? $record->client_body_type : $bodyType;
        $record->gvm_kg = $useClient && $record->client_gvm_kg !== null ? $record->client_gvm_kg : $gvmKg;
        $record->confirmed_by = $actor->id;
        $record->confirmed_at = now();
        $record->save();

        $application->vehicle?->update([
            'tare_kg' => $record->tare_kg,
            'body_type' => $record->body_type,
            'gvm_kg' => $record->gvm_kg,
        ]);

        $this->audit->handle(
            $actor,
            $application,
            'datafix.confirmed',
            'Datafix values confirmed.',
            null,
            [
                'tare_kg' => $record->tare_kg,
                'body_type' => $record->body_type,
                'gvm_kg' => $record->gvm_kg,
                'choice' => $choice,
            ],
        );

        return $record->refresh();
    }

    private function numberDiffers(?int $client, int $reviewer): bool
    {
        return $client !== null && $client !== $reviewer;
    }

    private function textDiffers(?string $client, string $reviewer): bool
    {
        return filled($client) && trim((string) $client) !== $reviewer;
    }
}
