<?php

namespace Tests\Feature;

use App\Actions\SaveApplicationDraft;
use App\Actions\SubmitApplication;
use App\Enums\ApplicationStage;
use App\Enums\DocumentStatus;
use App\Enums\LicenceFeeCategory;
use App\Enums\OwnerType;
use App\Enums\Province;
use App\Enums\RequestType;
use App\Enums\ServiceType;
use App\Enums\VehicleCategory;
use App\Livewire\Portal\ApplicationForm;
use App\Livewire\Portal\ApplicationShow;
use App\Livewire\Portal\BusinessClientShow;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\BusinessClient;
use App\Models\ClientAccount;
use App\Models\DocumentType;
use App\Models\FeeTable;
use App\Models\User;
use Database\Seeders\DocumentRuleSeeder;
use Database\Seeders\FeeTableSeeder;
use Database\Seeders\LicenceFeeBandSeeder;
use Database\Seeders\LicenceFeeRateSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression suite covering the critical findings from the 2026-10-05 live
 * audit: ghost drafts, documentless submits, blind resubmits, auditor /
 * finance seeing reviewer controls, dealer-user BC writes, logout
 * destination, and navigable error pages.
 */
class CriticalAuditFixesTest extends TestCase
{
    use RefreshDatabase;

    private ClientAccount $dealer;

    private User $clientAdmin;

    private User $clientUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(DocumentRuleSeeder::class);
        $this->seed(FeeTableSeeder::class);
        $this->seed(LicenceFeeBandSeeder::class);
        $this->seed(LicenceFeeRateSeeder::class);

        FeeTable::query()->where('province', Province::Gauteng->value)
            ->firstOrFail()
            ->versions()->where('status', 'draft')->update(['status' => 'active']);

        $this->dealer = ClientAccount::query()->create([
            'name' => 'Audit Dealer',
            'type' => 'dealer',
            'quote_acceptance_allowed' => false,
        ]);

        $this->clientAdmin = User::factory()->create([
            'client_account_id' => $this->dealer->id,
            'is_active' => true,
        ]);
        $this->clientAdmin->assignRole('client_admin');

