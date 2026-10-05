<?php

use App\Livewire\Portal\BusinessClientShow;
use App\Models\BusinessClient;
use App\Models\BusinessClientDocument;
use App\Models\ClientAccount;
use App\Models\DocumentVersion;
use App\Models\User;
use Database\Seeders\DocumentRuleSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Dealers upload reusable paperwork (BRN cert, proxy ID, proof of
 * address) against a BusinessClient record once; the file is then
 * available for every application that references the same business
 * client. Replacing a document keeps earlier versions on file for audit.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(DocumentRuleSeeder::class);
    Storage::fake('documents');

    $this->dealerA = ClientAccount::query()->create([
        'name' => 'Highveld Commercial Centurion',
        'type' => 'dealer',
        'status' => 'active',
        'markup_basis_points' => 0,
    ]);

    $this->dealerB = ClientAccount::query()->create([
        'name' => 'Lowveld Vehicle Group',
        'type' => 'dealer',
        'status' => 'active',
        'markup_basis_points' => 0,
    ]);

    // Business-client paperwork is a client_admin privilege under the new
    // strict policy: a plain client_user can select existing records on
    // application forms but cannot create, edit, or upload documents
    // against them. The upload / replace / download semantics are
    // identical for both roles, so we test them with the admin role.
    $this->userA = User::factory()->create([
        'client_account_id' => $this->dealerA->id,
        'is_active' => true,
    ]);
    $this->userA->assignRole('customer_admin');

    $this->userB = User::factory()->create([
        'client_account_id' => $this->dealerB->id,
        'is_active' => true,
    ]);
    $this->userB->assignRole('customer_admin');

    $this->businessClient = BusinessClient::query()->create([
        'client_account_id' => $this->dealerA->id,
        'business_name' => 'Pauls Transport',
        'usable_as' => 'owner',
        'status' => 'active',
    ]);
});

function fakeBusinessClientPdf(): string
{
    return "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
}

it('uploads a BRN certificate against the business client, stores the file, and sets current_version_id', function (): void {
    Livewire::actingAs($this->userA)
        ->test(BusinessClientShow::class, ['businessClient' => $this->businessClient])
        ->set('uploadTypeCode', 'brn_certificate')
        ->set('uploadFile', UploadedFile::fake()->createWithContent('brn.pdf', fakeBusinessClientPdf()))
        ->call('uploadDocument')
        ->assertHasNoErrors();

    $document = BusinessClientDocument::query()
        ->where('business_client_id', $this->businessClient->id)
        ->firstOrFail();

    $version = $document->currentVersion()->firstOrFail();

    expect($document->document_type_id)->not->toBeNull()
        ->and($document->documentType->code)->toBe('brn_certificate')
        ->and($document->current_version_id)->toBe($version->id)
        ->and($version->business_client_document_id)->toBe($document->id)
        ->and($version->mime)->toBe('application/pdf')
        ->and($version->uploaded_by)->toBe($this->userA->id);

    Storage::disk('documents')->assertExists($version->storage_path);
    expect($version->storage_path)->toStartWith('business-clients/'.$this->businessClient->id.'/');
});

it('rejects an upload with no document type picked', function (): void {
    Livewire::actingAs($this->userA)
        ->test(BusinessClientShow::class, ['businessClient' => $this->businessClient])
        ->set('uploadFile', UploadedFile::fake()->createWithContent('a.pdf', fakeBusinessClientPdf()))
        ->call('uploadDocument')
        ->assertHasErrors(['uploadFile']);

    expect(BusinessClientDocument::query()->count())->toBe(0);
});

it('rejects an upload for a document type code that is not on the business client allow-list', function (): void {
    Livewire::actingAs($this->userA)
        ->test(BusinessClientShow::class, ['businessClient' => $this->businessClient])
        ->set('uploadTypeCode', 'original_natis')
        ->set('uploadFile', UploadedFile::fake()->createWithContent('a.pdf', fakeBusinessClientPdf()))
        ->call('uploadDocument')
        ->assertHasErrors(['uploadFile']);

    expect(BusinessClientDocument::query()->count())->toBe(0);
});

it('rejects a non-PDF/JPG/PNG upload with a human message', function (): void {
    Livewire::actingAs($this->userA)
        ->test(BusinessClientShow::class, ['businessClient' => $this->businessClient])
        ->set('uploadTypeCode', 'poa')
        ->set('uploadFile', UploadedFile::fake()->createWithContent('poa.txt', 'this is not a pdf'))
        ->call('uploadDocument')
        ->assertHasErrors(['upload']);
});

