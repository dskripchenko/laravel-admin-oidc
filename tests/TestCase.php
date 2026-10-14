<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelAdminOidc\Tests;

use Dskripchenko\LaravelAdmin\Testing\PackageTestCase;
use Dskripchenko\LaravelAdminOidc\AdminOidcServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

abstract class TestCase extends PackageTestCase
{
    use RefreshDatabase;

    protected function additionalProviders(): array
    {
        return [AdminOidcServiceProvider::class];
    }

    protected function defineAdditionalEnvironment($app): void
    {
        // The fake provider's host resolves nowhere; the policy itself has tests of its own.
        $app['config']->set('admin-oidc.http.block_private_networks', false);
        $app['config']->set('admin.audit.log_auth_events', false);
    }
}
