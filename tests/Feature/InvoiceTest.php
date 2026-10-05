<?php

use App\Enums\ApplicationStage;
use App\Livewire\Portal\ApplicationShow;
use App\Livewire\Portal\FinanceInvoiceQueue;
use App\Livewire\Portal\InvoiceIndex;
use App\Models\Application;
use App\Models\ClientAccount;
use App\Models\Invoice;
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
        'reference' => 'EXL-INV-00001',
        'client_account_id' => $this->account->id,
        'stage' => ApplicationStage::PaymentVerified,
        'fee_snapshot' => ['total_cents' => 125_000],
    ]);
});

function invoiceUser(string $role, ?int $accountId = null): User
{
    $user = User::factory()->create([
        'client_account_id' => str_starts_with($role, 'customer') ? $accountId : null,
        'is_active' => true,
    ]);
    $user->assignRole($role);

    return $user;
}

function fakeInvoicePdf(): string
{
    return "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
}

it('lets finance upload an invoice from PaymentVerified onward', function (): void {
    $finance = invoiceUser('finance');

    Livewire::actingAs($finance)
        ->test(ApplicationShow::class, ['application' => $this->application])
        ->set('newInvoiceNumber', 'INV-2026-00042')
        ->set('newInvoice', UploadedFile::fake()->createWithContent('invoice.pdf', fakeInvoicePdf()))
        ->call('storeInvoice')
        ->assertHasNoErrors();

    $invoice = Invoice::query()->firstOrFail();

    expect($invoice->application_id)->toBe($this->application->id)
        ->and($invoice->invoice_number)->toBe('INV-2026-00042')
        ->and($invoice->uploaded_by_id)->toBe($finance->id)
        ->and($invoice->isPaid())->toBeFalse();

    Storage::disk('documents')->assertExists($invoice->storage_path);

    $this->assertDatabaseHas('audit_events', [
        'action' => 'invoice.uploaded',
        'actor_user_id' => $finance->id,
    ]);
});

it('refuses to upload against an application that is still in review', function (): void {
    $finance = invoiceUser('finance');

    $application = Application::query()->create([
        'reference' => 'EXL-INV-EARLY',
        'client_account_id' => $this->account->id,
        'stage' => ApplicationStage::DocumentReview,
    ]);

    Livewire::actingAs($finance)
        ->test(ApplicationShow::class, ['application' => $application])
        ->set('newInvoiceNumber', 'INV-2026-00099')
        ->set('newInvoice', UploadedFile::fake()->createWithContent('invoice.pdf', fakeInvoicePdf()))
        ->call('storeInvoice')
        ->assertForbidden();

    expect(Invoice::query()->count())->toBe(0);
});

it('rejects duplicate invoice numbers', function (): void {
    $finance = invoiceUser('finance');

    Invoice::query()->create([
        'application_id' => $this->application->id,
        'invoice_number' => 'INV-DUP',
        'storage_path' => 'invoices/'.$this->application->id.'/first.pdf',
        'original_filename' => 'first.pdf',
        'mime' => 'application/pdf',
        'size_bytes' => 10,
        'uploaded_at' => now(),
    ]);

    Livewire::actingAs($finance)
        ->test(ApplicationShow::class, ['application' => $this->application])
        ->set('newInvoiceNumber', 'INV-DUP')
        ->set('newInvoice', UploadedFile::fake()->createWithContent('invoice.pdf', fakeInvoicePdf()))
        ->call('storeInvoice')
        ->assertHasErrors(['invoice_number']);

    expect(Invoice::query()->count())->toBe(1);
});

it('rejects non-PDF/image uploads', function (): void {
    $finance = invoiceUser('owner');

    Livewire::actingAs($finance)
        ->test(ApplicationShow::class, ['application' => $this->application])
        ->set('newInvoiceNumber', 'INV-BAD')
        ->set('newInvoice', UploadedFile::fake()->create('bad.txt', 10, 'text/plain'))
        ->call('storeInvoice')
        ->assertHasErrors(['invoice']);

    expect(Invoice::query()->count())->toBe(0);
});

