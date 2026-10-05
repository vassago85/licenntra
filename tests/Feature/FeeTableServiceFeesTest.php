<?php

namespace Tests\Feature;

use App\Actions\ApproveFeeTableVersion;
use App\Enums\Province;
use App\Models\FeeTable;
use App\Models\FeeTableVersion;
use App\Models\User;
use Database\Seeders\FeeTableSeeder;
use Database\Seeders\LicenceFeeBandSeeder;
use Database\Seeders\LicenceFeeRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FeeTableServiceFeesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeeTableSeeder::class);
    }

    public function test_gazette_draft_carries_the_live_service_fees_forward(): void
    {
        $this->seed(LicenceFeeBandSeeder::class);
        $this->seed(LicenceFeeRateSeeder::class);

        $draft = $this->gautengVersion('draft');
        $live = $this->gautengVersion('active');

        foreach (['registration', 'datafix', 'admin', 'runner', 'plates'] as $code) {
            $this->assertSame(
                $live->lines()->where('code', $code)->value('amount_cents'),
                $draft->lines()->where('code', $code)->value('amount_cents'),
                "The gazette draft must keep the live {$code} fee.",
            );
        }

        $this->assertSame(0, $draft->lines()->where('code', 'licence')->whereNull('licence_category')->count(), 'The flat demo licence line is replaced by the gazette bands.');
    }

    public function test_approving_the_gazette_draft_keeps_service_fees_live(): void
    {
        $this->seed(LicenceFeeBandSeeder::class);
        $this->seed(LicenceFeeRateSeeder::class);

        $approved = app(ApproveFeeTableVersion::class)->handle($this->gautengVersion('draft'), User::factory()->create());

        $this->assertSame('active', $approved->status);
        $this->assertSame(50000, $approved->lines()->where('code', 'registration')->value('amount_cents'));
    }

    public function test_approval_is_refused_when_the_draft_drops_a_live_fee(): void
    {
        $live = $this->gautengVersion('active');
        $draft = $live->feeTable->versions()->create(['version' => 2, 'status' => 'draft']);

        foreach ($live->lines()->whereNotIn('code', ['registration', 'datafix'])->get() as $line) {
            $draft->lines()->create($line->only($line->getFillable()));
        }

        try {
            app(ApproveFeeTableVersion::class)->handle($draft, User::factory()->create());
            $this->fail('Approval should be refused when live fees are missing.');
        } catch (ValidationException $exception) {
            $message = $exception->validator->errors()->first();
            $this->assertStringContainsString('Authority registration fee (demo)', $message);
            $this->assertStringContainsString('Datafix fee (demo)', $message);
        }

        $this->assertSame('draft', $draft->refresh()->status);
        $this->assertSame('active', $live->refresh()->status);
    }

    public function test_repair_migration_restores_service_fees_on_a_live_gazette_version(): void
    {
        $live = $this->gautengVersion('active');
        $gazette = $live->feeTable->versions()->create(['version' => 2, 'status' => 'active']);
        $gazette->lines()->create(['code' => 'rtmc_transaction_fee', 'label' => 'RTMC', 'amount_cents' => 7200, 'client_visible' => true]);
        $gazette->lines()->create(['code' => 'licence', 'label' => 'Rigid band', 'amount_cents' => 25200, 'client_visible' => true, 'licence_category' => 'motor_car', 'tare_min_kg' => 0, 'tare_max_kg' => 250]);
        $live->update(['status' => 'superseded']);

        $migration = require database_path('migrations/2026_10_05_162234_restore_service_fees_on_live_fee_versions.php');
        $migration->up();
        $migration->up();

        foreach (['registration', 'datafix', 'admin', 'runner', 'plates'] as $code) {
            $this->assertSame(1, $gazette->lines()->where('code', $code)->count(), "{$code} must be restored exactly once.");
        }

        $this->assertSame(1, $gazette->lines()->where('code', 'licence')->count(), 'The flat licence line must not come back next to the bands.');
    }

    private function gautengVersion(string $status): FeeTableVersion
    {
        return FeeTable::query()
            ->where('province', Province::Gauteng->value)
            ->firstOrFail()
            ->versions()
            ->where('status', $status)
            ->latest('version')
            ->firstOrFail();
    }
}
