@extends('layouts.guest')

@section('content')
    <h1 class="text-xl font-semibold">Reset password</h1>
    <p class="mt-1 text-sm text-muted">We will email a reset link if the account exists.</p>
    @if (session('status'))
        <p class="mt-4 rounded-md border border-line bg-white px-3 py-2 text-sm">{{ session('status') }}</p>
    @endif
    <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-4">
        @csrf
        <label class="block text-sm">
            <span class="text-muted">Email</span>
            <input name="email" type="email" value="{{ old('email') }}" required class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2">
        </label>
        @error('email') <p class="text-sm text-red-800">{{ $message }}</p> @enderror
        <button type="submit" class="h-10 w-full rounded-md text-sm font-semibold text-white" style="background: var(--brand)">Email reset link</button>
    </form>
    <p class="mt-4 text-sm"><a href="{{ route('login') }}">Back to sign in</a></p>
@endsection
