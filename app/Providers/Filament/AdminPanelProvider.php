<?php

namespace App\Providers\Filament;

use App\Http\Middleware\AbsoluteSessionLifetime;
use App\Models\BrandingSetting;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->login()
            ->brandName(function (): string {
                try {
                    if (Schema::hasTable('branding_settings')) {
                        return BrandingSetting::query()->value('company_name') ?: 'Licentra';
                    }
                } catch (\Throwable) {
                    return 'Licentra';
                }

                return 'Licentra';
            })
            ->colors(function (): array {
                $colour = '#1F47B8';

                try {
                    if (Schema::hasTable('branding_settings')) {
                        $colour = BrandingSetting::query()->value('primary_colour') ?: $colour;
                    }
                } catch (\Throwable) {
                    $colour = '#1F47B8';
                }

                return ['primary' => Color::hex($colour)];
            })
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
            ->navigationGroups([
                NavigationGroup::make('Access')->collapsed(),
                NavigationGroup::make('Documents')->collapsed(),
                NavigationGroup::make('Fees')->collapsed(),
                NavigationGroup::make('Compliance')->collapsed(),
                NavigationGroup::make('Settings')->collapsed(),
            ])
            ->userMenuItems([
                MenuItem::make()
                    ->label('Account settings')
                    ->url(fn (): string => route('account.settings'))
                    ->icon(Heroicon::OutlinedUserCircle),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                AbsoluteSessionLifetime::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
