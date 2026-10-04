<?php

namespace App\Actions;

use App\Models\BusinessClient;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class CaptureRetentionConsent
{
    public function __construct(private RecordAudit $audit) {}

    public function handle(BusinessClient $businessClient, User $actor, int $periodMonths, bool $confirmed): BusinessClient
    {
        if (! $confirmed) {
            throw ValidationException::withMessages([
                'consent' => 'Confirm that you have this business\'s authorisation to keep the documents.',
            ]);
        }

        $settings = SystemSetting::current();
        $options = array_map('intval', $settings->retention_period_options ?? []);

        if (! in_array($periodMonths, $options, true) || $periodMonths > $settings->retention_max_months) {
            throw ValidationException::withMessages([
                'period_months' => 'Choose an allowed retention period.',
            ]);
        }

        $businessClient->consents()->create([
            'user_id' => $actor->id,
            'period_months' => $periodMonths,
            'confirmed_at' => now(),
            'wording_version' => $settings->retention_wording_version,
        ]);

        $businessClient->retention_period_months = $periodMonths;
        $businessClient->retention_expires_at = now()->addMonths($periodMonths);
        $businessClient->save();

        $this->audit->handle(
            $actor,
            $businessClient,
            'retention.consented',
            'Retention consent recorded.',
            null,
            ['period_months' => $periodMonths, 'wording_version' => $settings->retention_wording_version],
        );

        return $businessClient->refresh();
    }
}
