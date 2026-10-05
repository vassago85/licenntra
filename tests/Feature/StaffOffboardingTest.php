<?php

namespace Tests\Feature;

use App\Actions\OffboardStaffMember;
use App\Actions\RecordAudit;
use App\Enums\OffboardReason;
use App\Exceptions\OffboardingNotAllowed;
use App\Jobs\AnonymiseOffboardedStaff;
use App\Models\AuditEvent;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class StaffOffboardingTest extends TestCase
{
    use RefreshDatabase;

    protected User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->actor = User::factory()->create(['is_active' => true]);
        $this->actor->assignRole('owner');

        // A second super admin so lone-admin guards don't fire during the suite.
        $spare = User::factory()->create(['is_active' => true]);
        $spare->assignRole('owner');
    }

    public function test_offboarding_kills_sessions_remember_token_two_factor_passkeys_and_roles(): void
    {
        $reviewer = $this->staff('reviewer', [
            'remember_token' => Str::random(60),
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['a', 'b'])),
            'two_factor_confirmed_at' => now(),
        ]);

        DB::table('sessions')->insert([
            'id' => Str::random(40),
            'user_id' => $reviewer->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'payload' => base64_encode('x'),
            'last_activity' => now()->timestamp,
        ]);

        DB::table('passkeys')->insert([
            'user_id' => $reviewer->id,
            'name' => 'yubikey',
            'credential_id' => Str::random(20),
            'credential' => json_encode(['x' => 1]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(OffboardStaffMember::class)->handle(
            $reviewer,
            OffboardReason::Resigned,
            'Moving to another firm.',
            $this->actor,
        );

        $reviewer->refresh();

        $this->assertFalse($reviewer->is_active);
        $this->assertNotNull($reviewer->offboarded_at);
        $this->assertSame(OffboardReason::Resigned, $reviewer->offboard_reason);
        $this->assertSame('Moving to another firm.', $reviewer->offboard_note);
        $this->assertSame($this->actor->id, $reviewer->offboarded_by_id);
        $this->assertNull($reviewer->remember_token);
        $this->assertNull($reviewer->two_factor_secret);
        $this->assertNull($reviewer->two_factor_recovery_codes);
        $this->assertNull($reviewer->two_factor_confirmed_at);
        $this->assertCount(0, $reviewer->roles);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $reviewer->id)->count());
        $this->assertSame(0, DB::table('passkeys')->where('user_id', $reviewer->id)->count());
    }

    public function test_offboarded_user_cannot_sign_in_and_cannot_open_the_admin_overview(): void
    {
        $reviewer = $this->staff('reviewer', ['password' => Hash::make('password')]);

        app(OffboardStaffMember::class)->handle(
            $reviewer,
            OffboardReason::Dismissed,
            null,
            $this->actor,
        );

        $this->post('/login', [
            'email' => $reviewer->email,
            'password' => 'password',
        ])->assertSessionHasErrors();

        $this->actingAs($reviewer->fresh());
        $this->get('/admin')->assertForbidden();
    }

    public function test_cannot_offboard_the_last_active_admin(): void
    {
        $soleAdmin = $this->staff('owner');

        // Strip all other active admins so this account is the last one.
        // The acting user intentionally has no admin role so it does not
        // "cover" for the admin we are about to offboard.
        User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['owner']))
            ->where('id', '!=', $soleAdmin->id)
            ->get()
            ->each(fn (User $u) => $u->update(['is_active' => false]));

        $actor = User::factory()->create(['is_active' => true]);
        $actor->assignRole('reviewer');

        $this->expectException(OffboardingNotAllowed::class);

        app(OffboardStaffMember::class)->handle(
            $soleAdmin,
            OffboardReason::Resigned,
            null,
            $actor,
        );
    }

    public function test_cannot_offboard_yourself(): void
    {
        $this->expectException(OffboardingNotAllowed::class);

        app(OffboardStaffMember::class)->handle(
            $this->actor,
            OffboardReason::Resigned,
            null,
            $this->actor,
        );
    }

    public function test_offboarding_writes_an_audit_event_with_reason_and_actor(): void
    {
        $finance = $this->staff('finance');

        app(OffboardStaffMember::class)->handle(
            $finance,
            OffboardReason::ContractEnded,
            'Fixed-term ended 31 Jan.',
            $this->actor,
        );

        $event = AuditEvent::query()
            ->where('action', 'staff.offboarded')
            ->where('subject_id', $finance->id)
            ->firstOrFail();

        $this->assertSame($this->actor->id, $event->actor_user_id);
        $this->assertSame('owner', $event->actor_role);
        $this->assertSame(User::class, $event->subject_type);
        $this->assertSame('contract_ended', $event->after['reason']);
        $this->assertSame('Fixed-term ended 31 Jan.', $event->after['note']);
        $this->assertSame(['finance'], $event->before['roles']);
    }

    public function test_anonymise_job_scrubs_users_past_five_years_and_leaves_recent_ones_alone(): void
    {
        $longAgo = $this->staff('reviewer');
        $longAgo->update(['name' => 'Old Timer', 'email' => 'old@firm.test']);
        app(OffboardStaffMember::class)->handle($longAgo, OffboardReason::Retired, null, $this->actor);
        $longAgo->forceFill([
            'offboarded_at' => now()->subYears(User::RETENTION_YEARS)->subDay(),
        ])->save();

        $recent = $this->staff('reviewer');
        $recent->update(['name' => 'Still Fresh', 'email' => 'fresh@firm.test']);
        app(OffboardStaffMember::class)->handle($recent, OffboardReason::Resigned, null, $this->actor);

        app(AnonymiseOffboardedStaff::class)->handle(app(RecordAudit::class));

        $longAgo->refresh();
        $recent->refresh();

        $this->assertNotNull($longAgo->anonymised_at);
        $this->assertSame("Former staff #{$longAgo->id}", $longAgo->name);
        $this->assertSame("offboarded-{$longAgo->id}@retained.local", $longAgo->email);
        $this->assertNull($longAgo->email_verified_at);

        $this->assertNull($recent->anonymised_at);
        $this->assertSame('Still Fresh', $recent->name);
        $this->assertSame('fresh@firm.test', $recent->email);
    }

    public function test_anonymise_job_is_idempotent_and_sets_anonymised_at(): void
    {
        $user = $this->staff('finance');
        app(OffboardStaffMember::class)->handle($user, OffboardReason::Resigned, null, $this->actor);
        $user->forceFill(['offboarded_at' => now()->subYears(10)])->save();

        app(AnonymiseOffboardedStaff::class)->handle(app(RecordAudit::class));
        $firstStamp = $user->fresh()->anonymised_at;
        $this->assertNotNull($firstStamp);

        app(AnonymiseOffboardedStaff::class)->handle(app(RecordAudit::class));

        $this->assertTrue($firstStamp->equalTo($user->fresh()->anonymised_at));
        $this->assertSame(1, AuditEvent::query()
            ->where('action', 'staff.anonymised')
            ->where('subject_id', $user->id)
            ->count());
    }

    public function test_audit_rows_retain_user_id_after_anonymisation(): void
    {
        $user = $this->staff('reviewer');
        app(OffboardStaffMember::class)->handle($user, OffboardReason::Dismissed, 'Policy breach.', $this->actor);

        $preAnonAudits = AuditEvent::query()->where('subject_id', $user->id)->count();
        $this->assertGreaterThan(0, $preAnonAudits);

        $user->forceFill(['offboarded_at' => now()->subYears(10)])->save();
        app(AnonymiseOffboardedStaff::class)->handle(app(RecordAudit::class));

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => "Former staff #{$user->id}"]);
        $this->assertSame(
            $preAnonAudits + 1,
            AuditEvent::query()->where('subject_id', $user->id)->count(),
            'Audit rows must survive anonymisation and gain one for the scrub itself.',
        );
    }

    public function test_offboarding_is_idempotent_and_does_not_double_write_audit_rows(): void
    {
        $user = $this->staff('finance');

        app(OffboardStaffMember::class)->handle($user, OffboardReason::Resigned, null, $this->actor);
        app(OffboardStaffMember::class)->handle($user, OffboardReason::Dismissed, 'Should be ignored.', $this->actor);

        $this->assertSame(1, AuditEvent::query()
            ->where('action', 'staff.offboarded')
            ->where('subject_id', $user->id)
            ->count());
        $this->assertSame(OffboardReason::Resigned, $user->fresh()->offboard_reason);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function staff(string $role, array $overrides = []): User
    {
        $user = User::factory()->create(array_merge(['is_active' => true], $overrides));
        $user->assignRole($role);

        return $user;
    }
}
