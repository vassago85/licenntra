<?php

namespace Tests\Feature;

use App\Actions\MarkInvoicePaid;
use App\Actions\MarkInvoiceUnpaid;
use App\Actions\ResolveRequiredDocuments;
use App\Actions\TransitionApplication;
use App\Enums\ApplicationStage;
use App\Enums\BillingMode;
use App\Enums\DocumentStatus;
use App\Livewire\Portal\ReviewWorkspace;
use App\Models\Application;
use App\Models\ClientAccount;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\FeatureFlags;
use App\Services\ReceivablesService;
use Database\Seeders\DocumentRuleSeeder;
use Database\Seeders\FeeTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReceivablesBalanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentRuleSeeder::class);
        $this->seed(FeeTableSeeder::class);

        foreach (['owner', 'reviewer', 'finance', 'customer_admin', 'customer_user'] as $role) {
            Role::findOrCreate($role);
        }
    }

    protected function tearDown(): void
    {
        FeatureFlags::swapPaymentTrackingRequired(null);

        parent::tearDown();
    }

    public function test_without_payment_tracking_a_pay_up_front_client_is_billed_and_moves_on(): void
    {
        FeatureFlags::swapPaymentTrackingRequired(false);
        $application = $this->requestPayment($this->payUpFrontAccount());

        $this->assertSame(ApplicationStage::PaymentVerified, $application->stage);

        $entry = $application->payments()->sole();
        $this->assertTrue($entry->on_account);
        $this->assertSame(Payment::METHOD_INVOICE, $entry->method);
        $this->assertNull($entry->statement_settled_at);
        $this->assertSame($application->fee_snapshot['total_cents'], $entry->amount_cents);
    }

    public function test_with_payment_tracking_a_pay_up_front_client_waits_for_cash(): void
    {
        FeatureFlags::swapPaymentTrackingRequired(true);
        $application = $this->requestPayment($this->payUpFrontAccount());

        $this->assertSame(ApplicationStage::PaymentPending, $application->stage);
        $this->assertSame(0, $application->payments()->count());
    }

    public function test_reviewer_can_bill_an_application_left_waiting_for_payment_when_tracking_is_off(): void
    {
        FeatureFlags::swapPaymentTrackingRequired(true);
        $application = $this->requestPayment($this->payUpFrontAccount());
        FeatureFlags::swapPaymentTrackingRequired(false);

        $reviewer = $this->userWithRole('reviewer');

        Livewire::actingAs($reviewer)
            ->test(ReviewWorkspace::class, ['application' => $application])
            ->assertSee('Bill and continue')
            ->call('advance', ApplicationStage::PaymentVerified->value)
            ->assertHasNoErrors();

        $application->refresh();
        $this->assertSame(ApplicationStage::PaymentVerified, $application->stage);
        $this->assertTrue($application->payments()->sole()->on_account);
    }

    public function test_reviewer_cannot_skip_payment_while_tracking_is_on(): void
    {
        FeatureFlags::swapPaymentTrackingRequired(true);
        $application = $this->requestPayment($this->payUpFrontAccount());

        Livewire::actingAs($this->userWithRole('reviewer'))
            ->test(ReviewWorkspace::class, ['application' => $application])
            ->assertDontSee('Bill and continue')
            ->call('advance', ApplicationStage::PaymentVerified->value);

        $this->assertSame(ApplicationStage::PaymentPending, $application->refresh()->stage);
    }

    public function test_statement_charge_is_outstanding_until_its_invoice_is_paid(): void
    {
        $account = $this->statementAccount();
        $application = $this->requestPayment($account);
        $total = (int) $application->fee_snapshot['total_cents'];
        $this->assertGreaterThan(0, $total);

        $receivables = app(ReceivablesService::class);
        $summary = $receivables->summary();
        $this->assertSame($total, $summary['outstanding_cents']);
        $this->assertSame(0, $summary['received_this_month_cents']);
        $this->assertSame($total, $this->balanceFor($account)['outstanding_cents']);
        $this->assertSame(0, $this->balanceFor($account)['received_90d_cents']);

        $finance = $this->userWithRole('finance');
        $invoice = $this->invoiceFor($application);
        app(MarkInvoicePaid::class)->handle($invoice, $finance, 'EFT-1');

        $entry = $application->payments()->sole();
        $this->assertNotNull($entry->statement_settled_at);
        $this->assertSame($finance->id, $entry->settled_by);

        $summary = $receivables->summary();
        $this->assertSame(0, $summary['outstanding_cents']);
        $this->assertSame($total, $summary['received_this_month_cents']);
        $this->assertSame($total, $this->balanceFor($account)['received_90d_cents']);
        $this->assertSame($total, $receivables->topCustomers()->firstWhere('account.id', $account->id)['revenue_cents']);

        app(MarkInvoiceUnpaid::class)->handle($invoice->refresh(), $finance);

        $this->assertNull($entry->refresh()->statement_settled_at);
        $this->assertSame($total, $receivables->summary()['outstanding_cents']);
        $this->assertSame(0, $receivables->summary()['received_this_month_cents']);
    }

    public function test_backfill_settles_entries_whose_invoice_was_already_paid(): void
    {
        $paid = $this->requestPayment($this->statementAccount());
        $unpaid = $this->requestPayment($this->statementAccount());
        $finance = $this->userWithRole('finance');

        $this->invoiceFor($paid)->forceFill(['paid_at' => now()->subDay(), 'paid_by_user_id' => $finance->id])->save();
        $this->invoiceFor($unpaid);

        $migration = require database_path('migrations/2026_10_05_162748_settle_billed_entries_with_paid_invoices.php');
        $migration->up();

        $settled = $paid->payments()->sole();
        $this->assertNotNull($settled->statement_settled_at);
        $this->assertSame($finance->id, $settled->settled_by);
        $this->assertNull($unpaid->payments()->sole()->statement_settled_at);
    }

    public function test_application_waiting_for_cash_is_outstanding_and_a_verified_payment_is_received(): void
    {
        FeatureFlags::swapPaymentTrackingRequired(true);
        $account = $this->payUpFrontAccount();
        $application = $this->requestPayment($account);
        $total = (int) $application->fee_snapshot['total_cents'];

        $receivables = app(ReceivablesService::class);
        $this->assertSame($total, $receivables->summary()['outstanding_cents']);
        $this->assertSame($total, $this->balanceFor($account)['outstanding_cents']);

        $application->payments()->create([
            'amount_cents' => $total,
            'method' => 'eft',
            'reference' => 'EFT-CASH',
            'verified_at' => now(),
        ]);

        $summary = $receivables->summary();
        $this->assertSame(0, $summary['outstanding_cents']);
        $this->assertSame($total, $summary['received_this_month_cents']);
        $this->assertSame($total, $summary['last_payment']->amount_cents);
    }

    public function test_balances_are_kept_separate_per_client_account(): void
    {
        $first = $this->statementAccount();
        $second = $this->statementAccount();
        $firstApplication = $this->requestPayment($first);
        $this->requestPayment($second);
        $this->requestPayment($second);

        $secondTotal = (int) $second->payments()->sum('payments.amount_cents');

        $this->assertSame((int) $firstApplication->fee_snapshot['total_cents'], $this->balanceFor($first)['outstanding_cents']);
        $this->assertSame($secondTotal, $this->balanceFor($second)['outstanding_cents']);
        $this->assertSame(
            $this->balanceFor($first)['outstanding_cents'] + $secondTotal,
            app(ReceivablesService::class)->summary()['outstanding_cents'],
        );
    }

    /**
     * @return array{outstanding_cents: int, received_90d_cents: int}
     */
    private function balanceFor(ClientAccount $account): array
    {
        $row = app(ReceivablesService::class)->customerBalances()->firstWhere('account.id', $account->id);

        return [
            'outstanding_cents' => (int) ($row['outstanding_cents'] ?? 0),
            'received_90d_cents' => (int) ($row['received_90d_cents'] ?? 0),
        ];
    }

    private function requestPayment(ClientAccount $account): Application
    {
        $application = Application::query()->create([
            'reference' => 'LIC-'.uniqid(),
            'client_account_id' => $account->id,
            'request_type' => 'duplicate_disc',
            'vehicle_category' => 'passenger',
            'owner_type' => 'individual',
            'province' => 'gauteng',
            'is_financed' => false,
            'stage' => 'document_review',
        ]);

        $application->vehicle()->create([
            'vin' => 'JHHGD8JLA7K104512',
            'vehicle_register_number' => 'TLX914G',
            'make' => 'Hino',
            'model' => '500',
            'tare_kg' => 2000,
        ]);

        app(ResolveRequiredDocuments::class)->handle($application);
        $application->documents()->where('required', true)->update(['status' => DocumentStatus::Accepted->value]);

        app(TransitionApplication::class)->handle(
            $application->refresh(),
            ApplicationStage::PaymentPending,
            $this->userWithRole('reviewer'),
        );

        return $application->refresh();
    }

    private function invoiceFor(Application $application): Invoice
    {
        return Invoice::query()->create([
            'application_id' => $application->id,
            'invoice_number' => 'INV-'.uniqid(),
            'storage_path' => 'invoices/'.$application->id.'/invoice.pdf',
            'original_filename' => 'invoice.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 10,
            'uploaded_at' => now(),
        ]);
    }

    private function statementAccount(): ClientAccount
    {
        return ClientAccount::query()->create([
            'name' => 'Statement Dealer '.uniqid(),
            'type' => 'dealer',
            'billing_mode' => BillingMode::AccountStatement->value,
            'payment_terms_days' => 30,
        ]);
    }

    private function payUpFrontAccount(): ClientAccount
    {
        return ClientAccount::query()->create([
            'name' => 'Up-front Dealer '.uniqid(),
            'type' => 'dealer',
            'billing_mode' => BillingMode::PayPerTransaction->value,
        ]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}
