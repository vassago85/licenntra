<?php

namespace Tests\Feature;

use App\Actions\ConfirmFleetVehicle;
use App\Actions\UploadFleetLicence;
use App\Enums\LicenceExpirySource;
use App\Jobs\ReadFleetLicence;
use App\Models\ClientAccount;
use App\Models\FleetVehicle;
use App\Models\FleetVehicleDocument;
use App\Models\User;
use App\Services\LicenceOcr\FakeLicenceOcrReader;
use App\Services\LicenceOcr\LicenceOcrReader;
use App\Services\LicenceOcr\LicenceOcrResult;
use App\Services\NotificationDispatcher;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FleetVehicleConfirmationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Storage::fake('documents');
    }

    public function test_confirmation_saves_fields_and_marks_source_as_scan_when_matching_ocr(): void
    {
        [$fleet, $reviewer, $document] = $this->uploadAndRead(
            LicenceOcrResult::clean('2026-05-31', 'BZG369X', 'ACVFTR34H8N042357'),
        );

        $vehicle = app(ConfirmFleetVehicle::class)->handle($document, $reviewer, [
            'vehicle_register_number' => 'BZG369X',
            'vin' => 'ACVFTR34H8N042357',
            'make' => 'Isuzu',
            'model' => 'F-Series',
            'vehicle_category' => 'commercial',
            'licence_expires_on' => '2026-05-31',
        ]);

        $vehicle->refresh();
        $this->assertSame('2026-05-31', $vehicle->licence_expires_on->format('Y-m-d'));
        $this->assertSame(LicenceExpirySource::Scan, $vehicle->licence_expiry_source);

        $this->assertSame(1, FleetVehicle::query()->confirmed()->count());
    }

    public function test_confirmation_marks_source_as_typed_when_reviewer_changes_expiry(): void
    {
        [$fleet, $reviewer, $document] = $this->uploadAndRead(
            LicenceOcrResult::clean('2026-05-31', 'BZG369X', 'ACVFTR34H8N042357'),
        );

        $vehicle = app(ConfirmFleetVehicle::class)->handle($document, $reviewer, [
            'vehicle_register_number' => 'BZG369X',
            'vin' => 'ACVFTR34H8N042357',
            'make' => 'Isuzu',
            'model' => 'F-Series',
            'vehicle_category' => 'commercial',
            'licence_expires_on' => '2026-06-30',
        ]);

        $this->assertSame(LicenceExpirySource::Typed, $vehicle->refresh()->licence_expiry_source);
    }

    public function test_confirmation_rejects_duplicate_register_within_same_fleet(): void
    {
        [$fleet, $reviewer, $firstDocument] = $this->uploadAndRead(
            LicenceOcrResult::clean('2026-05-31', 'BZG369X', 'ACVFTR34H8N042357'),
        );
        app(ConfirmFleetVehicle::class)->handle($firstDocument, $reviewer, [
            'vehicle_register_number' => 'BZG369X',
            'vin' => 'ACVFTR34H8N042357',
            'make' => 'Isuzu',
            'model' => 'F-Series',
            'vehicle_category' => 'commercial',
            'licence_expires_on' => '2026-05-31',
        ]);

        // Second upload for the SAME fleet with the SAME register number.
        Queue::fake();
        $second = app(UploadFleetLicence::class)->handle(
            $fleet,
            $this->fakePdfUpload('licence2.pdf'),
            $reviewer,
        );

        $this->expectException(ValidationException::class);

        app(ConfirmFleetVehicle::class)->handle($second, $reviewer, [
            'vehicle_register_number' => 'BZG369X',
            'vin' => 'ACVFTR34H8N042357',
            'make' => 'Isuzu',
            'model' => 'F-Series',
            'vehicle_category' => 'commercial',
            'licence_expires_on' => '2026-05-31',
        ]);
    }

    public function test_confirmation_allows_missing_expiry_but_vehicle_is_not_confirmed(): void
    {
        [$fleet, $reviewer, $document] = $this->uploadAndRead(
            LicenceOcrResult::unreadable('No expiry.'),
        );

        $vehicle = app(ConfirmFleetVehicle::class)->handle($document, $reviewer, [
            'vehicle_register_number' => 'BZG369X',
            'vin' => 'ACVFTR34H8N042357',
            'make' => 'Isuzu',
            'model' => 'F-Series',
            'vehicle_category' => 'commercial',
            'licence_expires_on' => null,
        ]);

        $vehicle->refresh();
        $this->assertNull($vehicle->licence_expires_on);
        $this->assertNull($vehicle->licence_expiry_source);
        // Document is still marked confirmed_at, so the vehicle is on the
        // fleet list, but it won't trigger a reminder.
        $this->assertNotNull($document->refresh()->confirmed_at);
    }

    /**
     * @return array{0: ClientAccount, 1: User, 2: FleetVehicleDocument}
     */
    private function uploadAndRead(LicenceOcrResult $ocr): array
    {
        $fleet = ClientAccount::query()->create([
            'name' => 'Kestrel Logistics',
            'type' => 'fleet_operator',
            'contact_email' => 'ops@kestrel.test',
        ]);

        $reviewer = User::factory()->create(['is_active' => true]);
        $reviewer->assignRole('reviewer');

        $document = app(UploadFleetLicence::class)->handle(
            $fleet,
            $this->fakePdfUpload(),
            $reviewer,
        );

        $fake = new FakeLicenceOcrReader;
        $fake->setDefault($ocr);
        $this->app->instance(LicenceOcrReader::class, $fake);

        (new ReadFleetLicence($document->id))->handle(
            $this->app->make(LicenceOcrReader::class),
            $this->app->make(NotificationDispatcher::class),
        );

        return [$fleet, $reviewer, $document->refresh()];
    }

    private function fakePdfUpload(string $filename = 'licence.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $filename,
            "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n",
        );
    }
}
