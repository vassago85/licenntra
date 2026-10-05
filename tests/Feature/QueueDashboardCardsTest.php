<?php

namespace Tests\Feature;

use App\Enums\ApplicationStage;
use App\Models\Application;
use App\Models\ClientAccount;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QueueDashboardCardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_review_queue_renders_the_four_kpi_stat_cards(): void
    {
        $reviewer = User::factory()->create(['is_active' => true]);
        $reviewer->assignRole('reviewer');

        $dealer = ClientAccount::query()->create([
            'name' => 'Dealer A',
            'type' => 'dealer',
            'quote_acceptance_allowed' => false,
        ]);

        $this->application($dealer, ApplicationStage::Submitted);
        $this->application($dealer, ApplicationStage::DocumentReview);
        $this->application($dealer, ApplicationStage::ChangesRequested);
        $this->application($dealer, ApplicationStage::Submitted, $reviewer->id);

        $response = $this->actingAs($reviewer)
            ->get(route('review.queue'))
            ->assertOk();

        $response->assertSee('Awaiting review');
        $response->assertSee('With client');
        $response->assertSee('Assigned to me');
        $response->assertSee('SLA at risk');
    }

    public function test_payment_queue_renders_the_four_kpi_stat_cards(): void
    {
        $finance = User::factory()->create(['is_active' => true]);
        $finance->assignRole('finance');

        $dealer = ClientAccount::query()->create([
            'name' => 'Dealer B',
            'type' => 'dealer',
            'quote_acceptance_allowed' => false,
        ]);

        $this->application($dealer, ApplicationStage::PaymentPending, null, [
            'fee_snapshot' => ['total_cents' => 42_000],
        ]);
        $this->application($dealer, ApplicationStage::PaymentPending, null, [
            'fee_snapshot' => ['total_cents' => 11_500],
        ]);

        $response = $this->actingAs($finance)
            ->get(route('finance.payments'))
            ->assertOk();

        $response->assertSee('Awaiting verification');
        $response->assertSee('Expected total');
        $response->assertSee('Dealerships owing');
        $response->assertSee('Oldest wait');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function application(ClientAccount $account, ApplicationStage $stage, ?int $reviewerId = null, array $overrides = []): Application
    {
        static $seq = 0;
        $seq++;

        return Application::query()->create(array_merge([
            'reference' => sprintf('LIC-TEST-%05d', $seq),
            'client_account_id' => $account->id,
            'stage' => $stage,
            'assigned_reviewer_id' => $reviewerId,
        ], $overrides));
    }
}
