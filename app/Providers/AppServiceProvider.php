<?php

namespace App\Providers;

use App\Logging\RedactSouthAfricanIdNumbers;
use App\Models\SystemSetting;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Monolog\Logger;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Force every generated URL to https when the app is behind a
        // TLS-terminating proxy (e.g. Nginx Proxy Manager on the demo
        // deployment). We trigger on APP_URL rather than APP_ENV so the
        // local Laragon dev (http://localhost/licentra/public) stays on
        // http without any env ceremony.
        if (Str::startsWith((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        Event::listen(Login::class, function (): void {
            if (session()->isStarted()) {
                session(['absolute_session_started_at' => now()->timestamp]);
            }
        });

        $this->app->booted(function (): void {
            $logger = Log::getLogger();

            if ($logger instanceof Logger) {
                $logger->pushProcessor(new RedactSouthAfricanIdNumbers);
            }

            $this->applyMailgunSettings();
        });
    }

    /**
     * Allow the admin UI to override Mailgun credentials and from-address
     * at runtime. ENV values stay as the fallback so a fresh install still
     * sends mail. When the DB values are empty the ENV wins; when the DB
     * values are set they take over without a redeploy.
     */
    private function applyMailgunSettings(): void
    {
        try {
            if (! Schema::hasTable('system_settings')) {
                return;
            }

            $settings = SystemSetting::current();
        } catch (\Throwable) {
            return;
        }

        if ($settings->hasMailgunCredentials()) {
            Config::set('services.mailgun.domain', $settings->mailgun_domain);
            Config::set('services.mailgun.secret', $settings->mailgun_secret);

            if (filled($settings->mailgun_endpoint)) {
                Config::set('services.mailgun.endpoint', $settings->mailgun_endpoint);
            }

            Config::set('mail.default', 'mailgun');
        }

        if (filled($settings->mail_from_address)) {
            Config::set('mail.from.address', $settings->mail_from_address);
        }

        if (filled($settings->mail_from_name)) {
            Config::set('mail.from.name', $settings->mail_from_name);
        }
    }
}
