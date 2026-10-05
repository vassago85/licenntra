<?php

use App\Livewire\Portal\Admin\Branding;
use App\Livewire\Portal\Admin\SystemSettings;
use App\Models\BrandingSetting;
use App\Models\SystemSetting as SystemSettingModel;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
|------------------------------------------------------------------------
| The former Filament "Branding" and "System settings" pages now live in
| the portal shell. Both must only be reachable by users who can
| configure the licensing company (super_admin / customer_admin); every
| other role gets 403 at mount.
|------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);

    $this->superAdmin = User::factory()->create(['is_active' => true]);
    $this->superAdmin->assignRole('super_admin');

    $this->customerAdmin = User::factory()->create(['is_active' => true]);
    $this->customerAdmin->assignRole('customer_admin');

    $this->reviewer = User::factory()->create(['is_active' => true]);
    $this->reviewer->assignRole('reviewer');

    $this->finance = User::factory()->create(['is_active' => true]);
    $this->finance->assignRole('finance');
});

/*
|------------------------------------------------------------------------
| Role gates
|------------------------------------------------------------------------
*/

it('lets a super admin reach the Branding page', function (): void {
    $this->actingAs($this->superAdmin)
        ->get(route('settings.branding'))
        ->assertOk();
});

it('lets a customer admin reach the Branding page', function (): void {
    $this->actingAs($this->customerAdmin)
        ->get(route('settings.branding'))
        ->assertOk();
});

it('forbids a reviewer from the Branding page', function (): void {
    $this->actingAs($this->reviewer)
        ->get(route('settings.branding'))
        ->assertForbidden();
});

it('forbids a finance user from the Branding page', function (): void {
    $this->actingAs($this->finance)
        ->get(route('settings.branding'))
        ->assertForbidden();
});

it('redirects a guest from the Branding page to login', function (): void {
    $this->get(route('settings.branding'))->assertRedirect(route('login'));
});

it('lets a super admin reach the System settings page', function (): void {
    $this->actingAs($this->superAdmin)
        ->get(route('settings.system'))
        ->assertOk();
});

it('forbids a reviewer from the System settings page', function (): void {
    $this->actingAs($this->reviewer)
        ->get(route('settings.system'))
        ->assertForbidden();
});

/*
|------------------------------------------------------------------------
| Branding - save
|------------------------------------------------------------------------
*/

it('saves branding and reflects the new values on the model', function (): void {
    $this->actingAs($this->superAdmin);

    Livewire::test(Branding::class)
        ->set('company_name', 'Highveld Licensing')
        ->set('reference_prefix', 'HVL')
        ->set('primary_colour', '#1a2b3c')
        ->set('support_email', 'help@highveld.co.za')
        ->set('support_phone', '+27 11 555 0100')
        ->set('address', '12 Main Rd, Centurion')
        ->call('save')
        ->assertHasNoErrors();

    $branding = BrandingSetting::current();

    expect($branding->company_name)->toBe('Highveld Licensing')
        ->and($branding->reference_prefix)->toBe('HVL')
        ->and($branding->primary_colour)->toBe('#1a2b3c')
        ->and($branding->support_email)->toBe('help@highveld.co.za')
        ->and($branding->support_phone)->toBe('+27 11 555 0100')
        ->and($branding->address)->toBe('12 Main Rd, Centurion');
});

it('rejects a non-hex primary colour', function (): void {
    $this->actingAs($this->superAdmin);

    Livewire::test(Branding::class)
        ->set('company_name', 'Something')
        ->set('reference_prefix', 'SMT')
        ->set('primary_colour', 'notahex')
        ->call('save')
        ->assertHasErrors(['primary_colour']);
});

it('stores an uploaded logo on the public disk and keeps the path on the model', function (): void {
    Storage::fake('public');
    $this->actingAs($this->superAdmin);

    Livewire::test(Branding::class)
        ->set('company_name', 'Licentra')
        ->set('reference_prefix', 'LIC')
        ->set('primary_colour', '#146d61')
        ->set('logo_upload', UploadedFile::fake()->image('logo.png', 128, 128))
        ->call('save')
        ->assertHasNoErrors();

    $branding = BrandingSetting::current();

    expect($branding->logo_path)->not->toBeNull()
        ->and(Storage::disk('public')->exists($branding->logo_path))->toBeTrue();
});

