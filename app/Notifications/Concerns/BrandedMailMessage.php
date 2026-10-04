<?php

namespace App\Notifications\Concerns;

use App\Models\BrandingSetting;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Shared helpers for every workflow email so subject lines, greetings, and
 * sign-offs stay consistent and always carry the licensing company's name.
 */
trait BrandedMailMessage
{
    protected function newBrandedMailMessage(BrandingSetting $branding): MailMessage
    {
        return (new MailMessage)
            ->greeting('Hello,')
            ->salutation('Regards, '.$branding->company_name);
    }

    protected function brandedSubject(BrandingSetting $branding, string $event, string $suffix): string
    {
        return $branding->company_name.' | '.$event.' | '.$suffix;
    }

    protected function portalUrl(string $path = ''): string
    {
        return url(ltrim($path, '/'));
    }
}
