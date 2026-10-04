<?php

namespace App\Services;

use App\Models\SystemSetting;

/**
 * Thin, cacheable accessor for the deployment feature switches.
 *
 * Call sites use this instead of poking SystemSetting directly so the
 * flag name and default live in exactly one place and so a test can
 * flip them cheaply with FeatureFlags::swap().
 */
class FeatureFlags
{
    private static ?bool $quotesEnabledOverride = null;

    private static ?bool $paymentTrackingOverride = null;

    public static function quotesEnabled(): bool
    {
        if (self::$quotesEnabledOverride !== null) {
            return self::$quotesEnabledOverride;
        }

        return (bool) SystemSetting::current()->quotes_enabled;
    }

    public static function paymentTrackingRequired(): bool
    {
        if (self::$paymentTrackingOverride !== null) {
            return self::$paymentTrackingOverride;
        }

        return (bool) SystemSetting::current()->payment_tracking_required;
    }

    /**
     * Test helper: pass null to clear the override.
     */
    public static function swapQuotesEnabled(?bool $value): void
    {
        self::$quotesEnabledOverride = $value;
    }

    public static function swapPaymentTrackingRequired(?bool $value): void
    {
        self::$paymentTrackingOverride = $value;
    }
}
