<?php

namespace Tests\Feature;

use App\Actions\UploadFleetLicence;
use App\Enums\FleetVehicleOcrStatus;
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
use Tests\TestCase;

class FleetVehicleUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Storage::fake('documents');
    }

    public function test_upload_creates_a_pending_vehicle_and_queues_ocr(): void
    {
        Queue::fake();

        [$fleet, $reviewer] = $this->fleetAndReviewer();

        $document = app(UploadFleetLicence::class)->handle(
            $fleet,
            $this->fakePdfUpload(),
            $reviewer,
        );

        $this->assertNull($document->confirmed_at);
        $this->assertSame(FleetVehicleOcrStatus::Pending, $document->ocr_status);
        $this->assertSame($reviewer->id, $document->uploaded_by_id);

        $vehicle = $document->fleetVehicle;
        $this->assertSame($fleet->id, $vehicle->client_account_id);
        $this->assertNull($vehicle->licence_expires_on);

        Queue::assertPushed(ReadFleetLicence::class);
    }

    public function test_upload_rejects_non_image_mime(): void
    {
        [$fleet, $reviewer] = $this->fleetAndReviewer();

        $this->expectExceptionMessage('Upload a PDF, JPG, or PNG.');

        app(UploadFleetLicence::class)->handle(
            $fleet,
            UploadedFile::fake()->createWithContent('badge.txt', 'definitely not a pdf'),
            $reviewer,
        );
    }

    public function test_read_fleet_licence_job_fills_candidates_from_ocr(): void
    {
        [$fleet, $reviewer] = $this->fleetAndReviewer();

        $document = app(UploadFleetLicence::class)->handle(
            $fleet,
            $this->fakePdfUpload(),
            $reviewer,
        );

        $fake = new FakeLicenceOcrReader;
        $fake->setDefault(LicenceOcrResult::clean('2026-05-31', 'BZG369X', 'ACVFTR34H8N042357'));
        $this->app->instance(LicenceOcrReader::class, $fake);

        (new ReadFleetLicence($document->id))->handle(
            $this->app->make(LicenceOcrReader::class),
            $this->app->make(NotificationDispatcher::class),
        );

        $document->refresh();
        $this->assertSame(FleetVehicleOcrStatus::Clean, $document->ocr_status);
        $this->assertSame('2026-05-31', $document->ocr_expiry_candidate->format('Y-m-d'));
        $this->assertSame('BZG369X', $document->ocr_register_candidate);
        $this->assertSame('ACVFTR34H8N042357', $document->ocr_vin_candidate);
    }

    public function test_read_fleet_licence_marks_unreadable_when_nothing_is_found(): void
    {
        [$fleet, $reviewer] = $this->fleetAndReviewer();

        $document = app(UploadFleetLicence::class)->handle(
            $fleet,
            $this->fakePdfUpload(),
            $reviewer,
        );

        $fake = new FakeLicenceOcrReader;
        $fake->setDefault(LicenceOcrResult::unreadable('No fields found.'));
        $this->app->instance(LicenceOcrReader::class, $fake);

        (new ReadFleetLicence($document->id))->handle(
            $this->app->make(LicenceOcrReader::class),
            $this->app->make(NotificationDispatcher::class),
        );

        $document->refresh();
        $this->assertSame(FleetVehicleOcrStatus::Unreadable, $document->ocr_status);
        $this->assertNull($document->ocr_expiry_candidate);
    }

    public function test_pending_vehicle_is_hidden_from_fleet_list(): void
    {
        [$fleet, $reviewer] = $this->fleetAndReviewer();

        app(UploadFleetLicence::class)->handle(
            $fleet,
            $this->fakePdfUpload(),
            $reviewer,
        );

        $visible = FleetVehicle::query()
            ->withoutGlobalScopes()
            ->where('client_account_id', $fleet->id)
            ->confirmed()
            ->count();

        $this->assertSame(0, $visible, 'An unconfirmed vehicle must not appear in the fleet list.');
        $this->assertSame(1, FleetVehicleDocument::query()->count());
    }

    private function fakePdfUpload(string $filename = 'licence.pdf'): UploadedFile
    {
        // Minimal PDF bytes that finfo recognises as application/pdf.
        return UploadedFile::fake()->createWithContent(
            $filename,
            "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n",
        );
    }

    /**
     * @return array{0: ClientAccount, 1: User}
     */
    private function fleetAndReviewer(): array
    {
        $fleet = ClientAccount::query()->create([
            'name' => 'Kestrel Logistics',
            'type' => 'fleet_operator',
            'contact_email' => 'ops@kestrel.test',
        ]);

        $reviewer = User::factory()->create(['is_active' => true]);
        $reviewer->assignRole('reviewer');

        return [$fleet, $reviewer];
    }
}
