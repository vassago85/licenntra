<?php

namespace App\Actions;

use App\Enums\OffboardReason;
use App\Exceptions\OffboardingNotAllowed;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

class OffboardStaffMember
{
    private const ADMIN_ROLES = ['super_admin', 'customer_admin'];

    public function __construct(private RecordAudit $audit) {}

    /**
     * Offboard a staff member. Idempotent: calling again on an already
     * offboarded user is a no-op.
     *
     * @throws OffboardingNotAllowed
     */
    public function handle(
        User $user,
        OffboardReason $reason,
        ?string $note,
        User $actor,
    ): User {
        if ($user->isOffboarded()) {
            return $user;
        }

        $this->guard($user, $actor);

        return DB::transaction(function () use ($user, $reason, $note, $actor): User {
            $previousRoles = $user->roles()->pluck('name')->all();

            $user->forceFill([
                'is_active' => false,
                'offboarded_at' => now(),
                'offboard_reason' => $reason,
                'offboard_note' => $note,
                'offboarded_by_id' => $actor->id,
                'remember_token' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();

            $user->syncRoles([]);

            DB::table('sessions')->where('user_id', $user->id)->delete();

            if (Schema::hasTable('passkeys')) {
                DB::table('passkeys')->where('user_id', $user->id)->delete();
            }

            if (Schema::hasTable('personal_access_tokens')) {
                DB::table('personal_access_tokens')
                    ->where('tokenable_type', $user::class)
                    ->where('tokenable_id', $user->id)
                    ->delete();
            }

            $this->audit->handle(
                $actor,
                $user,
                'staff.offboarded',
                "{$user->name} offboarded ({$reason->label()}).",
                ['roles' => $previousRoles, 'is_active' => true],
                ['reason' => $reason->value, 'note' => $note],
            );

            return $user->refresh();
        });
    }

    private function guard(User $user, User $actor): void
    {
        if ($user->is($actor)) {
            throw new OffboardingNotAllowed('You cannot offboard yourself.');
        }

        if (! $user->isLicensingStaff()) {
            throw new OffboardingNotAllowed('Only licensing staff may be offboarded here.');
        }

        if ($this->wouldStrandAdmins($user)) {
            throw new OffboardingNotAllowed('At least one active super or customer admin must remain.');
        }
    }

    private function wouldStrandAdmins(User $candidate): bool
    {
        $adminRoleIds = Role::query()
            ->whereIn('name', self::ADMIN_ROLES)
            ->pluck('id');

        if (! $candidate->roles()->whereIn('id', $adminRoleIds)->exists()) {
            return false;
        }

        $remaining = User::query()
            ->where('is_active', true)
            ->whereNull('offboarded_at')
            ->where('id', '!=', $candidate->id)
            ->whereHas('roles', fn ($query) => $query->whereIn('id', $adminRoleIds))
            ->count();

        return $remaining === 0;
    }
}
