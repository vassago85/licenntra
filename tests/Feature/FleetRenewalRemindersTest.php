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
use App\Notifications\FleetRenewalReminder;
use App\Services\NotificationDispatcher;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class FleetRenewalRemindersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        SystemSetting::query()->updateOrCreate(['id' => 1], ['notifications_enabled' => true]);
    }

    public function test_reminder_runs_only_on_first_of_month_and_lists_expiring_vehicles(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 1, 6, 0, 0));
        Notification::fake();

        $fleet = $this->fleetWithContact('Kestrel Logistics', 'ops@kestrel.test');
        $this->confirmedVehicle($fleet, '2026-06-30', 'BZG369X');
        $this->confirmedVehicle($fleet, '2026-07-15', 'BZG370X'); // next month, must be skipped
        $this->retiredVehicle($fleet, '2026-06-20', 'BZG371X');   // retired, must be skipped

        (new SendFleetRenewalReminders)->handle(app(NotificationDispatcher::class));

        Notification::assertSentOnDemand(FleetRenewalReminder::class, function (FleetRenewalReminder $notification): bool {
            return $notification->vehicles->count() === 1
                && $notification->vehicles->first()->vehicle_register_number === 'BZG369X';
        });
    }

    public function test_reminder_does_nothing_on_other_days(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 6, 0, 0));
        Notification::fake();

        $fleet = $this->fleetWithContact('Kestrel Logistics', 'ops@kestrel.test');
        $this->confirmedVehicle($fleet, '2026-06-30', 'BZG369X');

        (new SendFleetRenewalReminders)->handle(app(NotificationDispatcher::class));

        Notification::assertNothingSent();
    }

    public function test_fleet_with_no_expiring_vehicles_receives_nothing(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 1, 6, 0, 0));
        Notification::fake();

        $fleet = $this->fleetWithContact('Empty Fleet', 'ops@empty.test');
        $this->confirmedVehicle($fleet, '2026-07-15', 'BZG370X');

        (new SendFleetRenewalReminders)->handle(app(NotificationDispatcher::class));

        Notification::assertNothingSent();
    }

    private function fleetWithContact(string $name, string $email): ClientAccount
    {
        return ClientAccount::query()->create([
            'name' => $name,
            'type' => 'fleet_operator',
            'contact_email' => $email,
        ]);
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

    private function retiredVehicle(ClientAccount $fleet, string $expiryDate, string $register): FleetVehicle
    {
        $vehicle = $this->confirmedVehicle($fleet, $expiryDate, $register);
        $vehicle->update(['retired_at' => now()]);

        return $vehicle;
    }
}
