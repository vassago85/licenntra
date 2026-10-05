<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Livewire resolves `wire:click="name(...)"` against the client-side `$wire`
 * object first, so a public component method that shares a name with one of
 * its built-ins never reaches the server from the browser.
 */
it('has no Livewire component method that is shadowed by a client-side $wire built-in', function (): void {
    $reserved = ['upload', 'uploadMultiple', 'removeUpload', 'cancelUpload', 'get', 'set', 'call', 'on', 'watch', 'entangle', 'toggle', 'refresh', 'commit', 'dispatch', 'js', 'intercept', 'el', 'id', 'parent'];

    $clashes = collect(File::allFiles(app_path('Livewire')))
        ->map(fn (SplFileInfo $file): string => 'App\\Livewire\\'.Str::of($file->getRelativePathname())->replace(['/', '.php'], ['\\', '']))
        ->filter(fn (string $class): bool => class_exists($class) && is_subclass_of($class, Component::class))
        ->flatMap(fn (string $class): array => collect((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC))
            ->filter(fn (ReflectionMethod $method): bool => $method->class === $class && in_array($method->getName(), $reserved, true))
            ->map(fn (ReflectionMethod $method): string => $class.'::'.$method->getName())
            ->all())
        ->values()
        ->all();

    expect($clashes)->toBe([]);
});
