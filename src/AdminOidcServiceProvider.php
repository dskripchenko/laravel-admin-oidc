<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelAdminOidc;

use Dskripchenko\LaravelAdmin\Plugin\Concerns\RegistersAdminPlugin;
use Dskripchenko\LaravelAdminOidc\Contracts\ProviderResolver;
use Dskripchenko\LaravelAdminOidc\Contracts\RoleCatalog;
use Dskripchenko\LaravelAdminOidc\Contracts\UserProvisioner;
use Dskripchenko\LaravelAdminOidc\Http\ProviderHttp;
use Illuminate\Support\ServiceProvider;

/**
 * - config admin-oidc.php, migrations, JSON translations;
 * - the host's seams bound from config (resolver, provisioner, role catalog);
 * - AdminOidcPlugin registered in config('admin.plugins').
 *
 * Nothing static between requests: the services are made per resolution, so
 * the package is safe under Octane.
 */
final class AdminOidcServiceProvider extends ServiceProvider
{
    use RegistersAdminPlugin;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/admin-oidc.php', 'admin-oidc');

        $this->app->bind(ProviderResolver::class, fn () => $this->app->make((string) config('admin-oidc.resolver')));
        $this->app->bind(UserProvisioner::class, fn () => $this->app->make((string) config('admin-oidc.provisioner')));
        $this->app->bind(RoleCatalog::class, fn () => $this->app->make((string) config('admin-oidc.role_catalog')));
        $this->app->bind(ProviderHttp::class, static fn (): ProviderHttp => ProviderHttp::make());

        // Registered in register(), not boot(): see laravel-admin-health.
        $this->loadJsonTranslationsFrom(__DIR__.'/../resources/lang');

        $this->registerAdminPlugin(AdminOidcPlugin::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/admin-oidc.php' => config_path('admin-oidc.php'),
        ], 'admin-oidc-config');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
