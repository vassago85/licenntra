@extends('layouts.guest')

@php
    $showDemo = config('demo.show_credentials');
    $demoPersonas = [
        [
            'label' => 'Super admin',
            'email' => 'super.admin@licentra.test',
            'role' => 'super_admin',
            'summary' => 'Full access. Admin console, branding, fee tables, document rules, role management, Mailgun settings, identity-document downloads.',
            'tone' => 'danger',
        ],
        [
            'label' => 'Customer admin',
            'email' => 'customer.admin@licentra.test',
            'role' => 'customer_admin',
            'summary' => 'Runs day-to-day operations: review queue, document accept/reject, payment verification, team management, identity-document downloads.',
            'tone' => 'danger',
        ],
        [
            'label' => 'Reviewer',
            'email' => 'reviewer@licentra.test',
            'role' => 'reviewer',
            'summary' => 'Picks up applications from the queue. Accept/reject documents, request changes, confirm datafix, upload returned NaTIS + licence disc.',
            'tone' => 'warning',
        ],
        [
            'label' => 'Finance',
            'email' => 'finance@licentra.test',
            'role' => 'finance',
            'summary' => 'Payment queue only. Verify EFT / card-on-file against the fee snapshot. Cannot download identity documents.',
            'tone' => 'warning',
        ],
        [
            'label' => 'Auditor',
            'email' => 'auditor@licentra.test',
            'role' => 'auditor',
            'summary' => 'Read-only across every application and the append-only audit log. No edits, no identity-document downloads.',
            'tone' => 'info',
        ],
        [
            'label' => 'Dealer admin (Highveld)',
            'email' => 'thandi.mokoena@highveld.test',
            'role' => 'client_admin',
            'summary' => 'Dealership admin for Highveld Commercial Centurion. Create applications, upload documents, accept quotes, manage own team.',
            'tone' => 'success',
        ],
        [
            'label' => 'Dealer user (Highveld)',
            'email' => 'johan.botha@highveld.test',
            'role' => 'client_user',
            'summary' => 'Day-to-day dealership user. Create applications, upload documents, accept quotes (this account has quote-acceptance enabled).',
            'tone' => 'success',
        ],
    ];
    $toneClasses = [
        'danger' => 'bg-red-50 text-red-900 border-red-200',
        'warning' => 'bg-amber-50 text-amber-900 border-amber-200',
        'info' => 'bg-blue-50 text-blue-900 border-blue-200',
        'success' => 'bg-emerald-50 text-emerald-900 border-emerald-200',
    ];
@endphp

@section('content')
    <h1 class="text-xl font-semibold">Sign in</h1>
    <p class="mt-1 text-sm text-muted">Use the account your licensing company issued.</p>

    @if (session('status'))
        <p class="mt-4 rounded-md border border-line bg-white px-3 py-2 text-sm">{{ session('status') }}</p>
    @endif

    <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4">
        @csrf
        <label class="block text-sm">
            <span class="text-muted">Email</span>
            <input id="login-email" name="email" type="email" value="{{ old('email') }}" required autofocus
                   class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2">
        </label>
        <label class="block text-sm">
            <span class="text-muted">Password</span>
            <input id="login-password" name="password" type="password" required
                   class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2">
        </label>
        <label class="flex items-center gap-2 text-sm text-muted">
            <input type="checkbox" name="remember"> Remember this browser
        </label>
        @if ($errors->any())
            <p class="text-sm text-red-800">{{ $errors->first() }}</p>
        @endif
        <button type="submit" class="h-10 w-full rounded-md text-sm font-semibold text-white" style="background: var(--brand)">Sign in</button>
    </form>
    <p class="mt-4 text-sm"><a href="{{ route('password.request') }}">Forgot password</a></p>

    @if ($showDemo)
        <section class="mt-10 rounded-md border border-line bg-white"
                 aria-labelledby="demo-heading">
            <header class="border-b border-line px-4 py-3">
                <h2 id="demo-heading" class="text-sm font-semibold">Demo accounts</h2>
                <p class="mt-1 text-xs text-muted">
                    This deployment ships with seeded personas so you can try each role.
                    Password for every account is
                    <code class="rounded bg-paper px-1 py-0.5 font-mono text-[11px]">password</code>.
                    Click a row to fill the form, then press <strong>Sign in</strong>.
                </p>
            </header>

            <ul class="divide-y divide-line text-sm">
                @foreach ($demoPersonas as $persona)
                    <li class="px-4 py-3">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-medium">{{ $persona['label'] }}</span>
                                    <span class="inline-flex items-center rounded border px-1.5 py-0.5 font-mono text-[11px] {{ $toneClasses[$persona['tone']] }}">
                                        {{ $persona['role'] }}
                                    </span>
                                </div>
                                <p class="mt-1 font-mono text-xs text-muted">{{ $persona['email'] }}</p>
                                <p class="mt-1 text-xs">{{ $persona['summary'] }}</p>
                            </div>
                            <button type="button"
                                    class="h-8 shrink-0 rounded-md border border-line px-2 text-xs hover:border-ink"
                                    data-fill-email="{{ $persona['email'] }}">
                                Fill
                            </button>
                        </div>
                    </li>
                @endforeach
            </ul>

            <footer class="border-t border-line px-4 py-3 text-xs text-muted">
                Dealer-side personas belong to <strong>Highveld Commercial Centurion</strong>
                and only see that account's work. The dealership for Kestrel Logistics, Ridgeway
                Bodies and Northvale Truck &amp; Bus SA exists in the seed data but has no demo
                user attached — ask an admin to issue one if you need to compare accounts.
            </footer>
        </section>

        <script>
            document.querySelectorAll('[data-fill-email]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var email = document.getElementById('login-email');
                    var pwd = document.getElementById('login-password');
                    if (email) email.value = btn.getAttribute('data-fill-email');
                    if (pwd) pwd.value = 'password';
                    if (pwd) pwd.focus();
                });
            });
        </script>
    @endif
@endsection
