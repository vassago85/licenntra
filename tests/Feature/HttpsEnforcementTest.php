<?php

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\URL;

it('forces generated URLs to https when APP_URL is https', function (): void {
    config(['app.url' => 'https://licentra.charsleydigital.co.za']);

    // Re-run the provider's URL::forceScheme call now that config has flipped.
    (new AppServiceProvider($this->app))->boot();

    expect(URL::route('login'))->toStartWith('https://')
        ->and(URL::to('/dashboard'))->toStartWith('https://');
});

it('leaves URLs as http when APP_URL is http (local dev)', function (): void {
    config(['app.url' => 'http://localhost/licentra/public']);

    // URL::forceScheme is sticky, so we only verify by generating a URL
    // via `url()` with no scheme — Laravel falls back to app.url's scheme
    // when no scheme is forced.
    expect(URL::to('/dashboard'))->toStartWith('http://');
});
