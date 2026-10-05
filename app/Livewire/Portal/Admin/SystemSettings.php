<?php

namespace App\Livewire\Portal\Admin;

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\NotificationDispatcher;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Portal replacement for the former Filament "System settings" page.
 *
 * All configuration that applies to the licensing company as a whole:
 * finance, security, retention, and Mailgun transport. Any value saved
 * here overrides the matching MAIL_ / MAILGUN_ entries from .env at
 * runtime via AppServiceProvider::applyMailgunSettings().
 */
#[Layout('layouts.portal')]
class SystemSettings extends Component
{
    // Finance
    public string $vat_percent = '15.00';

    // Security
    public int $idle_timeout_minutes = 30;

    public int $absolute_timeout_minutes = 480;

    public bool $enforce_client_two_factor = false;

    // Retention
    public string $retention_period_options_input = '3, 6, 12, 24';

    public int $retention_max_months = 24;

    public int $archive_after_days = 90;

    public string $retention_wording_version = '1';

    public string $retention_wording = '';

    // Mail (Mailgun)
    public ?string $mailgun_domain = null;

    public ?string $mailgun_endpoint = null;

    /** Blank = keep stored key. Any typed value replaces it. */
    public string $mailgun_secret = '';

    public ?string $mail_from_address = null;

    public ?string $mail_from_name = null;

    public bool $notifications_enabled = true;

    // Test email
    public string $test_email_recipient = '';

    public ?string $statusMessage = null;

    public ?string $errorMessage = null;

    public function mount(): void
    {
        $user = $this->currentUser();
        abort_unless($user->canConfigure(), 403);

        $settings = SystemSetting::current();

        $this->vat_percent = number_format($settings->vat_basis_points / 100, 2, '.', '');
        $this->idle_timeout_minutes = (int) $settings->idle_timeout_minutes;
        $this->absolute_timeout_minutes = (int) $settings->absolute_timeout_minutes;
        $this->enforce_client_two_factor = (bool) $settings->enforce_client_two_factor;
        $this->retention_period_options_input = implode(', ', $settings->retention_period_options ?? []);
        $this->retention_max_months = (int) $settings->retention_max_months;
        $this->archive_after_days = (int) $settings->archive_after_days;
        $this->retention_wording_version = (string) $settings->retention_wording_version;
        $this->retention_wording = (string) $settings->retention_wording;
        $this->mailgun_domain = $settings->mailgun_domain;
        $this->mailgun_endpoint = $settings->mailgun_endpoint;
        $this->mail_from_address = $settings->mail_from_address;
        $this->mail_from_name = $settings->mail_from_name;
        $this->notifications_enabled = (bool) $settings->notifications_enabled;
        $this->test_email_recipient = (string) ($user->email ?? '');
    }

    public function save(): void
    {
        $this->resetErrorBag();
        $this->statusMessage = null;
        $this->errorMessage = null;

        $data = $this->validate([
            'vat_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'idle_timeout_minutes' => ['required', 'integer', 'min:5', 'max:240'],
            'absolute_timeout_minutes' => ['required', 'integer', 'min:30', 'max:1440'],
            'enforce_client_two_factor' => ['boolean'],
            'retention_period_options_input' => ['required', 'string'],
            'retention_max_months' => ['required', 'integer', 'min:1', 'max:120'],
            'archive_after_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'retention_wording_version' => ['required', 'string', 'max:20'],
            'retention_wording' => ['required', 'string'],
            'mailgun_domain' => ['nullable', 'string', 'max:190'],
            'mailgun_endpoint' => ['nullable', 'string', 'max:190'],
            'mail_from_address' => ['nullable', 'email', 'max:190'],
            'mail_from_name' => ['nullable', 'string', 'max:190'],
            'notifications_enabled' => ['boolean'],
        ]);

        $options = $this->parseRetentionOptions($data['retention_period_options_input']);

        if ($options === []) {
            $this->addError('retention_period_options_input', 'Add at least one retention period.');

            return;
        }

        if ($data['idle_timeout_minutes'] >= $data['absolute_timeout_minutes']) {
            $this->addError('absolute_timeout_minutes', 'The absolute timeout must be longer than the idle timeout.');

            return;
        }

        if (max($options) > $data['retention_max_months']) {
            $this->addError('retention_period_options_input', 'A retention option is above the maximum.');

            return;
        }

        $payload = [
            'vat_basis_points' => (int) round(((float) $data['vat_percent']) * 100),
            'idle_timeout_minutes' => (int) $data['idle_timeout_minutes'],
            'absolute_timeout_minutes' => (int) $data['absolute_timeout_minutes'],
            'retention_period_options' => $options,
            'retention_max_months' => (int) $data['retention_max_months'],
            'archive_after_days' => (int) $data['archive_after_days'],
            'enforce_client_two_factor' => (bool) ($data['enforce_client_two_factor'] ?? false),
            'retention_wording_version' => (string) $data['retention_wording_version'],
            'retention_wording' => (string) $data['retention_wording'],
            'mailgun_domain' => $this->emptyToNull($data['mailgun_domain'] ?? null),
            'mailgun_endpoint' => $this->emptyToNull($data['mailgun_endpoint'] ?? null),
            'mail_from_address' => $this->emptyToNull($data['mail_from_address'] ?? null),
            'mail_from_name' => $this->emptyToNull($data['mail_from_name'] ?? null),
            'notifications_enabled' => (bool) ($data['notifications_enabled'] ?? false),
        ];

        $newSecret = $this->emptyToNull($this->mailgun_secret);
        if ($newSecret !== null) {
            $payload['mailgun_secret'] = $newSecret;
        }

        SystemSetting::current()->update($payload);

        // Normalise the retention options input back to the canonical form.
        $this->retention_period_options_input = implode(', ', $options);
        $this->mailgun_secret = '';

        $this->statusMessage = 'System settings saved.';
    }

    public function sendTestEmail(): void
    {
        $this->resetErrorBag();
        $this->errorMessage = null;
        $this->statusMessage = null;

        $this->validate([
            'test_email_recipient' => ['required', 'email'],
        ]);

        try {
            app(NotificationDispatcher::class)->sendTestEmail($this->test_email_recipient);
        } catch (\Throwable $exception) {
            $this->errorMessage = 'Test email failed: '.$exception->getMessage();

            return;
        }

        $this->statusMessage = 'Test email dispatched to '.$this->test_email_recipient.'. Watch your inbox and Mailgun logs.';
    }

    public function mailgunSecretPlaceholder(): string
    {
        return SystemSetting::current()->hasMailgunCredentials()
            ? 'Stored. Type to replace.'
            : 'Paste the Mailgun API key';
    }

    public function render(): View
    {
        return view('livewire.portal.admin.system-settings');
    }

    /**
     * @return array<int, int>
     */
    private function parseRetentionOptions(string $raw): array
    {
        $tokens = preg_split('/[\s,]+/', $raw) ?: [];

        return array_values(array_unique(array_filter(
            array_map(
                static fn (string $token): int => (int) $token,
                array_filter(array_map('trim', $tokens), static fn (string $token): bool => $token !== ''),
            ),
            static fn (int $value): bool => $value > 0,
        )));
    }

    private function emptyToNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function currentUser(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