it('forbids a reviewer from uploading an invoice', function (): void {
    $reviewer = invoiceUser('reviewer');

    Livewire::actingAs($reviewer)
        ->test(ApplicationShow::class, ['application' => $this->application])
        ->set('newInvoiceNumber', 'INV-REVIEWER')
        ->set('newInvoice', UploadedFile::fake()->createWithContent('invoice.pdf', fakeInvoicePdf()))
        ->call('storeInvoice')
        ->assertForbidden();

    expect(Invoice::query()->count())->toBe(0);
});

it('forbids a client user from uploading an invoice', function (): void {
    $client = invoiceUser('customer_user', $this->account->id);

    Livewire::actingAs($client)
        ->test(ApplicationShow::class, ['application' => $this->application])
        ->set('newInvoiceNumber', 'INV-CLIENT')
        ->set('newInvoice', UploadedFile::fake()->createWithContent('invoice.pdf', fakeInvoicePdf()))
        ->call('storeInvoice')
        ->assertForbidden();

    expect(Invoice::query()->count())->toBe(0);
});

it('lets finance mark an invoice paid and then unpaid, writing audit events', function (): void {
    $finance = invoiceUser('finance');

    $invoice = Invoice::query()->create([
        'application_id' => $this->application->id,
        'invoice_number' => 'INV-TOGGLE',
        'storage_path' => 'invoices/'.$this->application->id.'/toggle.pdf',
        'original_filename' => 'toggle.pdf',
        'mime' => 'application/pdf',
        'size_bytes' => 10,
        'uploaded_at' => now(),
    ]);

    Livewire::actingAs($finance)
        ->test(ApplicationShow::class, ['application' => $this->application])
        ->call('startInvoicePayment', $invoice->id)
        ->set('invoicePaidReference', 'EFT-2026-00412')
        ->call('markInvoicePaid');

    $invoice->refresh();

    expect($invoice->isPaid())->toBeTrue()
        ->and($invoice->paid_by_user_id)->toBe($finance->id)
        ->and($invoice->paid_reference)->toBe('EFT-2026-00412');

    $this->assertDatabaseHas('audit_events', [
        'action' => 'invoice.paid',
        'actor_user_id' => $finance->id,
    ]);

    Livewire::actingAs($finance)
        ->test(ApplicationShow::class, ['application' => $this->application])
        ->call('markInvoiceUnpaid', $invoice->id);

    $invoice->refresh();

    expect($invoice->isPaid())->toBeFalse()
        ->and($invoice->paid_by_user_id)->toBeNull()
        ->and($invoice->paid_reference)->toBeNull();

    $this->assertDatabaseHas('audit_events', [
        'action' => 'invoice.unpaid',
        'actor_user_id' => $finance->id,
    ]);
});

it('forbids a client from marking an invoice paid', function (): void {
    $client = invoiceUser('customer_user', $this->account->id);

    $invoice = Invoice::query()->create([
        'application_id' => $this->application->id,
        'invoice_number' => 'INV-CP',
        'storage_path' => 'invoices/'.$this->application->id.'/cp.pdf',
        'original_filename' => 'cp.pdf',
        'mime' => 'application/pdf',
        'size_bytes' => 10,
        'uploaded_at' => now(),
    ]);

    Livewire::actingAs($client)
        ->test(ApplicationShow::class, ['application' => $this->application])
        ->call('startInvoicePayment', $invoice->id)
        ->assertForbidden();

    expect($invoice->fresh()->isPaid())->toBeFalse();
});

it('lets the owning dealer download their invoice and denies strangers', function (): void {
    Storage::disk('documents')->put('invoices/'.$this->application->id.'/sample.pdf', 'pdfbody');

    $invoice = Invoice::query()->create([
        'application_id' => $this->application->id,
        'invoice_number' => 'INV-DL',
        'storage_path' => 'invoices/'.$this->application->id.'/sample.pdf',
        'original_filename' => 'invoice.pdf',
        'mime' => 'application/pdf',
        'size_bytes' => 7,
        'uploaded_at' => now(),
    ]);

    $client = invoiceUser('customer_user', $this->account->id);

    $this->actingAs($client)
        ->get(route('invoices.download', $invoice))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->assertDatabaseHas('audit_events', [
        'action' => 'invoice.downloaded',
        'actor_user_id' => $client->id,
    ]);

    $stranger = invoiceUser('customer_user', $this->otherAccount->id);

    $this->actingAs($stranger)
        ->get(route('invoices.download', $invoice))
        ->assertForbidden();
});

