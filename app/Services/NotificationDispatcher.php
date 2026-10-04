<?php

namespace App\Services;

use App\Models\Application;
use App\Models\BrandingSetting;
use App\Models\ClientAccount;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\ApplicationReadyForCollection;
use App\Notifications\AuthoritySubmittedForDealer;
use App\Notifications\ChangesRequestedForDealer;
use App\Notifications\MailgunTestNotification;
use App\Notifications\PaymentVerifiedForDealer;
use App\Notifications\QuoteSentToDealer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Fan workflow events out to Mailgun via Laravel's Notification facade.
 *
 * Every dispatch is gated by the admin "notifications enabled" toggle,
 * wrapped in DB::afterCommit so a rolled-back transaction never fires an
 * email, and swallows send errors so a bad Mailgun config can't block
 * the business action that triggered the notification.
 */
class NotificationDispatcher
{
    public function quoteSent(Application $application, Quote $quote): void
    {
        $this->dispatch($application->clientAccount, function (array $emails) use ($application, $quote): void {
            Notification::route('mail', $emails)->notify(new QuoteSentToDealer($application, $quote, BrandingSetting::current()));
        });
    }

    public function changesRequested(Application $application, ?string $reason = null): void
    {
        $this->dispatch($application->clientAccount, function (array $emails) use ($application, $reason): void {
            Notification::route('mail', $emails)->notify(new ChangesRequestedForDealer($application, $reason, BrandingSetting::current()));
        });
    }

    public function paymentVerified(Application $application, Payment $payment): void
    {
        $this->dispatch($application->clientAccount, function (array $emails) use ($application, $payment): void {
            Notification::route('mail', $emails)->notify(new PaymentVerifiedForDealer($application, $payment, BrandingSetting::current()));
        });
    }

    public function authoritySubmitted(Application $application): void
    {
        $this->dispatch($application->clientAccount, function (array $emails) use ($application): void {
            Notification::route('mail', $emails)->notify(new AuthoritySubmittedForDealer($application, BrandingSetting::current()));
        });
    }

    public function readyForCollection(Application $application): void
    {
        $this->dispatch($application->clientAccount, function (array $emails) use ($application): void {
            Notification::route('mail', $emails)->notify(new ApplicationReadyForCollection($application, BrandingSetting::current()));
        });
    }

    /**
     * Deliver a bespoke test email from the admin "Send test email" button.
     * Bypasses the enabled toggle so an admin can validate credentials even
     * while notifications are globally paused.
     */
    public function sendTestEmail(string $recipient): void
    {
        $branding = BrandingSetting::current();

        Notification::route('mail', $recipient)
            ->notify(new MailgunTestNotification($branding));
    }

    /**
     * @param  callable(list<string>): void  $callback
     */
    private function dispatch(?ClientAccount $account, callable $callback): void
    {
        if ($account === null) {
            return;
        }

        if (! SystemSetting::current()->notifications_enabled) {
            return;
        }

        $emails = $this->resolveRecipients($account);

        if (empty($emails)) {
            return;
        }

        DB::afterCommit(function () use ($emails, $callback): void {
            try {
                $callback($emails);
            } catch (\Throwable $exception) {
                Log::warning('notification.dispatch_failed', [
                    'exception' => $exception->getMessage(),
                    'recipients' => $emails,
                ]);
            }
        });
    }

    /** @return list<string> */
    private function resolveRecipients(ClientAccount $account): array
    {
        $contact = $account->contact_email;
        $admins = User::query()
            ->where('client_account_id', $account->id)
            ->whereHas('roles', fn ($q) => $q->where('name', 'client_admin'))
            ->where('is_active', true)
            ->pluck('email')
            ->all();

        $emails = array_filter(array_unique(array_merge(
            $contact ? [$contact] : [],
            $admins,
        )));

        return array_values($emails);
    }
}
