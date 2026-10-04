@extends('layouts.guest')

@section('content')
    <h1 class="text-xl font-semibold">Two-factor check</h1>
    <p class="mt-1 text-sm text-muted">Enter the code from your authenticator, or a recovery code.</p>

    <form method="POST" action="{{ route('two-factor.login') }}" class="mt-6 space-y-4">
        @csrf
        <label class="block text-sm">
            <span class="text-muted">Authentication code</span>
            <input name="code" inputmode="numeric" autofocus class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 font-mono">
        </label>
        <button type="submit" class="h-10 w-full rounded-md text-sm font-semibold text-white" style="background: var(--brand)">Continue</button>
    </form>

    <form method="POST" action="{{ route('two-factor.login') }}" class="mt-6 space-y-4 border-t border-line pt-6">
        @csrf
        <label class="block text-sm">
            <span class="text-muted">Recovery code</span>
            <input name="recovery_code" class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 font-mono">
        </label>
        @if ($errors->any())
            <p class="text-sm text-red-800">{{ $errors->first() }}</p>
        @endif
        <button type="submit" class="h-10 w-full rounded-md border border-line bg-white text-sm font-semibold">Use recovery code</button>
    </form>
@endsection
