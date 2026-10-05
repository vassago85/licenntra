<?php

namespace App\Livewire\Portal\Admin;

use App\Actions\RecordAudit;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\PlatformBillingService;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Portal replacement for the former Filament "Platform billing" page.
 *
 * Shows the running completed-transaction count for the current month
 * plus the resulting bill (count * fee).
 *
 * - Owner (super_admin) sees the counter + bill, read-only on the fee.
 * - Developer (Charsley Digital) sees the same + can edit the per-
 *   transaction fee. Fee changes are audited.
 *
 * Deliberately off-limits to customer_admin / finance / reviewers /
 * auditors - this is private commercial info between the owner and the
 * developer.
 */
#[Layout('layouts.portal')]
class PlatformBilling extends Component
{
    /** Rand-denominated input bound to the fee editor. */
    public string $platformFeeRands = '0.00';

    public ?string $statusMessage = null;

    public function mount(): void
    {
        $user = $this->currentUser();
        abort_unless($user->hasAnyRole(['owner', 'developer']), 403);

        $this->platformFeeRands = $this->feeAsRands(
            (int) SystemSetting::current()->platform_fee_per_transaction_cents,
        );
    }

    public function saveFee(): void
    {
        $user = $this->currentUser();
        $this->statusMessage = null;

        abort_unless(
            $user->hasRole('developer'),
            403,
            'Only the developer can set the platform fee.',
        );

        $trimmed = trim($this->platformFeeRands);

        if ($trimmed === '' || ! is_numeric($trimmed) || (float) $trimmed < 0) {
            throw ValidationException::withMessages([
                'platformFeeRands' => 'Enter a fee in rands, zero or more.',
            ]);
        }

        $newCents = (int) round(((float) $trimmed) * 100);

        $setting = SystemSetting::current();
        $oldCents = (int) $setting->platform_fee_per_transaction_cents;

        if ($oldCents === $newCents) {
            $this->statusMessage = 'Platform fee unchanged. No audit entry recorded.';

            return;
        }

        $setting->update(['platform_fee_per_transaction_cents' => $newCents]);

        app(RecordAudit::class)->handle(
            $user,
            $setting,
            'platform.fee_changed',
            sprintf(
                'Per-transaction fee updated from %s to %s.',
                Money::rands($oldCents),
                Money::rands($newCents),
            ),
            ['platform_fee_per_transaction_cents' => $oldCents],
            ['platform_fee_per_transaction_cents' => $newCents],
        );

        $this->platformFeeRands = $this->feeAsRands($newCents);
        $this->statusMessage = 'Platform fee saved.';
    }

    public function render(): View
    {
        $service = app(PlatformBillingService::class);
        $user = $this->currentUser();

        return view('livewire.portal.admin.platform-billing', [
            'current' => $service->monthlyUsage(),
            'recent' => $service->recentMonths(6),
            'money' => Money::class,
            'canEditFee' => $user->hasRole('developer'),
            'viewerRole' => $user->hasRole('developer') ? 'Developer' : 'Owner',
        ]);
    }

    private function feeAsRands(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private function currentUser(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
