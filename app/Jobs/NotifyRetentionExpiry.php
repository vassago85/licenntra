<?php

namespace App\Jobs;

use App\Models\BrandingSetting;
use App\Models\BusinessClient;
use App\Notifications\RetentionExpiring;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;

class NotifyRetentionExpiry implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $branding = BrandingSetting::current();

        BusinessClient::query()
            ->with('clientAccount')
            ->where('legal_hold', false)
            ->whereNotNull('retention_expires_at')
            ->whereBetween('retention_expires_at', [now(), now()->addDays(30)])
            ->orderBy('id')
            ->each(function (BusinessClient $client) use ($branding): void {
                $recipients = array_filter([
                    $client->clientAccount?->contact_email,
                    $branding->support_email,
                ]);

                foreach (array_unique($recipients) as $email) {
                    Notification::route('mail', $email)->notify(new RetentionExpiring($client));
                }
            });
    }
}
