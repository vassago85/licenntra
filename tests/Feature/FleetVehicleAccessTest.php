<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\DocumentVersion;
use App\Models\FleetVehicle;
use App\Models\FleetVehicleDocument;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FleetVehicleAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_fleet_user_can_reach_vehicles_page(): void
    {
        $fleet = $this->fleet('Kestrel Logistics');
        $user = $this->fleetUser($fleet);

        $this->actingAs($user)
            ->get(route('fleet.vehicles.index'))
            ->assertOk();
    }

    public function test_dealer_client_cannot_reach_fleet_vehicles_page(): void
    {
        $dealer = ClientAccount::query()->create(['name' => 'Highveld', 'type' => 'dealer']);
        $user = $this->fleetUser($dealer);

        $this->actingAs($user)
            ->get(route('fleet.vehicles.index'))
            ->assertForbidden();
    }

    public function test_fleet_vehicles_are_isolated_per_account(): void
    {
        $kestrel = $this->fleet('Kestrel Logistics');
        $otherFleet = $this->fleet('Other Fleet');

        $this->confirmedVehicle($kestrel, 'K-ONE');
        $this->confirmedVehicle($otherFleet, 'OTHER-ONE');

        $kestrelUser = $this->fleetUser($kestrel);

        $this->actingAs($kestrelUser)
            ->get(route('fleet.vehicles.index'))
            ->assertOk()
            ->assertSee('K-ONE')
            ->assertDontSee('OTHER-ONE');
    }

    public function test_staff_can_reach_fleet_review_queue(): void
    {
        $staff = User::factory()->create(['is_active' => true]);
        $staff->assignRole('reviewer');

        $this->actingAs($staff)
            ->get(route('fleet.review.queue'))
            ->assertOk();
    }

    public function test_fleet_client_cannot_reach_fleet_review_queue(): void
    {
        $fleet = $this->fleet('Kestrel Logistics');
        $user = $this->fleetUser($fleet);

        $this->actingAs($user)
            ->get(route('fleet.review.queue'))
            ->assertForbidden();
    }

    private function fleet(string $name): ClientAccount
    {
        return ClientAccount::query()->create([
            'name' => $name,
            'type' => 'fleet_operator',
            'contact_email' => strtolower(str_replace(' ', '', $name)).'@test',
        ]);
    }

    private function fleetUser(ClientAccount $account): User
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
