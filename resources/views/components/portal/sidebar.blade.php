@php
    /** @var \App\Models\User|null $user */
    $user = auth()->user();
    $branding = $branding ?? \App\Models\BrandingSetting::current();
    $isClient = $user?->isClient() ?? false;
    $isStaff = $user?->isLicensingStaff() ?? false;
    $isAdmin = $user?->hasAnyRole(['super_admin', 'customer_admin']) ?? false;

    $accountName = null;
    $accountSummary = null;

    if ($isClient && $user?->clientAccount) {
        $accountName = $user->clientAccount->name;
        $roleSummary = $user->hasRole('client_admin') ? 'Admin' : 'User';
        $typeLabel = $user->clientAccount->type?->label() ?? 'Client';
        $accountSummary = $typeLabel.' · '.$user->name.' ('.$roleSummary.')';
    } elseif ($isStaff) {
        $accountName = $user->name;
        $primaryRole = collect($user->getRoleNames())->first();
        $accountSummary = match ($primaryRole) {
            'super_admin' => 'Super admin',
            'customer_admin' => 'Customer admin',
            'reviewer' => 'Reviewer',
            'finance' => 'Finance',
            'auditor' => 'Auditor',
            default => ucfirst((string) $primaryRole),
        };
    }

    $applicationsCount = null;
    $businessClientsCount = null;
    $quotesCount = null;
    $reviewQueueCount = null;
    $paymentsCount = null;
    $fleetVehiclesCount = null;
    $fleetReviewPendingCount = null;
    $clientInvoicesOutstandingCount = null;
    $financeInvoicesOutstandingCount = null;
    $quotesEnabled = \App\Services\FeatureFlags::quotesEnabled();
    $paymentTrackingRequired = \App\Services\FeatureFlags::paymentTrackingRequired();
    $isFleetClient = $isClient && ($user?->clientAccount?->hasType(\App\Enums\ClientAccountType::FleetOperator) ?? false);

    if ($isClient && $user?->client_account_id !== null) {
        $applicationsCount = \App\Models\Application::query()
            ->where('client_account_id', $user->client_account_id)
            ->whereNotIn('stage', [
                \App\Enums\ApplicationStage::Completed,
                \App\Enums\ApplicationStage::Cancelled,
                \App\Enums\ApplicationStage::Archived,
            ])
            ->count();

        $businessClientsCount = \App\Models\BusinessClient::query()
            ->where('client_account_id', $user->client_account_id)
            ->count();

        $quotesCount = $quotesEnabled
            ? \App\Models\Quote::query()
                ->whereHas('application', fn ($q) => $q->where('client_account_id', $user->client_account_id))
                ->whereIn('status', ['sent'])
                ->count()
            : 0;

        $clientInvoicesOutstandingCount = \App\Models\Invoice::query()
            ->whereNull('paid_at')
            ->whereHas('application', fn ($q) => $q->where('client_account_id', $user->client_account_id))
            ->count();
    }

    if ($isStaff) {
        $reviewQueueCount = \App\Models\Application::query()
            ->whereIn('stage', [
                \App\Enums\ApplicationStage::Submitted,
                \App\Enums\ApplicationStage::DocumentReview,
                \App\Enums\ApplicationStage::ChangesRequested,
            ])->count();

        $paymentsCount = $paymentTrackingRequired
            ? \App\Models\Payment::query()->whereNull('verified_at')->count()
            : 0;

        $fleetReviewPendingCount = \App\Models\FleetVehicleDocument::query()
            ->whereNull('confirmed_at')
            ->count();

        if ($user->hasAnyRole(['finance', 'customer_admin', 'super_admin'])) {
            $financeInvoicesOutstandingCount = \App\Models\Invoice::query()
                ->whereNull('paid_at')
                ->count();
        }
    }

    if ($isFleetClient) {
        $fleetVehiclesCount = \App\Models\FleetVehicle::query()
            ->where('client_account_id', $user->client_account_id)
            ->whereNull('retired_at')
            ->confirmed()
            ->count();
    }

    $isCurrent = fn (string $pattern): bool => request()->routeIs($pattern);
@endphp

<aside
    @keydown.escape.window="open = false"
    class="fixed inset-y-0 left-0 z-30 flex w-60 flex-col gap-6 border-r border-line bg-white px-4 py-5 transition-transform lg:static lg:w-full lg:translate-x-0"
    :class="open ? 'translate-x-0' : '-translate-x-full'"
    aria-label="Primary navigation"
