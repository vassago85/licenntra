<div>
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold">System settings</h1>
            <p class="text-sm text-muted">Platform-wide configuration: finance, security, retention and email transport.</p>
        </div>
    </div>

    @if ($statusMessage)
        <p class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">{{ $statusMessage }}</p>
    @endif

    @if ($errorMessage)
        <p class="mb-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900">{{ $errorMessage }}</p>
    @endif

    <form wire:submit="save" class="space-y-6">
        <section class="rounded-md border border-line bg-white p-4">
            <header class="mb-4 flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-sm font-semibold">Finance</h2>
                    <p class="text-xs text-muted">Applied to new fee snapshots. Existing applications keep the fees they were quoted.</p>
                </div>
            </header>

            <label class="block max-w-sm text-sm">
                <span class="text-muted">VAT (%)</span>
                <input wire:model="vat_percent" type="number" step="0.01" min="0" max="100" required
                    class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                <small class="mt-1 block text-xs text-muted">South African standard rate is 15%. Shown on fee snapshots and quotes.</small>
                @error('vat_percent') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
            </label>

            <label class="mt-4 flex items-start gap-2 text-sm">
                <input wire:model="quotes_enabled" type="checkbox"
                    class="mt-0.5 h-4 w-4 rounded border-line text-[color:var(--brand)] focus:ring-[color:var(--brand)]">
                <span>
                    <span class="font-medium">Use quotes</span>
                    <small class="block text-xs text-muted">Shows quote counters, filters and actions, including the "Quotes awaiting you" link for dealers. When off, applications go from document review straight to payment.</small>
                </span>
            </label>

            <label class="mt-3 flex items-start gap-2 text-sm">
                <input wire:model="payment_tracking_required" type="checkbox"
                    class="mt-0.5 h-4 w-4 rounded border-line text-[color:var(--brand)] focus:ring-[color:var(--brand)]">
                <span>
                    <span class="font-medium">Track payments</span>
                    <small class="block text-xs text-muted">Shows the Payments queue to finance and counts payment checks as outstanding work. When off, packs can be prepared and submitted without a payment check.</small>
                </span>
            </label>
        </section>

        <section class="rounded-md border border-line bg-white p-4">
            <header class="mb-4">
                <h2 class="text-sm font-semibold">Security</h2>
                <p class="text-xs text-muted">Session and sign-in policy for everyone in this deployment.</p>
            </header>

            <div class="grid gap-4 md:grid-cols-2">
                <label class="block text-sm">
                    <span class="text-muted">Idle timeout (minutes)</span>
                    <input wire:model="idle_timeout_minutes" type="number" min="5" max="240" required
                        class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                    <small class="mt-1 block text-xs text-muted">How long a session may sit idle before sign-out.</small>
                    @error('idle_timeout_minutes') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>

                <label class="block text-sm">
                    <span class="text-muted">Absolute timeout (minutes)</span>
                    <input wire:model="absolute_timeout_minutes" type="number" min="30" max="1440" required
                        class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                    <small class="mt-1 block text-xs text-muted">Hard sign-out after this long, even if the session is active.</small>
                    @error('absolute_timeout_minutes') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
            </div>

            <label class="mt-4 flex items-start gap-2 text-sm">
                <input wire:model="enforce_client_two_factor" type="checkbox"
                    class="mt-0.5 h-4 w-4 rounded border-line text-[color:var(--brand)] focus:ring-[color:var(--brand)]">
                <span>
                    <span class="font-medium">Require two-factor for client users</span>
                    <small class="block text-xs text-muted">When on, client admins and client users must set up TOTP or a passkey before they can submit work.</small>
                </span>
            </label>
        </section>

        <section class="rounded-md border border-line bg-white p-4">
            <header class="mb-4">
                <h2 class="text-sm font-semibold">Retention</h2>
                <p class="text-xs text-muted">How long you keep business-client identity and address documents between jobs.</p>
            </header>

            <div class="grid gap-4 md:grid-cols-2">
                <label class="block text-sm">
                    <span class="text-muted">Months a client can choose from</span>
                    <input wire:model="retention_period_options_input" type="text" required
                        placeholder="3, 6, 12, 24"
                        class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                    <small class="mt-1 block text-xs text-muted">Comma-separated list of months. Clients tick one of these when they save a business client.</small>
                    @error('retention_period_options_input') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>

                <label class="block text-sm">
                    <span class="text-muted">Maximum retention (months)</span>
                    <input wire:model="retention_max_months" type="number" min="1" max="120" required
                        class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                    <small class="mt-1 block text-xs text-muted">Hard ceiling. Client selections above this are refused.</small>
                    @error('retention_max_months') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>

                <label class="block text-sm">
                    <span class="text-muted">Archive completed work after (days)</span>
                    <input wire:model="archive_after_days" type="number" min="1" max="3650" required
                        class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                    <small class="mt-1 block text-xs text-muted">Applications in Completed are moved to Archived after this many days.</small>
                    @error('archive_after_days') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>

                <label class="block text-sm">
                    <span class="text-muted">Consent wording version</span>
                    <input wire:model="retention_wording_version" type="text" maxlength="20" required
                        class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 font-mono text-sm">
                    <small class="mt-1 block text-xs text-muted">Bump when you change the wording. Previous consents stay on the old version.</small>
                    @error('retention_wording_version') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
            </div>

            <label class="mt-4 block text-sm">
                <span class="text-muted">Consent wording</span>
                <textarea wire:model="retention_wording" rows="4" required
                    class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-sm"></textarea>
                <small class="mt-1 block text-xs text-muted">Shown to the client when they confirm the retention period. Plain text.</small>
                @error('retention_wording') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
            </label>
        </section>

        <section class="rounded-md border border-line bg-white p-4">
            <header class="mb-4">
                <h2 class="text-sm font-semibold">Email (Mailgun)</h2>
                <p class="text-xs text-muted">Outgoing mail provider. Values set here override the matching <span class="font-mono">MAILGUN_*</span> / <span class="font-mono">MAIL_FROM_*</span> entries in <span class="font-mono">.env</span> at runtime.</p>
            </header>

            <div class="grid gap-4 md:grid-cols-2">
                <label class="block text-sm">
                    <span class="text-muted">Mailgun domain</span>
                    <input wire:model="mailgun_domain" type="text" maxlength="190" placeholder="mg.example.co.za"
                        class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                    <small class="mt-1 block text-xs text-muted">The verified sending domain in your Mailgun dashboard.</small>
                    @error('mailgun_domain') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>

                <label class="block text-sm">
                    <span class="text-muted">API endpoint</span>
                    <input wire:model="mailgun_endpoint" type="text" maxlength="190" placeholder="api.mailgun.net or api.eu.mailgun.net"
                        class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                    <small class="mt-1 block text-xs text-muted">Use the EU endpoint if your Mailgun account is EU-hosted.</small>
                    @error('mailgun_endpoint') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
            </div>

            <label class="mt-4 block text-sm" x-data="{ show: false }">
                <span class="text-muted">Mailgun API key</span>
                <div class="mt-1 flex items-center gap-2">
                    <input wire:model="mailgun_secret" :type="show ? 'text' : 'password'" maxlength="190" autocomplete="new-password"
                        :placeholder="'{{ $this->mailgunSecretPlaceholder() }}'"
                        class="h-10 flex-1 rounded-md border border-line bg-white px-3 font-mono text-sm">
                    <button type="button" @click="show = !show"
                        class="h-10 rounded-md border border-line bg-paper px-3 text-xs text-muted hover:bg-white"
                        x-text="show ? 'Hide' : 'Show'"></button>
                </div>
                <small class="mt-1 block text-xs text-muted">Leave blank to keep the stored key. Only overwritten when a new value is typed.</small>
                @error('mailgun_secret') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
            </label>

            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <label class="block text-sm">
                    <span class="text-muted">From address</span>
                    <input wire:model="mail_from_address" type="email" maxlength="190" placeholder="no-reply@example.co.za"
                        class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                    <small class="mt-1 block text-xs text-muted">All workflow emails will come from this address.</small>
                    @error('mail_from_address') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>

                <label class="block text-sm">
                    <span class="text-muted">From name</span>
                    <input wire:model="mail_from_name" type="text" maxlength="190" placeholder="Your licensing company"
                        class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                    <small class="mt-1 block text-xs text-muted">Defaults to the branding company name when blank.</small>
                    @error('mail_from_name') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
            </div>

            <label class="mt-4 flex items-start gap-2 text-sm">
                <input wire:model="notifications_enabled" type="checkbox"
                    class="mt-0.5 h-4 w-4 rounded border-line text-[color:var(--brand)] focus:ring-[color:var(--brand)]">
                <span>
                    <span class="font-medium">Send workflow notifications</span>
                    <small class="block text-xs text-muted">Master switch. When off, no quote, payment, or submission emails are dispatched. Test emails still work.</small>
                </span>
            </label>
        </section>

        <div class="flex items-center justify-end gap-2">
            <button type="submit"
                class="h-10 rounded-md px-5 text-sm font-semibold text-white"
                style="background: var(--brand);"
                wire:loading.attr="disabled"
                wire:target="save">
                <span wire:loading.remove wire:target="save">Save settings</span>
                <span wire:loading wire:target="save">Saving&hellip;</span>
            </button>
        </div>
    </form>

    <section class="mt-6 rounded-md border border-line bg-white p-4">
        <header class="mb-3">
            <h2 class="text-sm font-semibold">Send a test email</h2>
            <p class="text-xs text-muted">Verifies the stored Mailgun credentials by dispatching a one-off message. Ignores the master switch above.</p>
        </header>
        <form wire:submit="sendTestEmail" class="flex flex-wrap items-end gap-3">
            <label class="block flex-1 min-w-[260px] text-sm">
                <span class="text-muted">Send to</span>
                <input wire:model="test_email_recipient" type="email" required
                    class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                @error('test_email_recipient') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
            </label>
            <button type="submit"
                class="h-10 rounded-md border border-line bg-white px-4 text-sm font-semibold text-ink hover:bg-paper"
                wire:loading.attr="disabled"
                wire:target="sendTestEmail">
                <span wire:loading.remove wire:target="sendTestEmail">Send test email</span>
                <span wire:loading wire:target="sendTestEmail">Sending&hellip;</span>
            </button>
        </form>
    </section>
</div>
