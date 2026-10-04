<?php

namespace App\Actions;

use App\Enums\OwnerType;
use App\Models\Application;
use App\Models\BusinessClient;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class LinkBusinessClientToApplication
{
    public function __construct(private RecordAudit $audit) {}

    public function handle(Application $application, BusinessClient $businessClient, User $actor, string $role = 'owner'): Application
    {
        if (! in_array($role, ['owner', 'title_holder'], true)) {
            throw ValidationException::withMessages([
                'role' => 'Link the business as owner or title holder.',
            ]);
        }

        if ($application->client_account_id !== $businessClient->client_account_id) {
            throw ValidationException::withMessages([
                'business_client' => 'That business client belongs to another account.',
            ]);
        }

        if ($role === 'title_holder') {
            $application->title_holder_business_client_id = $businessClient->id;
            $application->is_financed = true;
        } else {
            $application->business_client_id = $businessClient->id;
            $application->owner_type = OwnerType::Business;
        }

        $application->save();

        $application->parties()->updateOrCreate(['role' => $role], [
            'party_type' => 'business',
            'name' => $businessClient->business_name,
            'identifier' => $businessClient->registration_number,
            'address' => $businessClient->address,
            'business_client_id' => $businessClient->id,
        ]);

        app(ResolveRequiredDocuments::class)->handle($application);

        $this->audit->handle(
            $actor,
            $application,
            'application.business_client_linked',
            'Business client linked to the application.',
            null,
            ['role' => $role, 'business_client_id' => $businessClient->id],
        );

        return $application->refresh();
    }
}
