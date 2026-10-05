<?php

namespace Tests\Feature;

use App\Actions\ResolveRequiredDocuments;
use App\Actions\TransitionApplication;
use App\Enums\ApplicationStage;
use App\Enums\BillingMode;
use App\Enums\DocumentStatus;
use App\Models\Application;
use App\Models\ClientAccount;
use App\Models\Payment;
use App\Models\User;
use App\Services\OperationsWorkloadService;
use Database\Seeders\DocumentRuleSeeder;
use Database\Seeders\FeeTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BillingModeTest extends TestCase
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

    public function test_dealership_on_account_statement_auto_settles_payment_pending(): void
    {
        $application = $this->newApplicationFor($this->accountStatementDealer());

        $this->accept($application);

        app(TransitionApplication::class)->handle(
            $application,
            ApplicationStage::PaymentPending,
            $this->userWithRole('reviewer'),
        );

        $application->refresh();

        $this->assertSame(
            ApplicationStage::PaymentVerified,
            $application->stage,
            'An on-account dealership must auto-advance past PaymentPending.',
        );

        $payment = $application->payments()->firstOrFail();

        $this->assertTrue($payment->on_account, 'The auto-created payment must be flagged on_account.');
        $this->assertSame(Payment::METHOD_ACCOUNT_STATEMENT, $payment->method);
        $this->assertNotNull($payment->verified_at);
        $this->assertNull($payment->statement_settled_at);
        $this->assertSame($application->fee_snapshot['total_cents'], $payment->amount_cents);
    }

    public function test_pay_per_transaction_still_waits_for_a_cash_payment(): void
    {
        $application = $this->newApplicationFor($this->payPerTransactionAccount());

        $this->accept($application);

        app(TransitionApplication::class)->handle(
            $application,
            ApplicationStage::PaymentPending,
            $this->userWithRole('reviewer'),
        );

        $application->refresh();

        $this->assertSame(ApplicationStage::PaymentPending, $application->stage);
        $this->assertSame(0, $application->payments()->count(), 'No auto-payment for pay-per-transaction clients.');
    }

    public function test_statement_outstanding_cents_sums_only_unsettled_on_account_payments(): void
    {
        $dealer = $this->accountStatementDealer();
        $this->progressThroughPaymentPending($dealer);
        $this->progressThroughPaymentPending($dealer);

        $this->assertGreaterThan(0, $dealer->statementOutstandingCents());

        $expected = (int) $dealer->payments()
            ->where('payments.on_account', true)
            ->whereNull('payments.statement_settled_at')
            ->sum('payments.amount_cents');

        $this->assertSame($expected, $dealer->statementOutstandingCents());

        // Settle one payment and confirm the balance drops.
        $first = $dealer->payments()->orderBy('payments.id')->first();
        $first->update(['statement_settled_at' => now(), 'settled_by' => $this->userWithRole('finance')->id]);

        $this->assertLessThan($expected, $dealer->refresh()->statementOutstandingCents());
    }

    public function test_operations_workload_service_reports_statement_balance_per_account(): void
    {
        $dealer = $this->accountStatementDealer();
        $this->progressThroughPaymentPending($dealer);

        $rows = app(OperationsWorkloadService::class)->accountRows(['needs_action_only' => false]);
        $row = $rows->firstWhere('id', $dealer->id);

        $this->assertNotNull($row);
        $this->assertGreaterThan(0, $row['statement_outstanding_cents']);
        $this->assertSame(BillingMode::AccountStatement, $row['billing_mode']);
    }

    public function test_on_account_payment_records_a_system_audit_event(): void
    {
        $application = $this->newApplicationFor($this->accountStatementDealer());
        $this->accept($application);
        app(TransitionApplication::class)->handle(
            $application,
            ApplicationStage::PaymentPending,
            $this->userWithRole('reviewer'),
        );

        $payment = $application->refresh()->payments()->firstOrFail();

        $this->assertDatabaseHas('audit_events', [
            'subject_type' => Payment::class,
            'subject_id' => $payment->id,
            'action' => 'payment.on_account',
            'is_system' => true,
        ]);
    }

    private function accountStatementDealer(): ClientAccount
    {
        return ClientAccount::query()->create([
            'name' => 'On-Account Dealer '.uniqid(),
            'type' => 'dealer',
            'billing_mode' => BillingMode::AccountStatement->value,
            'payment_terms_days' => 30,
            'quote_acceptance_allowed' => true,
        ]);
    }

    private function payPerTransactionAccount(): ClientAccount
    {
        return ClientAccount::query()->create([
            'name' => 'Up-Front Dealer '.uniqid(),
            'type' => 'dealer',
            'billing_mode' => BillingMode::PayPerTransaction->value,
            'quote_acceptance_allowed' => true,
        ]);
    }

    private function newApplicationFor(ClientAccount $account): Application
    {
        $application = Application::query()->create([
            'reference' => 'LIC-'.uniqid(),
            'client_account_id' => $account->id,
            'request_type' => 'duplicate_disc',
            'service_type' => null,
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

        return $application->refresh();
    }

    private function accept(Application $application): void
    {
        $application->documents()
            ->where('required', true)
            ->update(['status' => DocumentStatus::Accepted->value]);
    }

    private function progressThroughPaymentPending(ClientAccount $dealer): Application
    {
        $application = $this->newApplicationFor($dealer);
        $this->accept($application);

        app(TransitionApplication::class)->handle(
            $application,
            ApplicationStage::PaymentPending,
            $this->userWithRole('reviewer'),
        );

        return $application->refresh();
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}
