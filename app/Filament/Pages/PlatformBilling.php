<?php

namespace App\Filament\Pages;

use App\Actions\RecordAudit;
use App\Filament\Concerns\OnlyOwnerOrDeveloper;
use App\Models\SystemSetting;
use App\Services\PlatformBillingService;
use App\Support\Money;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Platform-side billing dashboard. Shows the running completed-transaction
 * count for the current month plus the resulting bill (count × fee).
 *
 * - Owner (super_admin) sees the counter + bill, read-only on the fee.
 * - Developer (Charsley Digital) sees the same + can edit the per-
 *   transaction fee. Fee changes are audited.
 *
 * Deliberately off-limits to customer_admin / finance / reviewers / auditors:
 * this is private commercial info between the owner and the developer.
 */
class PlatformBilling extends Page
{
    use OnlyOwnerOrDeveloper;

    protected string $view = 'filament.pages.platform-billing';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'Platform billing';

    protected static ?int $navigationSort = 90;

    protected static ?string $title = 'Platform billing';

    /** Rand-denominated input bound to the fee editor. */
    public string $platformFeeRands = '0.00';

    public function mount(): void
    {
        $this->platformFeeRands = $this->feeAsRands(
            SystemSetting::current()->platform_fee_per_transaction_cents,
        );
    }

    public function saveFee(): void
    {
        $user = auth()->user();

        abort_unless(
            $user !== null && $user->hasRole('developer'),
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
            Notification::make()
                ->title('Platform fee unchanged')
                ->body('No audit entry recorded.')
                ->send();

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

        Notification::make()
            ->title('Platform fee saved')
            ->success()
            ->send();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $service = app(PlatformBillingService::class);
        $user = auth()->user();

        return [
            'current' => $service->monthlyUsage(),
            'recent' => $service->recentMonths(6),
            'money' => Money::class,
            'canEditFee' => $user !== null && $user->hasRole('developer'),
            'viewerRole' => $user !== null && $user->hasRole('developer') ? 'Developer' : 'Owner',
        ];
    }

    private function feeAsRands(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
