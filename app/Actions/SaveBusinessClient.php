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

        if ($client !== null && $client->client_account_id !== $actor->client_account_id) {
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
            'status' => ['required', 'in:active,inactive'],
        ])->validate();

        return DB::transaction(function () use ($actor, $validated, $client): BusinessClient {
            $creating = $client === null;

            $before = $creating ? null : $this->snapshot($client);

            $attributes = array_merge($validated, [
                'client_account_id' => $actor->client_account_id,
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
            'status' => $client->status,
        ];
    }
}