>
    <div class="flex items-start justify-between gap-3 px-2">
        <div class="flex flex-col gap-0.5">
            <span class="text-base font-bold tracking-[0.14em] uppercase" style="color: var(--brand)">
                {{ $branding->company_name }}
            </span>
            <span class="text-xs text-muted">
                @if ($isClient)
                    Client portal
                @elseif ($isStaff)
                    Staff portal
                @else
                    Portal
                @endif
            </span>
        </div>
        <button
            type="button"
            class="rounded-md p-1 text-muted hover:bg-paper lg:hidden"
            @click="open = false"
            aria-label="Close menu"
        >
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="h-5 w-5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
        </button>
    </div>

    @if ($accountName)
        <div class="flex flex-col gap-0.5 rounded-md border border-line px-3 py-2.5">
            <span class="text-[11px] font-medium uppercase tracking-[0.08em] text-muted">
                @if ($isClient) Client account @else Signed in as @endif
            </span>
            <span class="text-sm font-semibold">{{ $accountName }}</span>
            @if ($accountSummary)
                <span class="text-xs text-muted">{{ $accountSummary }}</span>
            @endif
        </div>
    @endif

    <nav class="flex flex-col gap-0.5">
        @if ($isClient)
            <x-portal.sidebar-link :href="route('applications.index')" :active="$isCurrent('applications.index')">
                Dashboard
            </x-portal.sidebar-link>
            <x-portal.sidebar-link
                :href="route('applications.index')"
                :active="false"
                :count="$applicationsCount"
                countTone="mono"
            >
                Applications
            </x-portal.sidebar-link>
            <x-portal.sidebar-link :href="route('applications.create')" :active="$isCurrent('applications.create')">
                New application
            </x-portal.sidebar-link>
            <x-portal.sidebar-link
                :href="route('business-clients.index')"
                :active="$isCurrent('business-clients.*')"
                :count="$businessClientsCount"
                countTone="mono"
            >
                Business clients
            </x-portal.sidebar-link>
            @if ($quotesEnabled && $quotesCount > 0)
                <x-portal.sidebar-link
                    :href="route('applications.index').'?tab=needs_action'"
                    :active="false"
                    :count="$quotesCount"
                    countTone="warning"
                >
                    Quotes awaiting you
                </x-portal.sidebar-link>
            @endif
            <x-portal.sidebar-link :href="route('estimate.index')" :active="$isCurrent('estimate.*')">
                Licence cost estimate
            </x-portal.sidebar-link>
            <x-portal.sidebar-link
                :href="route('invoices.index')"
                :active="$isCurrent('invoices.index')"
                :count="$clientInvoicesOutstandingCount"
                :countTone="$clientInvoicesOutstandingCount > 0 ? 'warning' : 'mono'"
            >
                Invoices
            </x-portal.sidebar-link>
            @if ($isFleetClient)
                <x-portal.sidebar-link
                    :href="route('fleet.vehicles.index')"
                    :active="$isCurrent('fleet.vehicles.*')"
                    :count="$fleetVehiclesCount"
                    countTone="mono"
                >
                    Fleet vehicles
                </x-portal.sidebar-link>
            @endif
            @if ($user?->hasRole('client_admin'))
                <x-portal.sidebar-link :href="route('team.index')" :active="$isCurrent('team.*')">
                    Team
                </x-portal.sidebar-link>
            @endif
        @elseif ($isStaff)
            <x-portal.sidebar-link
                :href="route('review.queue')"
                :active="$isCurrent('review.*')"
                :count="$reviewQueueCount"
                :countTone="$reviewQueueCount > 0 ? 'warning' : 'mono'"
            >
                Review queue
            </x-portal.sidebar-link>
            @if ($paymentTrackingRequired && $user->hasAnyRole(['finance', 'customer_admin', 'super_admin']))
                <x-portal.sidebar-link
                    :href="route('finance.payments')"
                    :active="$isCurrent('finance.payments')"
                    :count="$paymentsCount"
                    :countTone="$paymentsCount > 0 ? 'warning' : 'mono'"
                >
                    Payments
                </x-portal.sidebar-link>
            @endif
            @if ($user->hasAnyRole(['finance', 'customer_admin', 'super_admin']))
                <x-portal.sidebar-link
                    :href="route('finance.invoices')"
                    :active="$isCurrent('finance.invoices')"
                    :count="$financeInvoicesOutstandingCount"
                    :countTone="$financeInvoicesOutstandingCount > 0 ? 'warning' : 'mono'"
                >
                    Invoices
                </x-portal.sidebar-link>
            @endif
            @if ($user->hasAnyRole(['reviewer', 'customer_admin', 'super_admin']))
                <x-portal.sidebar-link
                    :href="route('fleet.review.queue')"
                    :active="$isCurrent('fleet.review.*')"
                    :count="$fleetReviewPendingCount"
                    :countTone="$fleetReviewPendingCount > 0 ? 'warning' : 'mono'"
                >
                    Fleet licence review
                </x-portal.sidebar-link>
            @endif
            @if ($isAdmin)
                <x-portal.sidebar-link :href="url('/admin')" :active="false">
                    <span class="flex items-center gap-1">Admin console
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-3.5 w-3.5 text-muted"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/></svg>
                    </span>
                </x-portal.sidebar-link>
            @endif
        @endif
    </nav>

    @auth
        <div class="flex flex-col gap-2 border-t border-line px-2 pt-4 text-xs text-muted">
            <div class="truncate" title="{{ $user->email }}">{{ $user->email }}</div>
            <a href="{{ route('account.settings') }}" class="font-medium text-ink hover:underline @if ($isCurrent('account.*')) underline @endif">Account settings</a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="font-medium text-ink hover:underline">Sign out</button>
            </form>
        </div>
    @endauth

    <div class="mt-auto px-2 pt-4 text-xs text-muted">Powered by Licentra</div>
</aside>

<div
    x-cloak
    x-show="open"
    x-transition.opacity
    class="fixed inset-0 z-20 bg-black/40 lg:hidden"
    @click="open = false"
    aria-hidden="true"
></div>
