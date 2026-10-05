<?php

namespace App\Actions;

use App\Models\BusinessClient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create or update a business client under the acting dealer's account.
 *
 * This is the single write path the dealer portal uses for the Business
 * Clients CRUD screens. Keeping it narrow guarantees:
 *   - every create and every update is audited;
 *   - client_account_id can never be flipped away from the actor's own
 *     account by crafting a payload;
 *   - encrypted PII (registration_number, proxy_id_number) is passed
 *     through Eloquent's `encrypted` casts, never written raw.
 */
class SaveBusinessClient
{
    public function __construct(private RecordAudit $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, array $data, ?BusinessClient $client = null): BusinessClient
    {
        if ($actor->client_account_id === null) {
            throw ValidationException::withMessages([
                'business_name' => 'Only a dealer user can save a business client.',
            ]);
        }

        // Edits are allowed on either (a) records on the actor's own
        // dealership, OR (b) shared records (title holders / finance
        // houses). Everything else is forbidden.
        if ($client !== null
            && $client->client_account_id !== $actor->client_account_id
            && ! $client->isShared()) {
            throw ValidationException::withMessages([
                'business_name' => 'You can only edit business clients on your own account.',
            ]);
        }

        $validated = validator($data, [
            'business_name' => ['required', 'string', 'max:160'],
            'registration_number' => ['nullable', 'string', 'max:64'],
            'proxy_name' => ['nullable', 'string', 'max:160'],
            'proxy_contact' => ['nullable', 'string', 'max:160'],
            'proxy_id_number' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:500'],
            'usable_as' => ['required', 'in:owner,title_holder,both'],
            'is_shared' => ['boolean'],
            'status' => ['required', 'in:active,inactive'],
        ])->validate();

        // Only title-holder-shaped records may be shared. Owner records
        // contain the dealer's own customer data and must never leak across
        // dealerships even if the UI tries to set is_shared=true.
        $isShared = (bool) ($validated['is_shared'] ?? false)
            && in_array($validated['usable_as'], ['title_holder', 'both'], true);

        $validated['is_shared'] = $isShared;

        return DB::transaction(function () use ($actor, $validated, $client): BusinessClient {
            $creating = $client === null;

            $before = $creating ? null : $this->snapshot($client);

            // On create: originator = actor's dealership.
            // On update of a shared record created by another dealer:
            //   preserve the originator so provenance is never lost.
            $originatingAccountId = $creating
                ? $actor->client_account_id
                : $client->client_account_id;

            $attributes = array_merge($validated, [
                'client_account_id' => $originatingAccountId,
            ]);

            if ($creating) {
                $client = BusinessClient::query()->create($attributes);
            } else {
                $client->fill($attributes)->save();
                $client->refresh();
            }

            $this->audit->handle(
                $actor,
                $client,
                $creating ? 'business_client.created' : 'business_client.updated',
                $creating
                    ? 'Business client "'.$client->business_name.'" added.'
                    : 'Business client "'.$client->business_name.'" updated.',
                $before,
                $this->snapshot($client),
            );

            return $client;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(BusinessClient $client): array
    {
        return [
            'business_name' => $client->business_name,
            'registration_number' => $client->registration_number,
            'proxy_name' => $client->proxy_name,
            'proxy_contact' => $client->proxy_contact,
            'proxy_id_number' => $client->proxy_id_number,
            'address' => $client->address,
            'usable_as' => $client->usable_as,
            'is_shared' => $client->is_shared,
            'status' => $client->status,
        ];
    }
}
