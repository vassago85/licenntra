<?php

namespace Tests\Feature;

use App\Actions\CalculateFees;
use App\Models\Application;
use App\Models\ClientAccount;
use App\Models\FeeTable;
use Database\Seeders\DocumentRuleSeeder;
use Database\Seeders\FeeTableSeeder;
use Database\Seeders\LicenceFeeBandSeeder;
use Database\Seeders\LicenceFeeRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The R72 RTMC transaction fee is a national pass-through added to every
 * licence transaction (new licence or renewal). Register-only transactions
 * and non-licence transactions (change of ownership, data change, etc)
 * must not carry it.
 */
class RtmcTransactionFeeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentRuleSeeder::class);
        $this->seed(FeeTableSeeder::class);
        $this->seed(LicenceFeeBandSeeder::class);
        $this->seed(LicenceFeeRateSeeder::class);

        // Activate the Gauteng draft so CalculateFees can resolve it.
        $gauteng = FeeTable::query()->where('province', 'gauteng')->firstOrFail();
        $gauteng->versions()->where('status', 'draft')->update(['status' => 'active']);
    }

    public function test_r72_is_charged_on_new_registration_with_licence(): void
    {
        $snapshot = app(CalculateFees::class)->snapshot(
            $this->application(['service_type' => 'register_and_license', 'request_type' => 'new_registration']),
        );

        $rtmc = $this->rtmcLineFrom($snapshot);

        $this->assertNotNull($rtmc, 'RTMC fee must be present on a register-and-licence transaction');
        $this->assertSame(7200, $rtmc['amount_cents']);
        $this->assertSame('exempt', $rtmc['tax_treatment']);
    }

    public function test_r72_is_charged_on_licence_renewal(): void
    {
        $snapshot = app(CalculateFees::class)->snapshot(
            $this->application(['request_type' => 'licence_renewal', 'service_type' => null]),
        );

        $this->assertNotNull($this->rtmcLineFrom($snapshot), 'RTMC fee must be present on a licence renewal');
    }

    public function test_r72_is_skipped_on_register_only_transactions(): void
    {
        $snapshot = app(CalculateFees::class)->snapshot(
            $this->application(['service_type' => 'register_only', 'request_type' => 'new_registration']),
        );

        $this->assertNull($this->rtmcLineFrom($snapshot), 'RTMC fee must NOT appear on a register-only transaction');
    }

    public function test_r72_is_skipped_on_change_of_ownership(): void
    {
        $snapshot = app(CalculateFees::class)->snapshot(
            $this->application(['request_type' => 'change_of_ownership', 'service_type' => null]),
        );

        $this->assertNull($this->rtmcLineFrom($snapshot));
    }

    public function test_r72_is_skipped_on_data_change(): void
    {
        $snapshot = app(CalculateFees::class)->snapshot(
            $this->application(['request_type' => 'data_change', 'service_type' => null]),
        );

        $this->assertNull($this->rtmcLineFrom($snapshot));
    }

    public function test_r72_is_skipped_on_duplicate_disc(): void
    {
        $snapshot = app(CalculateFees::class)->snapshot(
            $this->application(['request_type' => 'duplicate_disc', 'service_type' => null]),
        );

        $this->assertNull($this->rtmcLineFrom($snapshot));
    }

    public function test_rtmc_fee_is_included_in_the_snapshot_total(): void
    {
        $snapshot = app(CalculateFees::class)->snapshot(
            $this->application(['service_type' => 'register_and_license', 'request_type' => 'new_registration']),
        );

        $sumOfLines = array_sum(array_column($snapshot['lines'], 'amount_cents'));

        $this->assertSame($sumOfLines, $snapshot['total_cents'], 'The RTMC fee must contribute to total_cents');
        $this->assertGreaterThanOrEqual(7200, $snapshot['total_cents']);
    }

    private function application(array $overrides): Application
    {
        $account = ClientAccount::query()->create([
            'name' => 'RTMC Test '.uniqid(),
            'type' => 'dealer',
            'markup_basis_points' => 0,
        ]);

        $application = Application::query()->create(array_merge([
            'reference' => 'LIC-RTMC-'.uniqid(),
            'client_account_id' => $account->id,
            'request_type' => 'new_registration',
            'service_type' => 'register_and_license',
            'vehicle_category' => 'passenger',
            'owner_type' => 'individual',
            'province' => 'gauteng',
            'is_financed' => false,
            'stage' => 'draft',
        ], $overrides));

        $application->vehicle()->create([
            'vin' => 'JHHGD8JLA7K104512',
            'vehicle_register_number' => 'TLX914G',
            'tare_kg' => 1500,
        ]);

        return $application->refresh();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>|null
     */
    private function rtmcLineFrom(array $snapshot): ?array
    {
        foreach ($snapshot['lines'] as $line) {
            if ($line['code'] === 'rtmc_transaction_fee') {
                return $line;
            }
        }

        return null;
    }
}
