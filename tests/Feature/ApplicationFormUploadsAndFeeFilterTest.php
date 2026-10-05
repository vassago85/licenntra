<?php

use App\Actions\CalculateFees;
use App\Actions\SaveApplicationDraft;
use App\Enums\LicenceFeeCategory;
use App\Enums\OwnerType;
use App\Enums\Province;
use App\Enums\RequestType;
use App\Enums\ServiceType;
use App\Enums\VehicleCategory;
use App\Livewire\Portal\ApplicationForm;
use App\Models\ApplicationDocument;
use App\Models\ClientAccount;
use App\Models\FeeTable;
use App\Models\User;
use Database\Seeders\DocumentRuleSeeder;
use Database\Seeders\FeeTableSeeder;
use Database\Seeders\LicenceFeeBandSeeder;
use Database\Seeders\LicenceFeeRateSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(DocumentRuleSeeder::class);
    $this->seed(FeeTableSeeder::class);
    $this->seed(LicenceFeeBandSeeder::class);
    $this->seed(LicenceFeeRateSeeder::class);

    FeeTable::query()->where('province', Province::Gauteng->value)
        ->firstOrFail()
        ->versions()->where('status', 'draft')->update(['status' => 'active']);

    $this->dealer = ClientAccount::query()->create([
        'name' => 'Highveld Commercial Centurion',
        'type' => 'dealer',
        'status' => 'active',
        'markup_basis_points' => 0,
    ]);

    $this->user = User::factory()->create([
        'client_account_id' => $this->dealer->id,
        'is_active' => true,
    ]);
    $this->user->assignRole('client_user');
});

it('prices a commercial truck under exactly one licence band - not fifteen', function (): void {
    $application = app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::NewRegistration->value,
        'service_type' => ServiceType::RegisterAndLicense->value,
        'vehicle_category' => VehicleCategory::Commercial->value,
        'licence_category' => LicenceFeeCategory::MotorCar->value,
        'province' => Province::Gauteng->value,
        'owner_type' => OwnerType::Individual->value,
        'owner_name' => 'Paul Charsley',
        'tare_kg' => 6578,
    ]);

    $snapshot = app(CalculateFees::class)->snapshot($application);

    // All licence-fee lines should be the single MotorCar band for 6 501-6 750 kg,
    // plus the national RTMC pass-through. Not a line for every band in every
    // category in the gazette.
    $licenceLines = collect($snapshot['lines'])->where('code', 'licence');

    expect($licenceLines->count())->toBe(1, 'exactly one licence band should match');
    expect($licenceLines->first()['label'])->toContain('Rigid vehicle');
});

it('defaults the licence category to Rigid vehicle (MotorCar) when the dealer did not pick one', function (): void {
    $application = app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::NewRegistration->value,
        'service_type' => ServiceType::RegisterAndLicense->value,
        'vehicle_category' => VehicleCategory::Commercial->value,
        'province' => Province::Gauteng->value,
        'owner_type' => OwnerType::Individual->value,
        'owner_name' => 'Paul Charsley',
        'tare_kg' => 1500,
    ]);

    expect($application->licence_category)->toBe(LicenceFeeCategory::MotorCar);
});

it('switches the matched band when the dealer picks Trailer instead of Rigid vehicle', function (): void {
    $application = app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::NewRegistration->value,
        'service_type' => ServiceType::RegisterAndLicense->value,
        'vehicle_category' => VehicleCategory::Commercial->value,
        'licence_category' => LicenceFeeCategory::Trailer->value,
        'province' => Province::Gauteng->value,
        'owner_type' => OwnerType::Individual->value,
        'owner_name' => 'Paul Charsley',
        'tare_kg' => 2500,
    ]);

    $snapshot = app(CalculateFees::class)->snapshot($application);
    $licenceLines = collect($snapshot['lines'])->where('code', 'licence');

    expect($licenceLines->count())->toBe(1)
        ->and($licenceLines->first()['label'])->toContain('Trailer');
});

it('still includes the R72 RTMC transaction fee on top of the one licence band', function (): void {
    $application = app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::NewRegistration->value,
        'service_type' => ServiceType::RegisterAndLicense->value,
        'vehicle_category' => VehicleCategory::Commercial->value,
        'licence_category' => LicenceFeeCategory::MotorCar->value,
        'province' => Province::Gauteng->value,
        'owner_type' => OwnerType::Individual->value,
        'owner_name' => 'Paul Charsley',
        'tare_kg' => 1500,
    ]);

    $snapshot = app(CalculateFees::class)->snapshot($application);

    $rtmc = collect($snapshot['lines'])->firstWhere('code', 'rtmc_transaction_fee');
    expect($rtmc)->not->toBeNull()
        ->and($rtmc['amount_cents'])->toBe(7200);
});

it('lets a client user upload a required document straight from the application form', function (): void {
    Storage::fake('documents');

    $application = app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::NewRegistration->value,
        'service_type' => ServiceType::RegisterAndLicense->value,
        'vehicle_category' => VehicleCategory::Commercial->value,
        'licence_category' => LicenceFeeCategory::MotorCar->value,
        'province' => Province::Gauteng->value,
        'owner_type' => OwnerType::Individual->value,
        'owner_name' => 'Paul Charsley',
        'tare_kg' => 1500,
    ]);

    /** @var ApplicationDocument $document */
    $document = $application->documents()->firstOrFail();

    // Real PDF bytes so finfo recognises the mime - mirrors the pattern
    // used in InvoiceTest and DeliverableDocumentTest.
    $pdfBytes = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

    Livewire::actingAs($this->user)
        ->test(ApplicationForm::class, ['application' => $application])
        ->set("uploads.{$document->id}", UploadedFile::fake()->createWithContent('weighbridge.pdf', $pdfBytes))
        ->call('upload', $document->id);

    expect($document->fresh()->currentVersion)->not->toBeNull()
        ->and($document->fresh()->currentVersion->original_filename)->toBe('weighbridge.pdf');
});

it('rejects a non-PDF/JPG/PNG upload with a clear message', function (): void {
    Storage::fake('documents');

    $application = app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::NewRegistration->value,
        'service_type' => ServiceType::RegisterAndLicense->value,
        'vehicle_category' => VehicleCategory::Commercial->value,
        'licence_category' => LicenceFeeCategory::MotorCar->value,
        'province' => Province::Gauteng->value,
        'owner_type' => OwnerType::Individual->value,
        'owner_name' => 'Paul Charsley',
        'tare_kg' => 1500,
    ]);

    $document = $application->documents()->firstOrFail();

    Livewire::actingAs($this->user)
        ->test(ApplicationForm::class, ['application' => $application])
        ->set("uploads.{$document->id}", UploadedFile::fake()->create('malware.exe', 10, 'application/octet-stream'))
        ->call('upload', $document->id)
        ->assertHasErrors(['upload']);

    expect($document->fresh()->currentVersion)->toBeNull();
});
