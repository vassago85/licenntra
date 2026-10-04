@extends('layouts.guest')

@section('content')
    <h1 class="text-xl font-semibold">Choose a new password</h1>
    <form method="POST" action="{{ route('password.update') }}" class="mt-6 space-y-4">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <label class="block text-sm">
            <span class="text-muted">Email</span>
            <input name="email" type="email" value="{{ old('email', $request->email) }}" required class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2">
        </label>
        <label class="block text-sm">
            <span class="text-muted">Password</span>
            <input name="password" type="password" required class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2">
        </label>
        <label class="block text-sm">
            <span class="text-muted">Confirm password</span>
            <input name="password_confirmation" type="password" required class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2">
        </label>
        @if ($errors->any())
            <p class="text-sm text-red-800">{{ $errors->first() }}</p>
        @endif
        <button type="submit" class="h-10 w-full rounded-md text-sm font-semibold text-white" style="background: var(--brand)">Save password</button>
    </form>
@endsection
