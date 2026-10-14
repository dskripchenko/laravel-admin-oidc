<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelAdminOidc;

use Composer\InstalledVersions;
use Dskripchenko\LaravelAdmin\Admin;
use Dskripchenko\LaravelAdmin\Auth\ExternalLogin;
use Dskripchenko\LaravelAdmin\Auth\ExternalLoginRegistry;
use Dskripchenko\LaravelAdmin\Auth\LogoutRedirectRegistry;
use Dskripchenko\LaravelAdmin\Panel\Panel;
use Dskripchenko\LaravelAdmin\Panel\Panels;
use Dskripchenko\LaravelAdmin\Permission\ItemPermission;
use Dskripchenko\LaravelAdmin\Plugin\AdminPlugin;
use Dskripchenko\LaravelAdminOidc\Contracts\ProviderResolver;
use Dskripchenko\LaravelAdminOidc\Flow\SignInFlow;
use Dskripchenko\LaravelAdminOidc\Flow\SignOut;
use Dskripchenko\LaravelAdminOidc\Http\Controllers\SsoController;
use Dskripchenko\LaravelAdminOidc\Models\OidcProvider;
use Dskripchenko\LaravelAdminOidc\Resources\OidcProviderResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

final class AdminOidcPlugin implements AdminPlugin
{
    public function name(): string
    {
        return 'oidc';
    }

    public function version(): string
    {
        return InstalledVersions::getPrettyVersion('dskripchenko/laravel-admin-oidc') ?? 'dev';
    }

    public function register(): void
    {
        // No-op.
    }

    public function boot(Admin $admin): void
    {
        // The core boots its plugins before it registers the panels' SPA
        // shells; routes added here come first and the shell's catch-all
        // does not swallow /{panel}/sso/*. Once per application: a host may
        // list the plugin in several panels.
        if (! app()->bound('admin-oidc.routes')) {
            app()->instance('admin-oidc.routes', true);
            foreach ($this->panels() as $panel) {
                $this->registerRoutes($panel);
                $this->registerLoginButtons($panel);
                $this->registerSignOut($panel);
            }
        }

        $admin->resources([OidcProviderResource::class]);
        // Source strings: the core translates the group in each request's locale.
        $admin->permissions(
            ItemPermission::group('Системные')
                ->addPermission(OidcProviderResource::permission(), 'SSO-провайдеры'),
        );
    }

    /** @return list<Panel> */
    private function panels(): array
    {
        $out = [];
        foreach ((array) config('admin-oidc.panels', ['admin']) as $id) {
            $panel = is_string($id) ? Panels::registry()->get($id) : null;
            if ($panel !== null) {
                $out[] = $panel;
            }
        }

        return $out;
    }

    private function registerRoutes(Panel $panel): void
    {
        $middleware = $panel->id === 'admin' ? (array) config('admin.middleware.shell', ['web']) : $panel->shellMiddleware();
        Route::middleware([...$middleware, 'throttle:'.config('admin-oidc.throttle', '30,1')])
            ->prefix(trim($panel->path.'/'.SignInFlow::prefix(), '/'))
            ->domain(config('admin.domain'))
            ->name($panel->id.'.oidc.')
            ->group(static function () use ($panel): void {
                Route::get('redirect/{provider}', [SsoController::class, 'redirect'])->whereNumber('provider')->name('redirect')->defaults('adminPanel', $panel->id);
                Route::get('callback', [SsoController::class, 'callback'])->name('callback')->defaults('adminPanel', $panel->id);
                Route::get('to/{hint}', [SsoController::class, 'hint'])->where('hint', '[A-Za-z0-9_-]{1,100}')->name('hint')->defaults('adminPanel', $panel->id);
            });
    }

    private function registerLoginButtons(Panel $panel): void
    {
        app(ExternalLoginRegistry::class)->add($panel->id, static function (Request $request) use ($panel): array {
            $hints = $request->hasSession() ? (array) $request->session()->get(SsoController::HINT_SESSION, []) : [];
            $hint = is_string($hints[$panel->id] ?? null) ? $hints[$panel->id] : null;

            return array_map(
                static fn (OidcProvider $provider): ExternalLogin => new ExternalLogin(
                    __('Войти через :name', ['name' => $provider->name]),
                    route($panel->id.'.oidc.redirect', ['provider' => $provider->id]),
                    'key',
                ),
                app(ProviderResolver::class)->offered($request, $panel->id, $hint),
            );
        });
    }

    private function registerSignOut(Panel $panel): void
    {
        app(LogoutRedirectRegistry::class)->add($panel->id, static fn (Request $request): ?string => app(SignOut::class)->redirectFor($request, $panel));
    }
}