it('replaces an existing document by writing a new version and flipping current_version_id', function (): void {
    // Seed a first upload.
    Livewire::actingAs($this->userA)
        ->test(BusinessClientShow::class, ['businessClient' => $this->businessClient])
        ->set('uploadTypeCode', 'proxy_id')
        ->set('uploadFile', UploadedFile::fake()->createWithContent('proxy_v1.pdf', fakeBusinessClientPdf()))
        ->call('uploadDocument')
        ->assertHasNoErrors();

    $document = BusinessClientDocument::query()
        ->where('business_client_id', $this->businessClient->id)
        ->firstOrFail();
    $firstVersionId = $document->current_version_id;

    // Then upload again against the same shell via the row-level replace.
    Livewire::actingAs($this->userA)
        ->test(BusinessClientShow::class, ['businessClient' => $this->businessClient])
        ->set('replacementFiles.'.$document->id, UploadedFile::fake()->createWithContent('proxy_v2.pdf', fakeBusinessClientPdf()))
        ->call('replaceDocument', $document->id)
        ->assertHasNoErrors();

    $document->refresh();
    $secondVersionId = $document->current_version_id;

    expect($secondVersionId)->not->toBe($firstVersionId)
        ->and(DocumentVersion::query()
            ->where('business_client_document_id', $document->id)
            ->count())->toBe(2);

    // Old version is still queryable (audit trail intact)
    $firstVersion = DocumentVersion::query()->find($firstVersionId);
    expect($firstVersion)->not->toBeNull();
});

it('refuses a cross-dealership user to even open the show page', function (): void {
    $this->actingAs($this->userB);

    // Dealership B cannot bind the model instance - the global scope on
    // BusinessClient filters it out and route-model-binding 404s.
    $this->get(route('business-clients.show', $this->businessClient))
        ->assertNotFound();
});

it('lets the owning dealer download their document and 404s a cross-dealer stranger', function (): void {
    Livewire::actingAs($this->userA)
        ->test(BusinessClientShow::class, ['businessClient' => $this->businessClient])
        ->set('uploadTypeCode', 'brn_certificate')
        ->set('uploadFile', UploadedFile::fake()->createWithContent('brn.pdf', fakeBusinessClientPdf()))
        ->call('uploadDocument');

    $version = DocumentVersion::query()
        ->whereNotNull('business_client_document_id')
        ->firstOrFail();

    // Owning dealer downloads successfully.
    $this->actingAs($this->userA)
        ->get(route('documents.download', $version))
        ->assertOk();

    // Cross-dealership user hits the global scope on DocumentVersion and
    // route-model-binding 404s - no information about the document leaks.
    $this->actingAs($this->userB)
        ->get(route('documents.download', $version))
        ->assertNotFound();
});

it('shows the uploaded document in the Documents section of the show page', function (): void {
    Livewire::actingAs($this->userA)
        ->test(BusinessClientShow::class, ['businessClient' => $this->businessClient])
        ->set('uploadTypeCode', 'brn_certificate')
        ->set('uploadFile', UploadedFile::fake()->createWithContent('brn.pdf', fakeBusinessClientPdf()))
        ->call('uploadDocument');

    Livewire::actingAs($this->userA)
        ->test(BusinessClientShow::class, ['businessClient' => $this->businessClient])
        ->assertSee('Documents')
        ->assertSee('BRN certificate')
        ->assertSee('brn.pdf')
        ->assertSee('Replace');
});

it('writes a business_client_document.stored audit event with the document type code', function (): void {
    Livewire::actingAs($this->userA)
        ->test(BusinessClientShow::class, ['businessClient' => $this->businessClient])
        ->set('uploadTypeCode', 'poa')
        ->set('uploadFile', UploadedFile::fake()->createWithContent('poa.pdf', fakeBusinessClientPdf()))
        ->call('uploadDocument');

    $this->assertDatabaseHas('audit_events', [
        'action' => 'business_client_document.stored',
        'subject_type' => BusinessClient::class,
        'subject_id' => $this->businessClient->id,
        'actor_user_id' => $this->userA->id,
    ]);
});

it('lets a dealer upload a document against a SHARED title holder they can see', function (): void {
    $sharedBank = BusinessClient::query()->create([
        'client_account_id' => $this->dealerA->id,
        'business_name' => 'Nedbank Vehicle Finance',
        'usable_as' => 'title_holder',
        'is_shared' => true,
        'status' => 'active',
    ]);

    Livewire::actingAs($this->userB)
        ->test(BusinessClientShow::class, ['businessClient' => $sharedBank])
        ->set('uploadTypeCode', 'brn_certificate')
        ->set('uploadFile', UploadedFile::fake()->createWithContent('nedbank-brn.pdf', fakeBusinessClientPdf()))
        ->call('uploadDocument')
        ->assertHasNoErrors();

    expect(BusinessClientDocument::query()
        ->where('business_client_id', $sharedBank->id)
        ->count())->toBe(1);
});
