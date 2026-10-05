<?php

use App\Actions\StoreInvoice;
use App\Enums\ApplicationStage;
use App\Livewire\Portal\ApplicationShow;
use App\Livewire\Portal\TeamIndex;
use App\Models\Application;
use App\Models\ClientAccount;
use App\Models\Invoice;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Invoice recipient flow:
 *  - The dealership's client_admin nominates one user as the "stock
 *    controller" from the Team page.
 *  - When the licensing company (finance/customer_admin/super_admin)
 *    uploads an invoice, the recipient defaults to that stock
 *    controller.
 *  - Finance can override per-invoice to pick a different active user
 *    on the same dealership.
 *  - Trying to assign a user from a different dealership is rejected.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('documents');

    $this->dealerA = ClientAccount::query()->create([
        'name' => 'Highveld Commercial Centurion',
        'type' => 'dealer',
    ]);

    $this->dealerB = ClientAccount::query()->create([
        'name' => 'Lowveld Vehicle Group',
        'type' => 'dealer',
    ]);

    $this->admin = User::factory()->create([
        'client_account_id' => $this->dealerA->id,
        'is_active' => true,
    ]);
    $this->admin->assignRole('customer_admin');

    $this->stockGuy = User::factory()->create([
        'client_account_id' => $this->dealerA->id,
        'is_active' => true,
        'name' => 'Jane Stockroom',
    ]);
    $this->stockGuy->assignRole('customer_user');

    $this->financeAssistant = User::factory()->create([
        'client_account_id' => $this->dealerA->id,
        'is_active' => true,
        'name' => 'Phil Debtor',
    ]);
    $this->financeAssistant->assignRole('customer_user');

    $this->otherDealerUser = User::factory()->create([
        'client_account_id' => $this->dealerB->id,
        'is_active' => true,
        'name' => 'Rival Rachel',
    ]);
    $this->otherDealerUser->assignRole('customer_user');

    $this->finance = User::factory()->create([
        'client_account_id' => null,
        'is_active' => true,
    ]);
    $this->finance->assignRole('finance');

    $this->application = Application::query()->create([
        'reference' => 'EXL-SC-00001',
        'client_account_id' => $this->dealerA->id,
        'stage' => ApplicationStage::PaymentVerified,
        'fee_snapshot' => ['total_cents' => 125_000],
    ]);
});

function fakeStockInvoicePdf(): string
{
    return "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
}

it('lets a client_admin nominate a stock controller for the dealership', function (): void {
    Livewire::actingAs($this->admin)
        ->test(TeamIndex::class)
        ->call('promoteStockController', $this->stockGuy->id)
        ->assertHasNoErrors();

    expect($this->dealerA->refresh()->stock_controller_user_id)->toBe($this->stockGuy->id);
});

it('refuses to nominate an inactive user as the stock controller', function (): void {
    $this->stockGuy->update(['is_active' => false]);

    Livewire::actingAs($this->admin)
        ->test(TeamIndex::class)
        ->call('promoteStockController', $this->stockGuy->id);

    expect($this->dealerA->refresh()->stock_controller_user_id)->toBeNull();
});

it('only allows ONE stock controller at a time - promoting a second overwrites the first', function (): void {
    Livewire::actingAs($this->admin)
        ->test(TeamIndex::class)
        ->call('promoteStockController', $this->stockGuy->id)
        ->call('promoteStockController', $this->financeAssistant->id);

    expect($this->dealerA->refresh()->stock_controller_user_id)->toBe($this->financeAssistant->id);
});

it('lets a client_admin clear the stock controller nomination', function (): void {
    $this->dealerA->forceFill(['stock_controller_user_id' => $this->stockGuy->id])->save();

    Livewire::actingAs($this->admin)
        ->test(TeamIndex::class)
        ->call('demoteStockController');

    expect($this->dealerA->refresh()->stock_controller_user_id)->toBeNull();
});

it('refuses to let a client_admin from one dealership touch another dealership\'s team', function (): void {
    $otherAdmin = User::factory()->create([
        'client_account_id' => $this->dealerB->id,
        'is_active' => true,
    ]);
    $otherAdmin->assignRole('customer_admin');

    Livewire::actingAs($otherAdmin)
        ->test(TeamIndex::class)
        ->call('promoteStockController', $this->stockGuy->id)
        ->assertStatus(404);

    expect($this->dealerA->refresh()->stock_controller_user_id)->toBeNull();
});

it('auto-assigns a new invoice to the stock controller when finance leaves recipient blank', function (): void {
    $this->dealerA->forceFill(['stock_controller_user_id' => $this->stockGuy->id])->save();

    app(StoreInvoice::class)->handle(
        $this->application,
        UploadedFile::fake()->createWithContent('invoice.pdf', fakeStockInvoicePdf()),
        'INV-2026-00500',
        $this->finance,
        null,
    );

    $invoice = Invoice::query()->firstOrFail();
    expect($invoice->recipient_user_id)->toBe($this->stockGuy->id);
});

it('creates an invoice with NO recipient when there is no stock controller and finance leaves it blank', function (): void {
    app(StoreInvoice::class)->handle(
        $this->application,
        UploadedFile::fake()->createWithContent('invoice.pdf', fakeStockInvoicePdf()),
        'INV-2026-00501',
        $this->finance,
        null,
    );

    expect(Invoice::query()->firstOrFail()->recipient_user_id)->toBeNull();
});

it('lets finance override the recipient to a different active user at the dealership', function (): void {
    $this->dealerA->forceFill(['stock_controller_user_id' => $this->stockGuy->id])->save();

    app(StoreInvoice::class)->handle(
        $this->application,
        UploadedFile::fake()->createWithContent('invoice.pdf', fakeStockInvoicePdf()),
        'INV-2026-00502',
        $this->finance,
        $this->financeAssistant->id,
    );

    expect(Invoice::query()->firstOrFail()->recipient_user_id)->toBe($this->financeAssistant->id);
});

it('refuses to assign a user from a DIFFERENT dealership as the invoice recipient', function (): void {
    expect(fn () => app(StoreInvoice::class)->handle(
        $this->application,
        UploadedFile::fake()->createWithContent('invoice.pdf', fakeStockInvoicePdf()),
        'INV-2026-00503',
        $this->finance,
        $this->otherDealerUser->id,
    ))->toThrow(ValidationException::class);
});

it('refuses to assign an inactive user as the invoice recipient', function (): void {
    $this->stockGuy->update(['is_active' => false]);

    expect(fn () => app(StoreInvoice::class)->handle(
        $this->application,
        UploadedFile::fake()->createWithContent('invoice.pdf', fakeStockInvoicePdf()),
        'INV-2026-00504',
        $this->finance,
        $this->stockGuy->id,
    ))->toThrow(ValidationException::class);
});

it('pre-fills the ApplicationShow invoice form with the dealership stock controller for a finance uploader', function (): void {
    $this->dealerA->forceFill(['stock_controller_user_id' => $this->stockGuy->id])->save();

    Livewire::actingAs($this->finance)
        ->test(ApplicationShow::class, ['application' => $this->application])
        ->assertSet('newInvoiceRecipientUserId', (string) $this->stockGuy->id)
        ->assertSee('Addressed to (at the dealership)')
        ->assertSee('(Stock controller)');
});
