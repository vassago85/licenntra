<?php

use App\Enums\ApplicationStage;
use App\Enums\DeliverableKind;
use App\Enums\VehicleCategory;
use App\Livewire\Portal\ApplicationShow;
use App\Livewire\Portal\Dashboard;
use App\Models\Application;
use App\Models\ClientAccount;
use App\Models\DeliverableDocument;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('documents');

    $this->account = ClientAccount::query()->create([
        'name' => 'Highveld Commercial Centurion',
        'type' => 'dealer',
    ]);

    $this->otherAccount = ClientAccount::query()->create([
        'name' => 'Kestrel Logistics',
        'type' => 'fleet_operator',
    ]);

    $this->application = Application::query()->create([
        'reference' => 'EXL-DEL-00001',
        'client_account_id' => $this->account->id,
        'stage' => ApplicationStage::Completed,
    ]);
});

function deliverableUser(string $role, ?int $accountId = null): User
{
    $user = User::factory()->create([
        'client_account_id' => str_starts_with($role, 'client') ? $accountId : null,
        'is_active' => true,
    ]);
    $user->assignRole($role);

    return $user;
}

function fakePdfBytes(): string
{
    return "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
}

it('lets a licensing reviewer upload a NaTIS deliverable and records an audit entry', function (): void {
    $reviewer = deliverableUser('reviewer');

    Livewire::actingAs($reviewer)
        ->test(ApplicationShow::class, ['application' => $this->application])
        ->set('newDeliverableKind', DeliverableKind::NatisCertificate->value)
        ->set('newDeliverable', UploadedFile::fake()->createWithContent('natis.pdf', fakePdfBytes()))
        ->set('newDeliverableNotes', 'Collected 12 Jul')
        ->call('storeDeliverable')
        ->assertHasNoErrors();

    $deliverable = DeliverableDocument::query()->firstOrFail();

    expect($deliverable->application_id)->toBe($this->application->id)
        ->and($deliverable->kind)->toBe(DeliverableKind::NatisCertificate)
        ->and($deliverable->uploaded_by_id)->toBe($reviewer->id)
        ->and($deliverable->handover_notes)->toBe('Collected 12 Jul');

    Storage::disk('documents')->assertExists($deliverable->storage_path);

    $this->assertDatabaseHas('audit_events', [
        'action' => 'deliverable.stored',
        'actor_user_id' => $reviewer->id,
    ]);
});

it('forbids a client user from uploading a deliverable', function (): void {
    $client = deliverableUser('client_user', $this->account->id);

    Livewire::actingAs($client)
        ->test(ApplicationShow::class, ['application' => $this->application])
        ->set('newDeliverable', UploadedFile::fake()->createWithContent('natis.pdf', fakePdfBytes()))
        ->call('storeDeliverable')
        ->assertForbidden();

    expect(DeliverableDocument::query()->count())->toBe(0);
});

it('rejects non PDF or image uploads', function (): void {
    $reviewer = deliverableUser('customer_admin');

    Livewire::actingAs($reviewer)
        ->test(ApplicationShow::class, ['application' => $this->application])
        ->set('newDeliverable', UploadedFile::fake()->create('bad.txt', 10, 'text/plain'))
        ->call('storeDeliverable')
        ->assertHasErrors(['deliverable']);

    expect(DeliverableDocument::query()->count())->toBe(0);
});

