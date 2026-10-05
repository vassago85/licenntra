<?php

namespace App\Actions;

use App\Enums\HandoverDirection;
use App\Enums\HandoverStatus;
use App\Models\Application;
use App\Models\DocumentHandover;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveDocumentHandover
{
    public function __construct(private RecordAudit $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, array $data, ?DocumentHandover $handover = null): DocumentHandover
    {
        if ($actor->client_account_id === null) {
            throw ValidationException::withMessages([
                'handover' => 'Only a dealership user can record a hand-over.',
            ]);
        }

        if ($handover !== null && ! $handover->isPending()) {
            throw ValidationException::withMessages([
                'handover' => 'This hand-over has already been confirmed and can no longer be edited.',
            ]);
        }

        foreach (['counterparty_name', 'counterparty_identifier', 'counterparty_company', 'dealer_person_name', 'items_summary', 'notes'] as $key) {
            if (array_key_exists($key, $data) && is_string($data[$key]) && trim($data[$key]) === '') {
                $data[$key] = null;
            }
        }

        $validated = validator($data, [
            'direction' => ['required', Rule::enum(HandoverDirection::class)],
            'counterparty_name' => ['nullable', 'string', 'max:160'],
            'counterparty_identifier' => ['nullable', 'string', 'max:32'],
            'counterparty_company' => ['nullable', 'string', 'max:160'],
            'dealer_person_name' => ['nullable', 'string', 'max:160'],
            'items_summary' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'application_ids' => ['array'],
            'application_ids.*' => ['integer'],
            'line_items' => ['array'],
        ])->validate();

        return DB::transaction(function () use ($actor, $validated, $handover): DocumentHandover {
            $creating = $handover === null;

            $handover ??= new DocumentHandover([
                'client_account_id' => $actor->client_account_id,
                'status' => HandoverStatus::Pending,
                'created_by_id' => $actor->id,
            ]);

            $handover->fill([
                'direction' => HandoverDirection::from($validated['direction']),
                'counterparty_name' => $validated['counterparty_name'] ?? null,
                'counterparty_identifier' => $validated['counterparty_identifier'] ?? null,
                'counterparty_company' => $validated['counterparty_company'] ?? 'Licensing authority',
                'dealer_person_name' => $validated['dealer_person_name'] ?? null,
                'items_summary' => $validated['items_summary'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            $handover->save();

            $applicationIds = $this->resolveApplicationIds($actor, $validated['application_ids'] ?? []);
            $syncPayload = [];

            foreach ($applicationIds as $id) {
                $description = $validated['line_items'][$id] ?? null;
                $syncPayload[$id] = [
                    'item_description' => is_string($description) && trim($description) !== '' ? trim($description) : null,
                ];
            }

            $handover->applications()->sync($syncPayload);

            $this->audit->handle(
                $actor,
                $handover,
                $creating ? 'handover.created' : 'handover.updated',
                $creating ? 'Hand-over draft created.' : 'Hand-over draft updated.',
                null,
                [
                    'direction' => $handover->direction->value,
                    'application_ids' => array_keys($syncPayload),
                ],
            );

            return $handover->refresh();
        });
    }

    /**
     * @param  list<int>  $incoming
     * @return list<int>
     */
    private function resolveApplicationIds(User $actor, array $incoming): array
    {
        if ($incoming === []) {
            return [];
        }

        $owned = Application::query()
            ->whereIn('id', $incoming)
            ->where('client_account_id', $actor->client_account_id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (count($owned) !== count(array_unique($incoming))) {
            throw ValidationException::withMessages([
                'application_ids' => 'Choose applications from your own dealership only.',
            ]);
        }

        return $owned;
    }
}