it('shows the dealer only their own invoices on the account-wide index with correct totals', function (): void {
    $myApp = $this->application;
    $otherApp = Application::query()->create([
        'reference' => 'EXL-INV-OTHER',
        'client_account_id' => $this->otherAccount->id,
        'stage' => ApplicationStage::Completed,
        'fee_snapshot' => ['total_cents' => 50_000],
    ]);

    Invoice::query()->create([
        'application_id' => $myApp->id,
        'invoice_number' => 'INV-MINE-1',
        'storage_path' => 'invoices/'.$myApp->id.'/mine1.pdf',
        'original_filename' => 'mine1.pdf',
        'mime' => 'application/pdf',
        'size_bytes' => 10,
        'uploaded_at' => now(),
    ]);
    Invoice::query()->create([
        'application_id' => $myApp->id,
        'invoice_number' => 'INV-MINE-2',
        'storage_path' => 'invoices/'.$myApp->id.'/mine2.pdf',
        'original_filename' => 'mine2.pdf',
        'mime' => 'application/pdf',
        'size_bytes' => 10,
        'uploaded_at' => now(),
        'paid_at' => now(),
    ]);
    Invoice::query()->create([
        'application_id' => $otherApp->id,
        'invoice_number' => 'INV-OTHER',
        'storage_path' => 'invoices/'.$otherApp->id.'/other.pdf',
        'original_filename' => 'other.pdf',
        'mime' => 'application/pdf',
        'size_bytes' => 10,
        'uploaded_at' => now(),
    ]);

    $client = invoiceUser('customer_user', $this->account->id);

    Livewire::actingAs($client)
        ->test(InvoiceIndex::class)
        ->assertSee('INV-MINE-1')
        ->assertDontSee('INV-OTHER')
        ->assertViewHas('outstandingCents', 125_000)
        ->assertViewHas('paidCents', 125_000);
});

it('shows the finance queue every invoice with per-account outstanding totals', function (): void {
    $otherApp = Application::query()->create([
        'reference' => 'EXL-INV-OTHER',
        'client_account_id' => $this->otherAccount->id,
        'stage' => ApplicationStage::Completed,
        'fee_snapshot' => ['total_cents' => 50_000],
    ]);

    Invoice::query()->create([
        'application_id' => $this->application->id,
        'invoice_number' => 'INV-A',
        'storage_path' => 'invoices/'.$this->application->id.'/a.pdf',
        'original_filename' => 'a.pdf',
        'mime' => 'application/pdf',
        'size_bytes' => 10,
        'uploaded_at' => now(),
    ]);
    Invoice::query()->create([
        'application_id' => $otherApp->id,
        'invoice_number' => 'INV-B',
        'storage_path' => 'invoices/'.$otherApp->id.'/b.pdf',
        'original_filename' => 'b.pdf',
        'mime' => 'application/pdf',
        'size_bytes' => 10,
        'uploaded_at' => now(),
    ]);

    $finance = invoiceUser('finance');

    Livewire::actingAs($finance)
        ->test(FinanceInvoiceQueue::class)
        ->assertSee('INV-A')
        ->assertSee('INV-B')
        ->assertViewHas('outstandingCents', 175_000);
});

it('forbids a client from reaching the finance invoice queue', function (): void {
    $client = invoiceUser('customer_user', $this->account->id);

    Livewire::actingAs($client)
        ->test(FinanceInvoiceQueue::class)
        ->assertForbidden();
});

it('forbids licensing staff from reaching the client invoice index', function (): void {
    $finance = invoiceUser('finance');

    Livewire::actingAs($finance)
        ->test(InvoiceIndex::class)
        ->assertForbidden();
});
