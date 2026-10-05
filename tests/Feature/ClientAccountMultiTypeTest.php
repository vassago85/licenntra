<?php

namespace Tests\Feature;

use App\Enums\ClientAccountType;
use App\Models\ClientAccount;
use App\Models\DocumentVersion;
use App\Models\FleetVehicle;
use App\Models\FleetVehicleDocument;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies that a single ClientAccount can play more than one role.
 * The driving example is a dealership (primary type) that also runs
 * a rental fleet (additional type): the same portal user should see
 * both the dealership sections and the fleet vehicles register.
 */
class ClientAccountMultiTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_has_type_matches_primary_and_additional_types(): void
    {
        $account = ClientAccount::query()->create([
            'name' => 'Midrand Motors',
            'type' => ClientAccountType::Dealer->value,
            'additional_types' => [ClientAccountType::FleetOperator->value],
        ]);

        $this->assertTrue($account->hasType(ClientAccountType::Dealer));
        $this->assertTrue($account->hasType(ClientAccountType::FleetOperator));
        $this->assertFalse($account->hasType(ClientAccountType::Oem));
    }

    public function test_types_returns_primary_first_then_additional_deduped(): void
    {
        $account = ClientAccount::query()->create([
            'name' => 'Midrand Motors',
            'type' => ClientAccountType::Dealer->value,
            'additional_types' => [
                ClientAccountType::FleetOperator->value,
                ClientAccountType::Dealer->value,
            ],
        ]);

        $this->assertSame(
            [ClientAccountType::Dealer, ClientAccountType::FleetOperator],
            $account->types()->all(),
        );
    }

    public function test_of_type_scope_finds_accounts_by_primary_or_additional_type(): void
    {
        $pureFleet = ClientAccount::query()->create([
            'name' => 'Kestrel Logistics',
            'type' => ClientAccountType::FleetOperator->value,
        ]);
        $dealerWithRentals = ClientAccount::query()->create([
            'name' => 'Midrand Motors',
            'type' => ClientAccountType::Dealer->value,
            'additional_types' => [ClientAccountType::FleetOperator->value],
        ]);
        ClientAccount::query()->create([
            'name' => 'Highveld Dealer Only',
            'type' => ClientAccountType::Dealer->value,
        ]);

        $fleetIds = ClientAccount::query()
            ->ofType(ClientAccountType::FleetOperator)
            ->pluck('id')
            ->all();

        sort($fleetIds);

        $expected = [$pureFleet->id, $dealerWithRentals->id];
        sort($expected);

        $this->assertSame($expected, $fleetIds);
    }

    public function test_dealer_with_fleet_capability_can_reach_fleet_vehicles_page(): void
    {
        $account = $this->dealerThatAlsoRunsAFleet();
        $this->confirmedVehicle($account, 'MID-001');

        $user = $this->clientUser($account);

        $this->actingAs($user)
            ->get(route('fleet.vehicles.index'))
            ->assertOk()
            ->assertSee('MID-001');
    }

    public function test_dealer_without_fleet_capability_still_cannot_reach_fleet_vehicles_page(): void
    {
        $account = ClientAccount::query()->create([
            'name' => 'Highveld Dealer Only',
            'type' => ClientAccountType::Dealer->value,
        ]);

        $user = $this->clientUser($account);

        $this->actingAs($user)
            ->get(route('fleet.vehicles.index'))
            ->assertForbidden();
    }

    private function dealerThatAlsoRunsAFleet(): ClientAccount
    {
        return ClientAccount::query()->create([
            'name' => 'Midrand Motors',
            'type' => ClientAccountType::Dealer->value,
            'additional_types' => [ClientAccountType::FleetOperator->value],
            'contact_email' => 'ops@midrand.test',
        ]);
    }

    private function clientUser(ClientAccount $account): User
    {
        $user = User::factory()->create([
            'client_account_id' => $account->id,
            'is_active' => true,
        ]);
        $user->assignRole('customer_user');

        return $user;
    }

    private function confirmedVehicle(ClientAccount $fleet, string $register): FleetVehicle
    {
        $vehicle = FleetVehicle::query()->create([
            'client_account_id' => $fleet->id,
            'vehicle_register_number' => $register,
            'vehicle_category' => 'commercial',
            'licence_expires_on' => now()->addMonths(2)->endOfMonth()->toDateString(),
            'licence_expiry_source' => 'typed',
        ]);

        $version = DocumentVersion::query()->create([
            'storage_path' => 'fleet-vehicles/'.$fleet->id.'/'.uniqid().'.pdf',
            'original_filename' => 'licence.pdf',
            'mime' => 'application/pdf',
            'size' => 1024,
            'sha256' => hash('sha256', $register),
            'scan_status' => 'skipped',
        ]);

        FleetVehicleDocument::query()->create([
            'fleet_vehicle_id' => $vehicle->id,
            'document_version_id' => $version->id,
            'ocr_status' => 'clean',
            'confirmed_at' => now(),
        ]);

        return $vehicle;
    }
}
