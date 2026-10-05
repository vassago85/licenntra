<?php

namespace App\Jobs;

use App\Actions\RecordAudit;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Scrubs identifying fields from staff users once their 5-year retention
 * window (see {@see User::RETENTION_YEARS}) has elapsed. The row itself is
 * kept so historical attribution in audit events and other foreign keys
 * remains intact.
 */
class AnonymiseOffboardedStaff implements ShouldQueue
{
    use Queueable;

    public function handle(RecordAudit $audit): void
    {
        User::query()
            ->whereNotNull('offboarded_at')
            ->whereNull('anonymised_at')
            ->where('offboarded_at', '<=', now()->subYears(User::RETENTION_YEARS))
            ->orderBy('id')
            ->chunkById(100, function ($users) use ($audit): void {
                foreach ($users as $user) {
                    $this->anonymise($user, $audit);
                }
            });
    }

    private function anonymise(User $user, RecordAudit $audit): void
    {
        DB::transaction(function () use ($user, $audit): void {
            $before = [
                'name' => $user->name,
                'email' => $user->email,
            ];

            $user->forceFill([
                'name' => "Former staff #{$user->id}",
                'email' => "offboarded-{$user->id}@retained.local",
                'email_verified_at' => null,
                'password' => Hash::make(Str::random(64)),
                'remember_token' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'anonymised_at' => now(),
            ])->save();

            $audit->handle(
                null,
                $user,
                'staff.anonymised',
                "Offboarded staff record anonymised after {$this->retentionYearsLabel()}.",
                $before,
                ['name' => $user->name, 'email' => $user->email],
                true,
            );
        });
    }

    private function retentionYearsLabel(): string
    {
        return User::RETENTION_YEARS.' year retention window';
    }
}