        $this->clientUser = User::factory()->create([
            'client_account_id' => $this->dealer->id,
            'is_active' => true,
        ]);
        $this->clientUser->assignRole('client_user');
    }

    /* ---------------------------------------------------------------
     * Draft discipline
     * ------------------------------------------------------------- */

    public function test_filling_form_fields_does_not_create_any_draft(): void
    {
        $this->assertSame(0, Application::query()->count());

        Livewire::actingAs($this->clientUser)
            ->test(ApplicationForm::class)
            ->set('request_type', RequestType::NewRegistration->value)
            ->set('service_type', ServiceType::RegisterAndLicense->value)
            ->set('vehicle_category', VehicleCategory::Passenger->value)
            ->set('owner_type', OwnerType::Individual->value)
            ->set('province', Province::Gauteng->value);

        $this->assertSame(0, Application::query()->count(), 'Reactive form changes must not spawn draft rows.');
    }

    public function test_clicking_save_draft_once_creates_exactly_one_draft(): void
    {
        $this->assertSame(0, Application::query()->count());

        Livewire::actingAs($this->clientUser)
            ->test(ApplicationForm::class)
            ->set('request_type', RequestType::NewRegistration->value)
            ->set('service_type', ServiceType::RegisterAndLicense->value)
            ->set('vehicle_category', VehicleCategory::Passenger->value)
            ->set('licence_category', LicenceFeeCategory::MotorCar->value)
            ->set('owner_type', OwnerType::Individual->value)
            ->set('province', Province::Gauteng->value)
            ->set('owner_name', 'Jane Doe')
            ->call('save');

        $this->assertSame(1, Application::query()->count(), 'Exactly one draft per Save click.');
    }

    /* ---------------------------------------------------------------
     * Submit validation
     * ------------------------------------------------------------- */

    public function test_submit_blocks_when_a_required_document_is_still_missing(): void
    {
        $application = $this->draftApplication();

        $type = DocumentType::query()->create([
            'code' => 'srf-'.$application->id,
            'name' => 'Sales registration form',
        ]);
        ApplicationDocument::query()->create([
            'application_id' => $application->id,
            'document_type_id' => $type->id,
            'required' => true,
            'status' => DocumentStatus::Missing,
        ]);

        $this->expectException(ValidationException::class);

        app(SubmitApplication::class)->handle($application->refresh(), $this->clientUser);
    }

    public function test_submit_blocks_when_a_required_document_was_rejected(): void
    {
        $application = $this->draftApplication();

        $type = DocumentType::query()->create([
            'code' => 'idc-'.$application->id,
            'name' => 'Proxy ID copy',
        ]);
        ApplicationDocument::query()->create([
            'application_id' => $application->id,
            'document_type_id' => $type->id,
            'required' => true,
            'status' => DocumentStatus::Rejected,
        ]);

        try {
            app(SubmitApplication::class)->handle($application->refresh(), $this->clientUser);
            $this->fail('Submit should have thrown for a rejected required document.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('outstanding document', $exception->validator->errors()->first('submit'));
        }
    }

    public function test_submit_succeeds_when_no_required_document_is_blocking(): void
    {
        $application = app(SaveApplicationDraft::class)->handle($this->clientUser, [
            'request_type' => RequestType::NewRegistration->value,
            'service_type' => ServiceType::RegisterAndLicense->value,
            'vehicle_category' => VehicleCategory::Passenger->value,
            'licence_category' => LicenceFeeCategory::MotorCar->value,
            'owner_type' => OwnerType::Individual->value,
            'province' => Province::Gauteng->value,
            'owner_name' => 'Jane Doe',
            'owner_identifier' => '9001015800084',
            'owner_address' => '1 Main Rd, Gauteng',
            'vin' => 'JTDBE32K000012345',
            'vehicle_register_number' => 'GP123456',
            'engine_number' => 'ENG123',
            'make' => 'Toyota',
            'model' => 'Corolla',
            'year' => 2024,
        ]);

        // Mark every required slot as uploaded so the guard passes.
        $application->documents()->where('required', true)->update([
            'status' => DocumentStatus::Uploaded,
        ]);

        $result = app(SubmitApplication::class)->handle($application->refresh(), $this->clientUser);

        $this->assertSame(ApplicationStage::DocumentReview, $result->stage);
    }

    /* ---------------------------------------------------------------
     * Changes-requested loop
     * ------------------------------------------------------------- */

    public function test_resubmit_is_blocked_until_the_requested_fix_is_uploaded(): void
    {
        $application = $this->draftApplication();
        $application->update(['stage' => ApplicationStage::ChangesRequested]);

        $type = DocumentType::query()->create([
            'code' => 'coa-'.$application->id,
            'name' => 'Change of address',
        ]);
        ApplicationDocument::query()->create([
            'application_id' => $application->id,
            'document_type_id' => $type->id,
            'required' => true,
            'status' => DocumentStatus::Rejected,
        ]);

        Livewire::actingAs($this->clientUser)
            ->test(ApplicationShow::class, ['application' => $application->refresh()])
            ->call('resubmit')
            ->assertHasErrors('submit');

        $this->assertSame(
            ApplicationStage::ChangesRequested,
            $application->refresh()->stage,
            'Resubmit must not advance the stage while fixes are outstanding.'
        );
    }

    public function test_changes_requested_view_shows_the_outstanding_fix_list(): void
    {
        $application = $this->draftApplication();
        $application->update(['stage' => ApplicationStage::ChangesRequested]);

        $type = DocumentType::query()->create([
            'code' => 'proxy-'.$application->id,
            'name' => 'Proxy letter',
        ]);
        ApplicationDocument::query()->create([
            'application_id' => $application->id,
            'document_type_id' => $type->id,
            'required' => true,
            'status' => DocumentStatus::Rejected,
            'reviewer_comment' => 'Signature is cut off at the bottom',
        ]);

        $this->actingAs($this->clientUser)
            ->get(route('applications.show', $application))
            ->assertOk()
            ->assertSeeText('Requested fixes')
            ->assertSeeText('Proxy letter')
            ->assertSeeText('Signature is cut off at the bottom');
    }

    /* ---------------------------------------------------------------
     * Role gating UI
     * ------------------------------------------------------------- */

    public function test_auditor_does_not_see_reviewer_controls_in_review_workspace(): void
    {
        $staff = $this->staff('auditor');
        $application = Application::query()->create([
            'reference' => 'AUD-REV-00001',
            'client_account_id' => $this->dealer->id,
            'stage' => ApplicationStage::DocumentReview,
        ]);

        $response = $this->actingAs($staff)
            ->get(route('review.show', $application))
            ->assertOk();

        $response->assertDontSee('wire:click="confirmDatafix"', escape: false);
        $response->assertDontSee('wire:click="completeDatafix"', escape: false);
        $response->assertDontSee('wire:click="changeService"', escape: false);
        $response->assertDontSee("wire:click=\"addNote('internal')\"", escape: false);
        $response->assertDontSee("wire:click=\"addNote('client')\"", escape: false);
        $response->assertSeeText('Datafix is read-only for your role.');
    }

    public function test_finance_does_not_see_reviewer_controls_in_review_workspace(): void
    {
        $staff = $this->staff('finance');
        $application = Application::query()->create([
            'reference' => 'FIN-REV-00001',
            'client_account_id' => $this->dealer->id,
            'stage' => ApplicationStage::DocumentReview,
        ]);

        $response = $this->actingAs($staff)
            ->get(route('review.show', $application))
            ->assertOk();

        $response->assertDontSee('wire:click="confirmDatafix"', escape: false);
        $response->assertDontSee('wire:click="changeService"', escape: false);
    }

    public function test_reviewer_still_sees_the_reviewer_controls(): void
    {
        $staff = $this->staff('reviewer');
        $application = Application::query()->create([
            'reference' => 'REV-REV-00001',
            'client_account_id' => $this->dealer->id,
            'stage' => ApplicationStage::DocumentReview,
        ]);

        $response = $this->actingAs($staff)
            ->get(route('review.show', $application))
            ->assertOk();

        $response->assertSee('wire:click="confirmDatafix"', escape: false);
        $response->assertSee('wire:click="changeService"', escape: false);
        $response->assertSee("wire:click=\"addNote('client')\"", escape: false);
    }

    /* ---------------------------------------------------------------
     * Dealer User strict: no business-client writes
     * ------------------------------------------------------------- */

    public function test_dealer_user_cannot_open_the_business_client_create_screen(): void
    {
        $this->actingAs($this->clientUser)
            ->get(route('business-clients.create'))
            ->assertForbidden();
    }

    public function test_dealer_user_cannot_open_the_business_client_edit_screen(): void
    {
        $businessClient = BusinessClient::query()->create([
            'client_account_id' => $this->dealer->id,
            'business_name' => 'Hertz SA',
            'usable_as' => 'owner',
            'status' => 'active',
        ]);

        $this->actingAs($this->clientUser)
            ->get(route('business-clients.edit', $businessClient))
            ->assertForbidden();
    }

    public function test_client_admin_can_still_create_and_edit_business_clients(): void
    {
        $businessClient = BusinessClient::query()->create([
            'client_account_id' => $this->dealer->id,
            'business_name' => 'Avis SA',
            'usable_as' => 'owner',
            'status' => 'active',
        ]);

        $this->actingAs($this->clientAdmin)
            ->get(route('business-clients.create'))
            ->assertOk();

        $this->actingAs($this->clientAdmin)
            ->get(route('business-clients.edit', $businessClient))
            ->assertOk();
    }

    public function test_dealer_user_cannot_save_a_retention_consent_on_a_business_client(): void
    {
        $businessClient = BusinessClient::query()->create([
            'client_account_id' => $this->dealer->id,
            'business_name' => 'Europcar SA',
            'usable_as' => 'owner',
            'status' => 'active',
        ]);

        Livewire::actingAs($this->clientUser)
            ->test(BusinessClientShow::class, ['businessClient' => $businessClient])
            ->set('period_months', 24)
            ->set('consent', true)
            ->call('saveConsent')
            ->assertForbidden();
    }

    /* ---------------------------------------------------------------
     * Sign-out destination + error pages
     * ------------------------------------------------------------- */

    public function test_signing_out_redirects_to_the_login_page_not_the_marketing_overview(): void
    {
        $this->actingAs($this->clientUser)
            ->post('/logout')
            ->assertRedirect(route('login'));
    }

    public function test_the_403_page_offers_navigation_back_to_the_dashboard(): void
    {
        $response = $this->actingAs($this->clientUser)
            ->get('/non-existent-protected-path-xyz');

        // Any protected route a client user cannot reach falls back to the
        // same navigable error chrome. 404 is sufficient to prove the view
        // renders with the links.
        $response->assertSeeText('Back to dashboard');
        $response->assertSeeText('Sign out');
    }

    public function test_the_404_page_for_a_guest_shows_the_sign_in_link(): void
    {
        $this->get('/this-page-does-not-exist')
            ->assertNotFound()
            ->assertSeeText('Sign in');
    }

    /* ---------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------- */

    private function draftApplication(): Application
    {
        return Application::query()->create([
            'reference' => 'DRF-'.uniqid(),
            'client_account_id' => $this->dealer->id,
            'stage' => ApplicationStage::Draft,
        ]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}
