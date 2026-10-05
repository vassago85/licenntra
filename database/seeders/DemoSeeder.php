<?php

namespace Database\Seeders;

use App\Actions\CalculateFees;
use App\Actions\ResolveRequiredDocuments;
use App\Actions\SyncDatafixStatus;
use App\Enums\DatafixStatus;
use App\Models\Application;
use App\Models\BrandingSetting;
use App\Models\BusinessClient;
use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // DemoSeeder is often invoked directly (first-boot auto-seed in the
        // Docker entrypoint, manual `db:seed --class=DemoSeeder`, etc.)
        // rather than through DatabaseSeeder. Call the prerequisite
        // seeders here so running DemoSeeder standalone always works:
        // without roles the user() helper's syncRoles() call blows up with
        // "There is no role named `super_admin` for guard `web`", and
        // without the fee/document/licence tables the generated
        // applications can't resolve their checklists or quotes.
        $this->call([
            RoleSeeder::class,
            DocumentRuleSeeder::class,
            FeeTableSeeder::class,
            LicenceFeeBandSeeder::class,
            LicenceFeeRateSeeder::class,
        ]);

        // Placeholder branding for a generic licensing company. The real
        // licensing company reconfigures these values via the Admin
        // console (Branding settings) before go-live; nothing in this
        // seeder is a real customer name.
        $branding = BrandingSetting::current();
        $branding->fill([
            'company_name' => 'Example Licensing',
            'primary_colour' => '#1F47B8',
            'support_email' => 'support@example.test',
            'support_phone' => '010 000 0000',
            'address' => '1 Demo Street, Example Town',
            'reference_prefix' => 'EXL',
        ])->save();

        $accounts = [
            'oem' => ClientAccount::query()->updateOrCreate(['name' => 'Northvale Truck & Bus SA'], [
                'type' => 'oem',
                'status' => 'active',
                'quote_acceptance_allowed' => false,
                'contact_name' => 'Northvale Desk',
                'contact_email' => 'desk@northvale.test',
            ]),
            'dealer' => ClientAccount::query()->updateOrCreate(['name' => 'Highveld Commercial Centurion'], [
                'type' => 'dealer',
                'status' => 'active',
                'quote_acceptance_allowed' => true,
                'billing_mode' => 'account_statement',
                'payment_terms_days' => 30,
                'contact_name' => 'Thandi Mokoena',
                'contact_email' => 'thandi.mokoena@highveld.test',
            ]),
            'body' => ClientAccount::query()->updateOrCreate(['name' => 'Ridgeway Bodies'], [
                'type' => 'body_builder',
                'status' => 'active',
                'quote_acceptance_allowed' => false,
                'contact_name' => 'Ridgeway Office',
                'contact_email' => 'office@ridgeway.test',
            ]),
            'fleet' => ClientAccount::query()->updateOrCreate(['name' => 'Kestrel Logistics'], [
                'type' => 'fleet_operator',
                'status' => 'active',
                'quote_acceptance_allowed' => true,
                'contact_name' => 'Kestrel Fleet',
                'contact_email' => 'fleet@kestrel.test',
            ]),
        ];

        $this->user('Super admin', 'super.admin@licentra.test', 'super_admin');
        $this->user('Customer admin', 'customer.admin@licentra.test', 'customer_admin');
        $reviewer = $this->user('Reviewer', 'reviewer@licentra.test', 'reviewer');
        $this->user('Finance', 'finance@licentra.test', 'finance');
        $this->user('Auditor', 'auditor@licentra.test', 'auditor');
        $this->user('Thandi Mokoena', 'thandi.mokoena@highveld.test', 'client_admin', $accounts['dealer']->id);
        $this->user('Johan Botha', 'johan.botha@highveld.test', 'client_user', $accounts['dealer']->id);

        $ridgeline = BusinessClient::query()->updateOrCreate([
            'client_account_id' => $accounts['dealer']->id,
            'business_name' => 'Ridgeline Haulage',
        ], [
            'usable_as' => 'owner',
            'proxy_name' => 'Lerato Dlamini',
            'address' => '4 Freight Street, Centurion',
            'retention_period_months' => 12,
            'retention_expires_at' => now()->addMonths(8),
            'legal_hold' => false,
            'status' => 'active',
        ]);

        BusinessClient::query()->updateOrCreate([
            'client_account_id' => $accounts['dealer']->id,
            'business_name' => 'Mokoena Cold Chain',
        ], [
            'usable_as' => 'owner',
            'proxy_name' => 'Sipho Mokoena',
            'address' => '90 Cold Store Road, Pretoria',
            'retention_period_months' => 6,
            'retention_expires_at' => now()->addDays(12),
            'legal_hold' => false,
            'status' => 'active',
        ]);

        $southern = BusinessClient::query()->updateOrCreate([
            'client_account_id' => $accounts['dealer']->id,
            'business_name' => 'Southern Cross Bank Vehicle Finance',
        ], [
            'usable_as' => 'title_holder',
            // Banks are shared across every dealership on the platform
            // so the next dealer onboarded sees Southern Cross on day one
            // without re-typing it (and risking a typo).
            'is_shared' => true,
            'address' => '1 Bank Lane, Johannesburg',
            'retention_period_months' => 24,
            'retention_expires_at' => now()->addMonths(20),
            'legal_hold' => false,
            'status' => 'active',
        ]);

        $meridian = BusinessClient::query()->updateOrCreate([
            'client_account_id' => $accounts['dealer']->id,
            'business_name' => 'Meridian Asset Finance',
        ], [
            'usable_as' => 'title_holder',
            'is_shared' => true,
            'address' => '44 Commissioner Street, Johannesburg',
            'retention_period_months' => 24,
            'retention_expires_at' => now()->addMonths(18),
            'legal_hold' => false,
            'status' => 'active',
        ]);

        if (! $ridgeline->consents()->exists()) {
            $ridgeline->consents()->create([
                'period_months' => 12,
                'confirmed_at' => now()->subMonths(4),
                'wording_version' => '1',
            ]);
        }

        if (Application::query()->withoutGlobalScopes()->where('reference', 'like', 'EXL-2026-%')->exists()) {
            return;
        }

        $stages = [
            'draft', 'draft', 'changes_requested', 'document_review', 'document_review', 'document_review',
            'quote_required', 'quote_sent', 'quote_accepted', 'payment_pending', 'payment_pending', 'payment_verified',
            'datafix_in_progress', 'submitted_to_authority', 'authority_query', 'approved', 'ready_for_collection',
            'ready_for_collection', 'completed', 'completed', 'cancelled', 'document_review', 'draft', 'payment_pending',
            'submitted_to_authority', 'quote_sent', 'datafix_in_progress', 'approved', 'completed', 'document_review',
        ];

        $makes = ['Hino', 'UD', 'Mercedes-Benz', 'Volvo', 'Scania', 'FAW'];

        foreach ($stages as $index => $stage) {
            $account = match (true) {
                $index < 20 => $accounts['dealer'],
                $index < 24 => $accounts['oem'],
                $index < 27 => $accounts['fleet'],
                default => $accounts['body'],
            };

            $commercial = $index % 3 !== 1;
            $request = match ($index) {
                6, 7 => 'import',
                8 => 'export',
                14 => 'data_change',
                21 => 'licence_renewal',
                default => 'new_registration',
            };

            $application = Application::query()->create([
                'reference' => sprintf('EXL-2026-%05d', $index + 1),
                'client_account_id' => $account->id,
                'request_type' => $request,
                'service_type' => in_array($request, ['import', 'export'], true) ? null : ($index % 4 === 0 ? 'register_only' : 'register_and_license'),
                'vehicle_category' => $commercial ? 'commercial' : 'passenger',
                'owner_type' => 'business',
                'province' => 'gauteng',
                'is_financed' => $account->is($accounts['dealer']) && $index % 2 === 0,
                'stage' => $stage,
                'assigned_reviewer_id' => in_array($stage, ['document_review', 'datafix_in_progress', 'authority_query'], true) ? $reviewer->id : null,
                'submitted_at' => $stage === 'draft' ? null : now()->subDays(30 - $index),
                'due_at' => in_array($stage, ['completed', 'cancelled', 'archived'], true) ? null : now()->addHours(48),
            ]);

            if ($application->is_financed) {
                $application->business_client_id = $ridgeline->id;
                $application->title_holder_business_client_id = $index % 4 === 0 ? $southern->id : $meridian->id;
                $application->save();
            } elseif ($account->is($accounts['dealer'])) {
                $application->business_client_id = $ridgeline->id;
                $application->save();
            }

            $application->vehicle()->create([
                'vin' => $this->vin($index + 1),
                'vehicle_register_number' => sprintf('TLX%03dG', 100 + $index),
                'make' => $makes[$index % count($makes)],
                'model' => $commercial ? '500 1627' : 'Corolla',
                'year' => 2024,
                'body_type' => $commercial ? 'Dropside' : 'Sedan',
                'tare_kg' => $commercial ? 8420 : 1280,
                'gvm_kg' => $commercial ? 16000 : 1800,
            ]);

            app(ResolveRequiredDocuments::class)->handle($application->refresh());

            if (! in_array($stage, ['draft'], true)) {
                $application->documents()->where('required', true)->update(['status' => 'accepted']);
            }

            if ($stage === 'changes_requested') {
                $document = $application->documents()->where('required', true)->first();
                $document?->update([
                    'status' => 'rejected',
                    'rejection_reason' => 'illegible',
                    'reviewer_comment' => 'The scan is too dark to read.',
                ]);
            }

            app(SyncDatafixStatus::class)->handle($application->refresh());

            $later = ['submitted_to_authority', 'authority_query', 'approved', 'ready_for_collection', 'completed'];

            if ($commercial && in_array($stage, $later, true)) {
                $application->datafix_status = DatafixStatus::Completed;
                $application->save();
                $application->datafix()->updateOrCreate(['application_id' => $application->id], [
                    'status' => DatafixStatus::Completed,
                    'tare_kg' => 8420,
                    'body_type' => 'Dropside',
                    'gvm_kg' => 16000,
                    'client_tare_kg' => 8420,
                    'client_body_type' => 'Dropside',
                    'client_gvm_kg' => 16000,
                    'confirmed_at' => now()->subDay(),
                    'completed_at' => now()->subDay(),
                    'authority_reference' => 'NAT-'.$application->id,
                ]);
            }

            if ($stage === 'datafix_in_progress') {
                $application->datafix()->updateOrCreate(['application_id' => $application->id], [
                    'status' => DatafixStatus::Ready,
                    'client_tare_kg' => 8420,
                    'client_body_type' => 'Dropside',
                    'client_gvm_kg' => 16000,
                ]);
            }

            $needsMoney = ['payment_pending', 'payment_verified', 'datafix_in_progress', 'submitted_to_authority', 'authority_query', 'approved', 'ready_for_collection', 'completed'];

            if (in_array($stage, $needsMoney, true)) {
                $application->fee_snapshot = app(CalculateFees::class)->snapshot($application->fresh(['vehicle', 'clientAccount']));
                $application->save();
            }

            if (in_array($stage, ['quote_sent', 'quote_accepted'], true)) {
                $quote = $application->quotes()->create([
                    'status' => $stage === 'quote_sent' ? 'sent' : 'accepted',
                    'expires_at' => now()->addDays(10),
                    'client_notes' => 'Includes the runner and the authority fee.',
                    'internal_notes' => 'Hold the runner until the client accepts.',
                    'created_by' => $reviewer->id,
                ]);
                $quote->lines()->create([
                    'description' => 'Import handling',
                    'client_price_cents' => 185000,
                    'internal_cost_cents' => 90000,
                ]);
            }

            if (in_array($stage, ['payment_verified', 'datafix_in_progress', 'submitted_to_authority', 'authority_query', 'approved', 'ready_for_collection', 'completed'], true)) {
                $application->payments()->create([
                    'amount_cents' => (int) ($application->fee_snapshot['total_cents'] ?? 0),
                    'method' => 'eft',
                    'reference' => 'PAY-'.$application->reference,
                    'verified_at' => now()->subDay(),
                ]);
            }

            if ($index === 3) {
                $application->notes()->create([
                    'body' => 'Please confirm the body builder certificate is the latest issue.',
                    'visibility' => 'client',
                    'user_id' => $reviewer->id,
                ]);
                $application->notes()->create([
                    'body' => 'Runner is booked for Thursday.',
                    'visibility' => 'internal',
                    'user_id' => $reviewer->id,
                ]);
                $application->forceFill([
                    'due_at' => now()->subHour(),
                    'updated_at' => now()->subDays(2),
                ])->saveQuietly();
            }

            if ($index === 4) {
                $application->forceFill([
                    'due_at' => now()->addHours(6),
                    'updated_at' => now()->subHours(30),
                ])->saveQuietly();
            }

            if ($stage === 'cancelled') {
                $application->cancelled_reason = 'The dealer withdrew the deal.';
                $application->save();
            }
        }
    }

    private function user(string $name, string $email, string $role, ?int $accountId = null): User
    {
        $user = User::query()->updateOrCreate(['email' => $email], [
            'name' => $name,
            'password' => 'password',
            'client_account_id' => $accountId,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $user->syncRoles([$role]);

        return $user;
    }

    private function vin(int $seed): string
    {
        $alphabet = 'ABCDEFGHJKLMNPRSTUVWXYZ0123456789';
        $length = strlen($alphabet);
        $value = '';
        $state = $seed + 19;

        for ($i = 0; $i < 17; $i++) {
            $state = ($state * 31 + $i * 17) % $length;
            $value .= $alphabet[$state];
        }

        return $value;
    }
}
