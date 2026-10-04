<?php

namespace Tests\Feature;

use App\Actions\ConfirmFleetVehicle;
use App\Jobs\SendFleetRenewalReminders;
use App\Models\ClientAccount;
use App\Models\DocumentVersion;
use App\Models\FleetVehicle;
use App\Models\FleetVehicleDocument;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\NotificationDispatcher;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class FleetVehicleRetirementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        SystemSetting::query()->updateOrCreate(['id' => 1], ['notifications_enabled' => true]);
    }

    public function test_retired_vehicle_is_still_listed_but_excluded_from_reminder(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 1, 6, 0, 0));
        Notification::fake();

        $fleet = ClientAccount::query()->create([
            'name' => 'Kestrel Logistics',
            'type' => 'fleet_operator',
            'contact_email' => 'ops@kestrel.test',
        ]);

        $vehicle = $this->confirmedVehicle($fleet, '2026-06-30', 'BZG369X');
        $vehicle->update(['retired_at' => now()]);

        (new SendFleetRenewalReminders)->handle(app(NotificationDispatcher::class));

        Notification::assertNothingSent();

        // Still exists on the file for downloads.
        $this->assertSame(1, FleetVehicle::query()
            ->withoutGlobalScopes()
            ->whereNotNull('retired_at')
            ->where('client_account_id', $fleet->id)
            ->count());
    }

    private function confirmedVehicle(ClientAccount $fleet, string $expiryDate, string $register): FleetVehicle
    {
        $reviewer = User::factory()->create(['is_active' => true]);
        $reviewer->assignRole('reviewer');

        $vehicle = FleetVehicle::query()->create([
            'client_account_id' => $fleet->id,
            'vehicle_register_number' => $register,
            'vin' => 'ACV'.strtoupper(bin2hex(random_bytes(7))),
            'vehicle_category' => 'commercial',
        ]);

        $version = DocumentVersion::query()->create([
            'storage_path' => 'fleet-vehicles/'.$fleet->id.'/'.uniqid().'.pdf',
            'original_filename' => 'licence.pdf',
            'mime' => 'application/pdf',
            'size' => 1024,
            'sha256' => hash('sha256', $register),
            'scan_status' => 'skipped',
        ]);

        $document = FleetVehicleDocument::query()->create([
            'fleet_vehicle_id' => $vehicle->id,
            'document_version_id' => $version->id,
            'ocr_status' => 'clean',
        ]);

        app(ConfirmFleetVehicle::class)->handle($document, $reviewer, [
            'vehicle_register_number' => $register,
            'vin' => $vehicle->vin,
            'make' => 'Isuzu',
            'model' => 'F-Series',
            'vehicle_category' => 'commercial',
            'licence_expires_on' => $expiryDate,
        ]);

        return $vehicle->refresh();
    }
}
