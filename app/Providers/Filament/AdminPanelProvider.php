<?php

namespace App\Providers\Filament;

use App\Http\Controllers\ProductImageController;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('Gestor de Exportación para Marketing')
            ->brandLogo(asset('brand/logo_luvik.svg'))
            ->brandLogoHeight('2.75rem')
            ->darkMode(false)
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->authenticatedRoutes(function (): void {
                Route::get('/product-images/{code}', ProductImageController::class)
                    ->name('product-images.show');
            })
            ->colors([
                'primary' => [
                    50 => '#f5fcfd',
                    100 => '#b3ecf2',
                    200 => '#9ad2d9',
                    300 => '#35658a',
                    400 => '#003455',
                    500 => '#003455',
                    600 => '#003455',
                    700 => '#002d4a',
                    800 => '#00263d',
                    900 => '#001e31',
                    950 => '#00151f',
                ],
                'danger' => Color::hex('#b21828'),
                'info' => Color::hex('#35658a'),
            ])
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\Filament\Admin\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\Filament\Admin\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\Filament\Admin\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
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
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
