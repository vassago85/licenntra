<?php

namespace App\Actions;

use App\Enums\ApplicationStage;
use App\Enums\FuelType;
use App\Enums\OwnerType;
use App\Enums\Province;
use App\Enums\ReasonForRegistration;
use App\Enums\RequestType;
use App\Enums\ServiceType;
use App\Enums\VehicleCategory;
use App\Models\Application;
use App\Models\BrandingSetting;
use App\Models\BusinessClient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveApplicationDraft
{
    public function __construct(private RecordAudit $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, array $data, ?Application $application = null): Application
    {
        if ($actor->client_account_id === null) {
            throw ValidationException::withMessages([
                'application' => 'Only a client user can save an application draft.',
            ]);
        }

        if ($application !== null && $application->stage !== ApplicationStage::Draft && $application->stage !== ApplicationStage::ChangesRequested) {
            throw ValidationException::withMessages([
                'application' => 'This application can no longer be edited as a draft.',
            ]);
        }

        foreach (['request_type', 'service_type', 'vehicle_category', 'owner_type', 'province', 'business_client_id', 'title_holder_business_client_id', 'vin', 'vehicle_register_number', 'engine_number', 'make', 'model', 'body_type', 'owner_name', 'owner_identifier', 'owner_address', 'new_business_name', 'new_registration_number', 'new_proxy_name', 'new_proxy_id_number', 'new_address', 'new_title_holder_business_name', 'new_title_holder_registration_number', 'new_title_holder_proxy_name', 'new_title_holder_proxy_contact', 'new_title_holder_proxy_id_number', 'new_title_holder_address', 'year', 'tare_kg'] as $key) {
            if (array_key_exists($key, $data) && is_string($data[$key]) && trim($data[$key]) === '') {
                $data[$key] = null;
            }
        }

        $data = validator($data, [
            'request_type' => ['nullable', Rule::enum(RequestType::class)],
            'service_type' => ['nullable', Rule::enum(ServiceType::class)],
            'vehicle_category' => ['nullable', Rule::enum(VehicleCategory::class)],
            'owner_type' => ['nullable', Rule::enum(OwnerType::class)],
            'province' => ['nullable', Rule::enum(Province::class)],
            'is_financed' => ['boolean'],
            'dangerous_goods' => ['boolean'],
            'business_client_id' => ['nullable', 'integer'],
            'title_holder_business_client_id' => ['nullable', 'integer'],
            'vin' => ['nullable', 'string', 'max:32'],
            'vehicle_register_number' => ['nullable', 'string', 'max:32'],
            'engine_number' => ['nullable', 'string', 'max:80'],
            'make' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:80'],
            'year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'body_type' => ['nullable', 'string', 'max:80'],
            'tare_kg' => ['nullable', 'integer', 'min:0'],
            'owner_name' => ['nullable', 'string', 'max:160'],
            'owner_identifier' => ['nullable', 'string', 'max:32'],
            'owner_address' => ['nullable', 'string', 'max:500'],
            'new_business_name' => ['nullable', 'string', 'max:160'],
            'new_registration_number' => ['nullable', 'string', 'max:64'],
            'new_proxy_name' => ['nullable', 'string', 'max:160'],
            'new_proxy_id_number' => ['nullable', 'string', 'max:32'],
            'new_address' => ['nullable', 'string', 'max:500'],
            'new_title_holder_business_name' => ['nullable', 'string', 'max:160'],
            'new_title_holder_registration_number' => ['nullable', 'string', 'max:64'],
            'new_title_holder_proxy_name' => ['nullable', 'string', 'max:160'],
            'new_title_holder_proxy_contact' => ['nullable', 'string', 'max:160'],
            'new_title_holder_proxy_id_number' => ['nullable', 'string', 'max:32'],
            'new_title_holder_address' => ['nullable', 'string', 'max:500'],
        ])->validate();

        return DB::transaction(function () use ($actor, $data, $application): Application {
            $creating = $application === null;

            $application ??= new Application([
                'reference' => $this->nextReference(),
                'client_account_id' => $actor->client_account_id,
                'stage' => ApplicationStage::Draft,
            ]);

            $businessClientId = $this->resolveBusinessClient($actor, $data, $application);
            $titleHolderId = $this->resolveTitleHolder($actor, $data, $application);

            $application->fill([
                'request_type' => $data['request_type'] ?? null,
                'service_type' => $data['service_type'] ?? null,
                'vehicle_category' => $data['vehicle_category'] ?? null,
                'owner_type' => $data['owner_type'] ?? null,
                'province' => $data['province'] ?? null,
                'is_financed' => (bool) ($data['is_financed'] ?? false),
                'dangerous_goods' => (bool) ($data['dangerous_goods'] ?? false),
                'business_client_id' => $businessClientId,
                'title_holder_business_client_id' => $titleHolderId,
            ]);
            $application->save();

            $vehicleAttributes = [
                'vin' => $data['vin'] ?? null,
                'vehicle_register_number' => $data['vehicle_register_number'] ?? null,
                'engine_number' => $data['engine_number'] ?? null,
                'make' => $data['make'] ?? null,
                'model' => $data['model'] ?? null,
                'year' => $data['year'] ?? null,
                'body_type' => $data['body_type'] ?? null,
                'tare_kg' => $data['tare_kg'] ?? null,
            ];

            $vehicle = $application->vehicle;
            $isNewVehicle = $vehicle === null;

            if ($isNewVehicle && $application->vehicle_category !== null) {
                // Dealership-submitted drafts: pre-fill the two fields whose
                // "right" default varies by context. The reviewer can
                // override before the submission pack is marked ready.
                $vehicleAttributes['fuel_type'] = FuelType::defaultFor($application->vehicle_category)->value;

                if ($application->request_type !== null) {
                    $vehicleAttributes['reason_for_registration'] = ReasonForRegistration::defaultFor($application->request_type)->value;
                }
            }

            $application->vehicle()->updateOrCreate([], $vehicleAttributes);

            $this->syncOwnerParty($application, $data);

            app(ResolveRequiredDocuments::class)->handle($application);

            $this->audit->handle(
                $actor,
                $application,
                $creating ? 'application.draft_created' : 'application.draft_saved',
                $creating ? 'Application draft created.' : 'Application draft saved.',
                null,
                ['reference' => $application->reference],
            );

            return $application->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveBusinessClient(User $actor, array $data, Application $application): ?int
    {
        $name = trim((string) ($data['new_business_name'] ?? ''));

        if ($name !== '') {
            $client = BusinessClient::query()->create([
                'client_account_id' => $actor->client_account_id,
                'business_name' => $name,
                'registration_number' => $data['new_registration_number'] ?? null,
                'proxy_name' => $data['new_proxy_name'] ?? null,
                'proxy_id_number' => $data['new_proxy_id_number'] ?? null,
                'address' => $data['new_address'] ?? null,
                'usable_as' => 'owner',
                'status' => 'active',
            ]);

            return $client->id;
        }

        return $this->ownedBusinessClientId($data['business_client_id'] ?? $application->business_client_id);
    }

    /**
     * Mirror of resolveBusinessClient for the title holder slot. If the
     * caller passed inline "new title holder" fields we create a dedicated
     * BusinessClient (usable_as = title_holder) scoped to the actor's
     * dealership. Otherwise fall back to the chosen existing title holder.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveTitleHolder(User $actor, array $data, Application $application): ?int
    {
        $name = trim((string) ($data['new_title_holder_business_name'] ?? ''));

        if ($name !== '') {
            $client = BusinessClient::query()->create([
                'client_account_id' => $actor->client_account_id,
                'business_name' => $name,
                'registration_number' => $data['new_title_holder_registration_number'] ?? null,
                'proxy_name' => $data['new_title_holder_proxy_name'] ?? null,
                'proxy_contact' => $data['new_title_holder_proxy_contact'] ?? null,
                'proxy_id_number' => $data['new_title_holder_proxy_id_number'] ?? null,
                'address' => $data['new_title_holder_address'] ?? null,
                'usable_as' => 'title_holder',
                'status' => 'active',
            ]);

            return $client->id;
        }

        return $this->ownedBusinessClientId($data['title_holder_business_client_id'] ?? $application->title_holder_business_client_id);
    }

    private function ownedBusinessClientId(mixed $id): ?int
    {
        if ($id === null || $id === '') {
            return null;
        }

        $client = BusinessClient::query()->find((int) $id);

        if ($client === null) {
            throw ValidationException::withMessages([
                'business_client_id' => 'Choose a business client on this account.',
            ]);
        }

        return $client->id;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncOwnerParty(Application $application, array $data): void
    {
        if ($application->owner_type === OwnerType::Business && $application->business_client_id) {
            $client = $application->businessClient;

            $application->parties()->updateOrCreate(['role' => 'owner'], [
                'party_type' => 'business',
                'name' => $client?->business_name,
                'identifier' => $client?->registration_number,
                'address' => $client?->address,
                'business_client_id' => $client?->id,
            ]);

            return;
        }

        if ($application->owner_type === OwnerType::Individual) {
            $application->parties()->updateOrCreate(['role' => 'owner'], [
                'party_type' => 'individual',
                'name' => $data['owner_name'] ?? null,
                'identifier' => $data['owner_identifier'] ?? null,
                'address' => $data['owner_address'] ?? null,
                'business_client_id' => null,
            ]);
        }
    }

    private function nextReference(): string
    {
        $prefix = BrandingSetting::current()->reference_prefix ?: 'LIC';
        $year = now()->format('Y');
        $sequence = Application::withoutGlobalScopes()->count() + 1;

        do {
            $reference = sprintf('%s-%s-%05d', $prefix, $year, $sequence);
            $sequence++;
        } while (Application::withoutGlobalScopes()->where('reference', $reference)->exists());

        return $reference;
    }
}
