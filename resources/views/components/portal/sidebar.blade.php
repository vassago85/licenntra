@php
    /** @var \App\Models\User|null $user */
    $user = auth()->user();
    $branding = $branding ?? \App\Models\BrandingSetting::current();
    $isClient = $user?->isClient() ?? false;
    $isStaff = $user?->isLicensingStaff() ?? false;
    $isDeveloper = $user?->hasRole('developer') ?? false;
    $isAdmin = $user?->hasAnyRole(['owner']) ?? false;
    $canAudit = $user?->hasAnyRole(['owner']) ?? false;
    $canPlatform = $user?->hasAnyRole(['owner', 'developer']) ?? false;

    $accountName = null;
    $accountSummary = null;

    if ($isClient && $user?->clientAccount) {
        $accountName = $user->clientAccount->name;
        $roleSummary = $user->hasRole('customer_admin') ? 'Admin' : 'User';
        $typeLabel = $user->clientAccount->type?->label() ?? 'Client';
        $accountSummary = $typeLabel.' · '.$user->name.' ('.$roleSummary.')';
    } elseif ($isStaff || $isDeveloper) {
        $accountName = $user->name;
        $primaryRole = collect($user->getRoleNames())->first();
        $accountSummary = match ($primaryRole) {
            'owner' => 'Owner',
            'reviewer' => 'Operations',
            'finance' => 'Finance',
            'developer' => 'Platform developer',
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

        if ($user->hasAnyRole(['finance', 'owner'])) {
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
    $isPath = fn (string $pattern): bool => request()->is($pattern);

    /** Auto-expand a section if any of its routes is active. */
    $operationsActive = $isCurrent('review.*') || $isCurrent('finance.*') || $isCurrent('fleet.review.*')
        || $isCurrent('tasks.*') || $isCurrent('dealerships.*') || (! $isClient && $isCurrent('handovers.*'));
    $portalActive = $isCurrent('applications.*') || $isCurrent('business-clients.*') || $isCurrent('estimate.*')
        || $isCurrent('handovers.*') || $isCurrent('invoices.*') || $isCurrent('fleet.vehicles.*') || $isCurrent('team.*');
    $operationsActive = $operationsActive || $isCurrent('admin.overview');
    $adminActive = ($isCurrent('admin.*') && ! $isCurrent('admin.overview')) || $isCurrent('settings.*');
    $complianceActive = $isCurrent('audit.*');
    $platformActive = $isCurrent('platform.*');

    /** First printable character of the brand, used in the sidebar mark. */
    $brandMark = strtoupper(mb_substr($branding->company_name ?? 'L', 0, 1));

    $icon = fn (string $path): string => <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" class="h-4 w-4">
            {$path}
        </svg>
    SVG;

    /** Lucide-style path fragments; keep inline so the sidebar stays dependency-free. */
    $icons = [
        'dashboard' => $icon('<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z"/>'),
        'list' => $icon('<path stroke-linecap="round" stroke-linejoin="round" d="M8.25 6.75h12M8.25 12h12M8.25 17.25h12M3.75 6.75h.007v.008H3.75V6.75Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0ZM3.75 12h.007v.008H3.75V12Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0ZM3.75 17.25h.007v.008H3.75v-.008Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"/>'),
        'plus' => $icon('<path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>'),
        'users' => $icon('<path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/>'),
        'calculator' => $icon('<path stroke-linecap="round" stroke-linejoin="round" d="M15.75 15.75V18m0-6.75h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Zm-3-3h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Zm-3-3h.008v.008H9.75v-.008Zm0 3h.008v.008H9.75v-.008ZM7.5 20.25h9A2.25 2.25 0 0 0 18.75 18V6A2.25 2.25 0 0 0 16.5 3.75h-9A2.25 2.25 0 0 0 5.25 6v12A2.25 2.25 0 0 0 7.5 20.25Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9v-1.5h-9v1.5Z"/>'),
        'package' => $icon('<path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5-9 15M12 2.25l-9 5.25V15l9 5.25L21 15V7.5l-9-5.25ZM3.27 6.96 12 12.21l8.73-5.25M12 12.21V21.75"/>'),
        'invoice' => $icon('<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25M9 15l2.25 2.25L15 12.75M6.75 2.25h5.25a3 3 0 0 1 3 3v2.25a3 3 0 0 0 3 3h2.25v9A2.25 2.25 0 0 1 18 21.75H6.75A2.25 2.25 0 0 1 4.5 19.5V4.5a2.25 2.25 0 0 1 2.25-2.25Z"/>'),
        'truck' => $icon('<path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 0 1-3 0M14.25 18.75a1.5 1.5 0 0 1-3 0M19.5 18.75a1.5 1.5 0 0 1-3 0M8.25 18.75h8.25V5.25H3v12.75h2.25M16.5 18.75H21V11.25h-4.5M3 11.25h13.5"/>'),
        'queue' => $icon('<path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25Z"/>'),
        'cash' => $icon('<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>'),
        'shield' => $icon('<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z"/>'),
        'cog' => $icon('<path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>'),
        'briefcase' => $icon('<path stroke-linecap="round" stroke-linejoin="round" d="M20.25 14.15v4.098a2.25 2.25 0 0 1-2.25 2.25h-12a2.25 2.25 0 0 1-2.25-2.25v-4.098m16.5 0a2.251 2.251 0 0 0 1.5-2.122V8.705c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 0 0-3.413-.387m4.75 8.006c-.625.21-1.28.355-1.955.43M3.75 14.15A2.251 2.251 0 0 1 2.25 12.03V8.705c0-1.081.768-2.015 1.837-2.175a48.111 48.111 0 0 1 3.413-.387m0 0V5.25A2.25 2.25 0 0 1 9.75 3h4.5a2.25 2.25 0 0 1 2.25 2.25v.894m-7.5 0a48.667 48.667 0 0 1 7.5 0M12 12.75h.008v.008H12v-.008Z"/>'),
        'book' => $icon('<path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25"/>'),
        'tag' => $icon('<path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6Z"/>'),
        'card' => $icon('<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z"/>'),
    ];
@endphp

{{-- Collapsible section component for Alpine. Safe to run whether or not
     Alpine has already initialised. --}}
<script>
    (function () {
        const register = () => {
            if (!window.Alpine || window.__licentraSidebarRegistered) {
                return;
            }
            window.__licentraSidebarRegistered = true;
            window.Alpine.data('sidebarSection', (key, forcedActive = false, defaultOpen = true) => ({
                open: forcedActive
                    ? true
                    : (localStorage.getItem('licentra-sb-' + key) ?? (defaultOpen ? '1' : '0')) === '1',
                toggle() {
                    this.open = !this.open;
                    try {
                        localStorage.setItem('licentra-sb-' + key, this.open ? '1' : '0');
                    } catch (e) {
                        /* storage disabled — collapse state becomes session-only. */
                    }
                },
            }));
        };
        if (window.Alpine) {
            register();
        } else {
            document.addEventListener('alpine:init', register);
        }
    })();
</script>

<aside
    @keydown.escape.window="open = false"
    class="fixed inset-y-0 left-0 z-30 flex w-[220px] flex-col gap-4 overflow-y-auto border-r border-line bg-surface px-3 py-5 transition-transform lg:w-[176px] lg:translate-x-0"
    :class="open ? 'translate-x-0' : '-translate-x-full'"
    aria-label="Primary navigation"
>
    <div class="flex items-start justify-between gap-3 px-1">
        <div class="flex items-center gap-2">
            <span
                class="grid h-8 w-[30px] place-items-center rounded-[4px] text-[18px] font-semibold text-white"
                style="background: var(--brand);"
                aria-hidden="true"
            >{{ $brandMark }}</span>
            <div class="flex flex-col leading-tight">
                <strong class="text-[13px] font-semibold text-ink">{{ $branding->company_name }}</strong>
                <small class="text-[11px] text-muted">
                    @if ($isClient)
                        Client portal
                    @elseif ($isStaff)
                        Licensing operations
                    @elseif ($isDeveloper)
                        Platform
                    @else
                        Portal
                    @endif
                </small>
            </div>
        </div>
        <button
            type="button"
            class="rounded-[4px] p-1 text-muted hover:bg-paper lg:hidden"
            @click="open = false"
            aria-label="Close menu"
        >
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="h-5 w-5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
        </button>
    </div>

    @if ($accountName)
        <div class="flex flex-col gap-0.5 rounded-[4px] border border-line px-2.5 py-2">
            <span class="text-[10px] font-medium uppercase tracking-[0.08em] text-muted">
                @if ($isClient) Client account @else Signed in as @endif
            </span>
            <span class="text-[13px] font-semibold leading-snug">{{ $accountName }}</span>
            @if ($accountSummary)
                <span class="text-[11px] text-muted">{{ $accountSummary }}</span>
            @endif
        </div>
    @endif

    <div class="flex flex-1 flex-col gap-3">
        @if ($isClient)
            <section
                x-data="sidebarSection('portal', {{ $portalActive ? 'true' : 'false' }})"
                class="flex flex-col gap-1"
            >
                <button type="button" @click="toggle" :aria-expanded="open" class="flex items-center justify-between gap-2 rounded-[4px] px-2 py-1 text-left hover:bg-paper">
                    <span class="text-[10px] font-medium uppercase tracking-[0.08em] text-muted">Portal</span>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-3 w-3 text-muted transition-transform" :class="open ? 'rotate-0' : '-rotate-90'">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/>
                    </svg>
                </button>
                <div x-show="open" x-cloak class="flex flex-col gap-1">
                    <x-portal.sidebar-link
                        :href="route('applications.index')"
                        :active="$isCurrent('applications.index')"
                        :icon="$icons['dashboard']"
                    >
                        Dashboard
                    </x-portal.sidebar-link>
                    <x-portal.sidebar-link
                        :href="route('applications.index')"
                        :active="false"
                        :count="$applicationsCount"
                        countTone="mono"
                        :icon="$icons['list']"
                    >
                        Applications
                    </x-portal.sidebar-link>
                    <x-portal.sidebar-link
                        :href="route('applications.create')"
                        :active="$isCurrent('applications.create')"
                        :icon="$icons['plus']"
                    >
                        New application
                    </x-portal.sidebar-link>
                    <x-portal.sidebar-link
                        :href="route('business-clients.index')"
                        :active="$isCurrent('business-clients.*')"
                        :count="$businessClientsCount"
                        countTone="mono"
                        :icon="$icons['users']"
                    >
                        Business clients
                    </x-portal.sidebar-link>
                    @if ($quotesEnabled && $quotesCount > 0)
                        <x-portal.sidebar-link
                            :href="route('applications.index').'?tab=needs_action'"
                            :active="false"
                            :count="$quotesCount"
                            countTone="warning"
                            :icon="$icons['invoice']"
                        >
                            Quotes awaiting you
                        </x-portal.sidebar-link>
                    @endif
                    <x-portal.sidebar-link
                        :href="route('estimate.index')"
                        :active="$isCurrent('estimate.*')"
                        :icon="$icons['calculator']"
                    >
                        Licence cost estimate
                    </x-portal.sidebar-link>
                    <x-portal.sidebar-link
                        :href="route('handovers.index')"
                        :active="$isCurrent('handovers.*')"
                        :icon="$icons['package']"
                    >
                        Hand-overs
                    </x-portal.sidebar-link>
                    <x-portal.sidebar-link
                        :href="route('invoices.index')"
                        :active="$isCurrent('invoices.index')"
                        :count="$clientInvoicesOutstandingCount"
                        :countTone="$clientInvoicesOutstandingCount > 0 ? 'warning' : 'mono'"
                        :icon="$icons['invoice']"
                    >
                        Invoices
                    </x-portal.sidebar-link>
                    @if ($isFleetClient)
                        <x-portal.sidebar-link
                            :href="route('fleet.vehicles.index')"
                            :active="$isCurrent('fleet.vehicles.*')"
                            :count="$fleetVehiclesCount"
                            countTone="mono"
                            :icon="$icons['truck']"
                        >
                            Fleet vehicles
                        </x-portal.sidebar-link>
                    @endif
                    @if ($user?->hasRole('customer_admin'))
                        <x-portal.sidebar-link
                            :href="route('team.index')"
                            :active="$isCurrent('team.*')"
                            :icon="$icons['users']"
                        >
                            Team
                        </x-portal.sidebar-link>
                    @endif
                </div>
            </section>
        @elseif ($isStaff)
            <section
                x-data="sidebarSection('operations', {{ $operationsActive ? 'true' : 'false' }})"
                class="flex flex-col gap-1"
            >
                <button type="button" @click="toggle" :aria-expanded="open" class="flex items-center justify-between gap-2 rounded-[4px] px-2 py-1 text-left hover:bg-paper">
                    <span class="text-[10px] font-medium uppercase tracking-[0.08em] text-muted">Operations</span>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-3 w-3 text-muted transition-transform" :class="open ? 'rotate-0' : '-rotate-90'">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/>
                    </svg>
                </button>
                <div x-show="open" x-cloak class="flex flex-col gap-1">
                    <x-portal.sidebar-link
                        :href="route('admin.overview')"
                        :active="$isCurrent('admin.overview')"
                        :icon="$icons['dashboard']"
                    >
                        Overview
                    </x-portal.sidebar-link>
                    <x-portal.sidebar-link
                        :href="route('review.queue')"
                        :active="$isCurrent('review.*')"
                        :count="$reviewQueueCount"
                        :countTone="$reviewQueueCount > 0 ? 'warning' : 'mono'"
                        :icon="$icons['queue']"
                    >
                        Review queue
                    </x-portal.sidebar-link>
                    <x-portal.sidebar-link
                        :href="route('tasks.outstanding')"
                        :active="$isCurrent('tasks.*')"
                        :icon="$icons['list']"
                    >
                        Outstanding tasks
                    </x-portal.sidebar-link>
                    <x-portal.sidebar-link
                        :href="route('dealerships.board')"
                        :active="$isCurrent('dealerships.*')"
                        :icon="$icons['briefcase']"
                    >
                        Dealership board
                    </x-portal.sidebar-link>
                    @if ($paymentTrackingRequired && $user->hasAnyRole(['finance', 'owner']))
                        <x-portal.sidebar-link
                            :href="route('finance.payments')"
                            :active="$isCurrent('finance.payments')"
                            :count="$paymentsCount"
                            :countTone="$paymentsCount > 0 ? 'warning' : 'mono'"
                            :icon="$icons['cash']"
                        >
                            Payments
                        </x-portal.sidebar-link>
                    @endif
                    @if ($user->hasAnyRole(['finance', 'owner']))
                        <x-portal.sidebar-link
                            :href="route('finance.invoices')"
                            :active="$isCurrent('finance.invoices')"
                            :count="$financeInvoicesOutstandingCount"
                            :countTone="$financeInvoicesOutstandingCount > 0 ? 'warning' : 'mono'"
                            :icon="$icons['invoice']"
                        >
                            Invoices
                        </x-portal.sidebar-link>
                    @endif
                    @if ($user->hasAnyRole(['reviewer', 'owner']))
                        <x-portal.sidebar-link
                            :href="route('fleet.review.queue')"
                            :active="$isCurrent('fleet.review.*')"
                            :count="$fleetReviewPendingCount"
                            :countTone="$fleetReviewPendingCount > 0 ? 'warning' : 'mono'"
                            :icon="$icons['truck']"
                        >
                            Fleet licence review
                        </x-portal.sidebar-link>
                        <x-portal.sidebar-link
                            :href="route('handovers.index')"
                            :active="$isCurrent('handovers.*')"
                            :icon="$icons['package']"
                        >
                            Hand-overs
                        </x-portal.sidebar-link>
                    @endif
                </div>
            </section>
        @endif

        @if ($isAdmin)
            <section
                x-data="sidebarSection('admin', {{ $adminActive ? 'true' : 'false' }})"
                class="flex flex-col gap-1 border-t border-line pt-3"
            >
                <button type="button" @click="toggle" :aria-expanded="open" class="flex items-center justify-between gap-2 rounded-[4px] px-2 py-1 text-left hover:bg-paper">
                    <span class="text-[10px] font-medium uppercase tracking-[0.08em] text-muted">Administration</span>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-3 w-3 text-muted transition-transform" :class="open ? 'rotate-0' : '-rotate-90'">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/>
                    </svg>
                </button>
                <div x-show="open" x-cloak class="flex flex-col gap-1">
                    <x-portal.sidebar-link
                        :href="route('admin.users')"
                        :active="$isCurrent('admin.users')"
                        :icon="$icons['users']"
                    >
                        Users
                    </x-portal.sidebar-link>
                    <x-portal.sidebar-link
                        :href="route('admin.client-accounts')"
                        :active="$isCurrent('admin.client-accounts')"
                        :icon="$icons['briefcase']"
                    >
                        Client accounts
                    </x-portal.sidebar-link>
                    <x-portal.sidebar-link
                        :href="route('admin.fee-table-versions')"
                        :active="$isCurrent('admin.fee-*')"
                        :icon="$icons['tag']"
                    >
                        Fee tables
                    </x-portal.sidebar-link>
                    <x-portal.sidebar-link
                        :href="route('admin.document-rules')"
                        :active="$isCurrent('admin.document-*')"
                        :icon="$icons['book']"
                    >
                        Document library
                    </x-portal.sidebar-link>
                    <x-portal.sidebar-link
                        :href="route('settings.branding')"
                        :active="$isCurrent('settings.branding')"
                        :icon="$icons['cog']"
                    >
                        Branding
                    </x-portal.sidebar-link>
                    <x-portal.sidebar-link
                        :href="route('settings.system')"
                        :active="$isCurrent('settings.system')"
                        :icon="$icons['shield']"
                    >
                        System settings
                    </x-portal.sidebar-link>
                </div>
            </section>
        @endif

        @if ($canAudit)
            <section
                x-data="sidebarSection('compliance', {{ $complianceActive ? 'true' : 'false' }})"
                class="flex flex-col gap-1 border-t border-line pt-3"
            >
                <button type="button" @click="toggle" :aria-expanded="open" class="flex items-center justify-between gap-2 rounded-[4px] px-2 py-1 text-left hover:bg-paper">
                    <span class="text-[10px] font-medium uppercase tracking-[0.08em] text-muted">Compliance</span>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-3 w-3 text-muted transition-transform" :class="open ? 'rotate-0' : '-rotate-90'">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/>
                    </svg>
                </button>
                <div x-show="open" x-cloak class="flex flex-col gap-1">
                    <x-portal.sidebar-link
                        :href="route('audit.index')"
                        :active="$isCurrent('audit.*')"
                        :icon="$icons['shield']"
                    >
                        Audit log
                    </x-portal.sidebar-link>
                </div>
            </section>
        @endif

        @if ($canPlatform)
            <section
                x-data="sidebarSection('platform', {{ $platformActive ? 'true' : 'false' }})"
                class="flex flex-col gap-1 border-t border-line pt-3"
            >
                <button type="button" @click="toggle" :aria-expanded="open" class="flex items-center justify-between gap-2 rounded-[4px] px-2 py-1 text-left hover:bg-paper">
                    <span class="text-[10px] font-medium uppercase tracking-[0.08em] text-muted">Platform</span>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-3 w-3 text-muted transition-transform" :class="open ? 'rotate-0' : '-rotate-90'">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/>
                    </svg>
                </button>
                <div x-show="open" x-cloak class="flex flex-col gap-1">
                    <x-portal.sidebar-link
                        :href="route('platform.billing')"
                        :active="$isCurrent('platform.*')"
                        :icon="$icons['card']"
                    >
                        Platform billing
                    </x-portal.sidebar-link>
                </div>
            </section>
        @endif
    </div>

    @auth
        <div class="mt-auto flex flex-col gap-2 border-t border-line px-2 pt-3 text-[11px] text-muted">
            <div class="truncate" title="{{ $user->email }}">{{ $user->email }}</div>
            <a href="{{ route('account.settings') }}" class="font-medium text-ink hover:underline @if ($isCurrent('account.*')) underline @endif">Account settings</a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="font-medium text-ink hover:underline">Sign out</button>
            </form>
        </div>
    @endauth

    <div class="px-2 pt-1 text-[11px] leading-tight text-muted">
        Powered by Licentra
        <small class="block text-[10px] text-muted">Charsley Digital</small>
    </div>
</aside>

<div
    x-cloak
    x-show="open"
    x-transition.opacity
    class="fixed inset-0 z-20 bg-black/40 lg:hidden"
    @click="open = false"
    aria-hidden="true"
></div>
