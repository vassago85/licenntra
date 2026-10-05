<?php

namespace Tests\Feature;

use App\Enums\RequestType;
use App\Enums\VehicleCategory;
use App\Models\Application;
use App\Models\ClientAccount;
use App\Models\DocumentType;
use App\Models\FleetVehicle;
use App\Models\User;
use Database\Seeders\DocumentRuleSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FleetRenewalPrefillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(DocumentRuleSeeder::class);
    }

    public function test_prefill_link_creates_renewal_draft_from_fleet_vehicle(): void
    {
        $fleet = ClientAccount::query()->create([
            'name' => 'Kestrel Logistics',
            'type' => 'fleet_operator',
        ]);

        $user = User::factory()->create([
            'client_account_id' => $fleet->id,
            'is_active' => true,
        ]);
        $user->assignRole('customer_admin');

        $vehicle = FleetVehicle::query()->create([
            'client_account_id' => $fleet->id,
            'vehicle_register_number' => 'BNT370X',
            'vin' => 'ACVRREHR8K4047295',
            'make' => 'Isuzu',
            'model' => 'D-Max',
            'vehicle_category' => VehicleCategory::Commercial->value,
            'licence_expires_on' => '2027-06-30',
            'licence_expiry_source' => 'typed',
        ]);

        $this->actingAs($user)
            ->get(route('applications.create', ['prefill_fleet_vehicle' => $vehicle->id]))
            ->assertRedirect();

        $application = Application::query()->where('client_account_id', $fleet->id)->firstOrFail();

        $this->assertSame(RequestType::LicenceRenewal, $application->request_type);
        $this->assertSame(VehicleCategory::Commercial, $application->vehicle_category);
        $this->assertNotNull($application->vehicle);
        $this->assertSame('BNT370X', $application->vehicle->vehicle_register_number);
        $this->assertSame('ACVRREHR8K4047295', $application->vehicle->vin);
        $this->assertSame('Isuzu', $application->vehicle->make);
    }

    public function test_commercial_licence_renewal_requires_a_certificate_of_fitness(): void
    {
        $cof = DocumentType::query()->where('code', 'cof')->firstOrFail();

        $this->assertDatabaseHas('document_rules', [
            'document_type_id' => $cof->id,
            'request_type' => 'licence_renewal',
            'vehicle_category' => 'commercial',
            'requirement' => 'required',
            'active' => true,
        ]);
    }
}