it('lets the owning dealer download a deliverable', function (): void {
    Storage::disk('documents')->put('deliverables/'.$this->application->id.'/sample.pdf', 'pdfbody');

    $deliverable = DeliverableDocument::query()->create([
        'application_id' => $this->application->id,
        'kind' => DeliverableKind::LicenceDisc,
        'storage_path' => 'deliverables/'.$this->application->id.'/sample.pdf',
        'original_filename' => 'disc.pdf',
        'mime' => 'application/pdf',
        'size_bytes' => 7,
        'uploaded_at' => now(),
    ]);

    $client = deliverableUser('client_user', $this->account->id);

    $this->actingAs($client)
        ->get(route('deliverables.download', $deliverable))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('denies a dealer from another account access to the deliverable', function (): void {
    Storage::disk('documents')->put('deliverables/'.$this->application->id.'/sample.pdf', 'pdfbody');

    $deliverable = DeliverableDocument::query()->create([
        'application_id' => $this->application->id,
        'kind' => DeliverableKind::NatisCertificate,
        'storage_path' => 'deliverables/'.$this->application->id.'/sample.pdf',
        'original_filename' => 'natis.pdf',
        'mime' => 'application/pdf',
        'size_bytes' => 7,
        'uploaded_at' => now(),
    ]);

    $stranger = deliverableUser('client_user', $this->otherAccount->id);

    $this->actingAs($stranger)
        ->get(route('deliverables.download', $deliverable))
        ->assertForbidden();
});

it('filters by Commercial and Passenger without a fatal enum error', function (): void {
    $commercial = Application::query()->create([
        'reference' => 'EXL-DEL-COMM',
        'client_account_id' => $this->account->id,
        'stage' => ApplicationStage::DocumentReview,
        'vehicle_category' => VehicleCategory::Commercial,
    ]);

    $passenger = Application::query()->create([
        'reference' => 'EXL-DEL-PASS',
        'client_account_id' => $this->account->id,
        'stage' => ApplicationStage::DocumentReview,
        'vehicle_category' => VehicleCategory::Passenger,
    ]);

    $client = deliverableUser('client_user', $this->account->id);

    Livewire::actingAs($client)
        ->test(Dashboard::class)
        ->call('setFilter', 'commercial')
        ->assertSee($commercial->reference)
        ->assertDontSee($passenger->reference)
        ->call('setFilter', 'passenger')
        ->assertSee($passenger->reference)
        ->assertDontSee($commercial->reference);
});

it('shows completed applications under the Completed filter on the dealer dashboard', function (): void {
    Application::query()->create([
        'reference' => 'EXL-DEL-OPEN',
        'client_account_id' => $this->account->id,
        'stage' => ApplicationStage::DocumentReview,
    ]);

    $client = deliverableUser('client_user', $this->account->id);

    Livewire::actingAs($client)
        ->test(Dashboard::class)
        ->assertSee('EXL-DEL-OPEN')
        ->assertDontSee('EXL-DEL-00001')
        ->call('setFilter', 'completed')
        ->assertSee('EXL-DEL-00001')
        ->assertDontSee('EXL-DEL-OPEN');
});

it('lets a licensing reviewer delete a deliverable but denies the dealer', function (): void {
    Storage::disk('documents')->put('deliverables/'.$this->application->id.'/sample.pdf', 'pdf');

    $deliverable = DeliverableDocument::query()->create([
        'application_id' => $this->application->id,
        'kind' => DeliverableKind::NatisCertificate,
        'storage_path' => 'deliverables/'.$this->application->id.'/sample.pdf',
        'original_filename' => 'natis.pdf',
        'mime' => 'application/pdf',
        'size_bytes' => 3,
        'uploaded_at' => now(),
    ]);

    $client = deliverableUser('client_user', $this->account->id);
    Livewire::actingAs($client)
        ->test(ApplicationShow::class, ['application' => $this->application])
        ->call('deleteDeliverable', $deliverable->id)
        ->assertForbidden();

    expect(DeliverableDocument::query()->whereKey($deliverable->id)->exists())->toBeTrue();

    $reviewer = deliverableUser('reviewer');
    Livewire::actingAs($reviewer)
        ->test(ApplicationShow::class, ['application' => $this->application])
        ->call('deleteDeliverable', $deliverable->id);

    expect(DeliverableDocument::query()->whereKey($deliverable->id)->exists())->toBeFalse();
});