/*
|------------------------------------------------------------------------
| System settings - save + validation
|------------------------------------------------------------------------
*/

it('saves system settings and converts VAT percent to basis points', function (): void {
    $this->actingAs($this->superAdmin);

    Livewire::test(SystemSettings::class)
        ->set('vat_percent', '15.00')
        ->set('idle_timeout_minutes', 30)
        ->set('absolute_timeout_minutes', 480)
        ->set('retention_period_options_input', '3, 6, 12, 24')
        ->set('retention_max_months', 24)
        ->set('archive_after_days', 90)
        ->set('retention_wording_version', '1')
        ->set('retention_wording', 'Consent wording.')
        ->set('notifications_enabled', true)
        ->call('save')
        ->assertHasNoErrors();

    $settings = SystemSettingModel::current();

    expect($settings->vat_basis_points)->toBe(1500)
        ->and($settings->retention_period_options)->toBe([3, 6, 12, 24])
        ->and($settings->notifications_enabled)->toBeTrue();
});

it('rejects an absolute timeout that is not larger than the idle timeout', function (): void {
    $this->actingAs($this->superAdmin);

    Livewire::test(SystemSettings::class)
        ->set('vat_percent', '15.00')
        ->set('idle_timeout_minutes', 60)
        ->set('absolute_timeout_minutes', 60)
        ->set('retention_period_options_input', '3')
        ->set('retention_max_months', 24)
        ->set('archive_after_days', 90)
        ->set('retention_wording_version', '1')
        ->set('retention_wording', 'x')
        ->call('save')
        ->assertHasErrors(['absolute_timeout_minutes']);
});

it('rejects a retention option that exceeds the maximum', function (): void {
    $this->actingAs($this->superAdmin);

    Livewire::test(SystemSettings::class)
        ->set('vat_percent', '15.00')
        ->set('idle_timeout_minutes', 30)
        ->set('absolute_timeout_minutes', 480)
        ->set('retention_period_options_input', '3, 6, 48')
        ->set('retention_max_months', 24)
        ->set('archive_after_days', 90)
        ->set('retention_wording_version', '1')
        ->set('retention_wording', 'x')
        ->call('save')
        ->assertHasErrors(['retention_period_options_input']);
});

it('rejects an empty retention option list', function (): void {
    $this->actingAs($this->superAdmin);

    Livewire::test(SystemSettings::class)
        ->set('vat_percent', '15.00')
        ->set('idle_timeout_minutes', 30)
        ->set('absolute_timeout_minutes', 480)
        ->set('retention_period_options_input', '  , , ')
        ->set('retention_max_months', 24)
        ->set('archive_after_days', 90)
        ->set('retention_wording_version', '1')
        ->set('retention_wording', 'x')
        ->call('save')
        ->assertHasErrors(['retention_period_options_input']);
});

it('leaves the stored Mailgun secret alone when the field is blank', function (): void {
    SystemSettingModel::current()->update(['mailgun_secret' => 'key-original']);
    $this->actingAs($this->superAdmin);

    Livewire::test(SystemSettings::class)
        ->set('vat_percent', '15.00')
        ->set('idle_timeout_minutes', 30)
        ->set('absolute_timeout_minutes', 480)
        ->set('retention_period_options_input', '3, 6, 12')
        ->set('retention_max_months', 24)
        ->set('archive_after_days', 90)
        ->set('retention_wording_version', '1')
        ->set('retention_wording', 'x')
        ->set('mailgun_secret', '')
        ->call('save')
        ->assertHasNoErrors();

    expect(SystemSettingModel::current()->mailgun_secret)->toBe('key-original');
});

it('replaces the stored Mailgun secret when a new value is typed', function (): void {
    SystemSettingModel::current()->update(['mailgun_secret' => 'key-original']);
    $this->actingAs($this->superAdmin);

    Livewire::test(SystemSettings::class)
        ->set('vat_percent', '15.00')
        ->set('idle_timeout_minutes', 30)
        ->set('absolute_timeout_minutes', 480)
        ->set('retention_period_options_input', '3, 6, 12')
        ->set('retention_max_months', 24)
        ->set('archive_after_days', 90)
        ->set('retention_wording_version', '1')
        ->set('retention_wording', 'x')
        ->set('mailgun_secret', 'key-new-value')
        ->call('save')
        ->assertHasNoErrors();

    expect(SystemSettingModel::current()->mailgun_secret)->toBe('key-new-value');
});
