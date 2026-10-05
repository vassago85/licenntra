<?php

use App\Enums\ApplicationStage;
use App\Enums\OwnerType;
use App\Enums\RequestType;
use App\Enums\VehicleCategory;
use App\Livewire\Portal\Admin\PlatformBilling;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\ClientAccount;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\PlatformBillingService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Charsley Digital charges the licensing company owner a per-completed-
 * transaction fee. The developer sets the fee; the owner reads the
 * running counter and the resulting bill. Everyone else is locked out
 * of the page entirely.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);

    $this->dealer = ClientAccount::query()->create([
        'name' => 'Highveld Commercial Centurion',
        'brn' => '1996/001234/07',
        'type' => 'dealer',
        'status' => 'active',
        'markup_basis_points' => 0,
    ]);

    $this->owner = User::factory()->create(['is_active' => true]);
    $this->owner->assignRole('owner');

    $this->developer = User::factory()->create(['is_active' => true]);
    $this->developer->assignRole('developer');

    $this->customerAdmin = User::factory()->create([
        'is_active' => true,
        'client_account_id' => $this->dealer->id,
    ]);
    $this->customerAdmin->assignRole('customer_admin');

    $this->finance = User::factory()->create(['is_active' => true]);
    $this->finance->assignRole('finance');
});

function makeApplicationAt(ClientAccount $dealer, ApplicationStage $stage, ?string $completedAt): Application
{
    return Application::query()->create([
        'reference' => 'LIC-PBT-'.random_int(10000, 99999),
        'client_account_id' => $dealer->id,
        'stage' => $stage,
        'request_type' => RequestType::LicenceRenewal,
        'vehicle_category' => VehicleCategory::Commercial,
        'owner_type' => OwnerType::Business,
        'completed_at' => $completedAt,
    ]);
}

it('counts only Completed-stage applications completed inside the chosen month', function (): void {
    SystemSetting::current()->update(['platform_fee_per_transaction_cents' => 2500]);

    // In the current month, three completions.
    makeApplicationAt($this->dealer, ApplicationStage::Completed, now()->startOfMonth()->addDays(1)->toDateTimeString());
    makeApplicationAt($this->dealer, ApplicationStage::Completed, now()->startOfMonth()->addDays(10)->toDateTimeString());
    makeApplicationAt($this->dealer, ApplicationStage::Completed, now()->endOfMonth()->subMinute()->toDateTimeString());

    // Last month - should not count.
    makeApplicationAt($this->dealer, ApplicationStage::Completed, now()->subMonthNoOverflow()->endOfMonth()->subDay()->toDateTimeString());

    // Cancelled this month - should not count even if timestamped.
    makeApplicationAt($this->dealer, ApplicationStage::Cancelled, now()->startOfMonth()->addDays(2)->toDateTimeString());

    // Still in progress - should not count.
    makeApplicationAt($this->dealer, ApplicationStage::DocumentReview, null);

    $usage = app(PlatformBillingService::class)->monthlyUsage();

    expect($usage['count'])->toBe(3)
        ->and($usage['fee_per_transaction_cents'])->toBe(2500)
        ->and($usage['total_cents'])->toBe(7500);
});

it('includes Archived rows (they also reached Completed first)', function (): void {
    SystemSetting::current()->update(['platform_fee_per_transaction_cents' => 1000]);

    makeApplicationAt($this->dealer, ApplicationStage::Completed, now()->startOfMonth()->addDay()->toDateTimeString());
    makeApplicationAt($this->dealer, ApplicationStage::Archived, now()->startOfMonth()->addDays(5)->toDateTimeString());

    $usage = app(PlatformBillingService::class)->monthlyUsage();

    expect($usage['count'])->toBe(2)
        ->and($usage['total_cents'])->toBe(2000);
});

it('returns six months of history in descending order', function (): void {
    $rows = app(PlatformBillingService::class)->recentMonths(6);

    expect($rows)->toHaveCount(6)
        ->and($rows[0]['month']->format('Y-m'))->toBe(now()->format('Y-m'))
        ->and($rows[5]['month']->format('Y-m'))->toBe(now()->subMonthsNoOverflow(5)->format('Y-m'));
});

it('allows the developer to set the per-transaction fee and audits the change', function (): void {
    $this->actingAs($this->developer);

    Livewire::test(PlatformBilling::class)
        ->set('platformFeeRands', '27.50')
        ->call('saveFee')
        ->assertHasNoErrors();

    expect(SystemSetting::current()->platform_fee_per_transaction_cents)->toBe(2750);

    $audit = AuditEvent::query()
        ->where('action', 'platform.fee_changed')
        ->latest('id')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->actor_user_id)->toBe($this->developer->id)
        ->and($audit->summary)->toContain('R 0.00')
        ->and($audit->summary)->toContain('R 27.50')
        ->and($audit->after['platform_fee_per_transaction_cents'])->toBe(2750);
});

it('forbids the owner from changing the fee even if they call saveFee directly', function (): void {
    SystemSetting::current()->update(['platform_fee_per_transaction_cents' => 1500]);

    $this->actingAs($this->owner);

    Livewire::test(PlatformBilling::class)
        ->set('platformFeeRands', '99.99')
        ->call('saveFee')
        ->assertStatus(403);

    // Nothing changed.
    expect(SystemSetting::current()->platform_fee_per_transaction_cents)->toBe(1500);
});

it('forbids a dealer admin (customer_admin) from even seeing the Platform billing page', function (): void {
    $this->actingAs($this->customerAdmin)
        ->get(route('platform.billing'))
        ->assertForbidden();
});

it('forbids a finance user from seeing the Platform billing page', function (): void {
    $this->actingAs($this->finance)
        ->get(route('platform.billing'))
        ->assertForbidden();
});

it('lets the owner see the page but renders the fee input as disabled', function (): void {
    $this->actingAs($this->owner)
        ->get(route('platform.billing'))
        ->assertOk();

    Livewire::test(PlatformBilling::class)
        ->assertSee('Read-only')
        ->assertSee('Only the developer can change the fee');
});

it('shows the counter + fee + bill tiles to the developer', function (): void {
    SystemSetting::current()->update(['platform_fee_per_transaction_cents' => 2500]);

    makeApplicationAt($this->dealer, ApplicationStage::Completed, now()->startOfMonth()->addDay()->toDateTimeString());
    makeApplicationAt($this->dealer, ApplicationStage::Completed, now()->startOfMonth()->addDays(2)->toDateTimeString());

    $this->actingAs($this->developer);

    Livewire::test(PlatformBilling::class)
        ->assertSee('Completed transactions')
        ->assertSee('Fee per transaction')
        ->assertSee('Platform bill')
        ->assertSee('R 25.00')  // fee
        ->assertSee('R 50.00'); // 2 × R25
});

it('does not change the stored fee or add an audit entry when the developer saves the same value', function (): void {
    SystemSetting::current()->update(['platform_fee_per_transaction_cents' => 2500]);

    $this->actingAs($this->developer);

    Livewire::test(PlatformBilling::class)
        ->set('platformFeeRands', '25.00')
        ->call('saveFee');

    expect(AuditEvent::query()->where('action', 'platform.fee_changed')->count())->toBe(0);
});
