<?php

namespace App\Actions;

use App\Enums\HandoverDirection;
use App\Enums\HandoverStatus;
use App\Models\Application;
use App\Models\BrandingSetting;
use App\Models\ClientAccount;
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
        $accountId = $this->resolveAccountId($actor, $data, $handover);

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

        return DB::transaction(function () use ($actor, $accountId, $validated, $handover): DocumentHandover {
            $creating = $handover === null;

            $handover ??= new DocumentHandover([
                'client_account_id' => $accountId,
                'status' => HandoverStatus::Pending,
                'created_by_id' => $actor->id,
            ]);

            $handover->fill([
                'direction' => HandoverDirection::from($validated['direction']),
                'counterparty_name' => $validated['counterparty_name'] ?? null,
                'counterparty_identifier' => $validated['counterparty_identifier'] ?? null,
                'counterparty_company' => $validated['counterparty_company'] ?? BrandingSetting::current()->company_name,
                'dealer_person_name' => $validated['dealer_person_name'] ?? null,
                'items_summary' => $validated['items_summary'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            $handover->save();

            $applicationIds = $this->resolveApplicationIds($accountId, $validated['application_ids'] ?? []);
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
     * Dealership users always record against their own account; operations
     * staff choose the dealership, which is fixed once the draft exists.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveAccountId(User $actor, array $data, ?DocumentHandover $handover): int
    {
        if ($actor->client_account_id !== null) {
            if ($handover !== null && $handover->client_account_id !== $actor->client_account_id) {
                throw ValidationException::withMessages([
                    'handover' => 'This hand-over belongs to another dealership.',
                ]);
            }

            return (int) $actor->client_account_id;
        }

        if (! $actor->is_active || ! $actor->hasAnyRole(['reviewer', 'owner'])) {
            throw ValidationException::withMessages([
                'handover' => 'Only dealership users and operations can record a hand-over.',
            ]);
        }

        if ($handover !== null) {
            return (int) $handover->client_account_id;
        }

        $accountId = (int) ($data['client_account_id'] ?? 0);

        if ($accountId === 0 || ! ClientAccount::query()->whereKey($accountId)->exists()) {
            throw ValidationException::withMessages([
                'client_account_id' => 'Choose the dealership this hand-over is with.',
            ]);
        }

        return $accountId;
    }

    /**
     * @param  list<int>  $incoming
     * @return list<int>
     */
    private function resolveApplicationIds(int $accountId, array $incoming): array
    {
        if ($incoming === []) {
            return [];
        }

        $owned = Application::query()
            ->whereIn('id', $incoming)
            ->where('client_account_id', $accountId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (count($owned) !== count(array_unique($incoming))) {
            throw ValidationException::withMessages([
                'application_ids' => 'Choose applications from this dealership only.',
            ]);
        }

        return $owned;
    }
}
