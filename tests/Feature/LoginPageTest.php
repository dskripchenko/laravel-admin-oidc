<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelAdminOidc\Tests\Feature;

use Dskripchenko\LaravelAdmin\Admin;
use Dskripchenko\LaravelAdminOidc\AdminOidcPlugin;
use Dskripchenko\LaravelAdminOidc\Models\OidcProvider;
use Dskripchenko\LaravelAdminOidc\Resources\OidcProviderResource;
use Dskripchenko\LaravelAdminOidc\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/** The plugin's surfaces: login page buttons, the hint link, the resource and its permission. */
final class LoginPageTest extends TestCase
{
    #[Test]
    public function the_login_page_offers_the_panels_enabled_providers(): void
    {
        $on = OidcProvider::create(['panel' => 'admin', 'name' => 'Keycloak', 'issuer' => 'https://idp.test/r', 'client_id' => 'c']);
        OidcProvider::create(['panel' => 'admin', 'name' => 'Old', 'issuer' => 'https://old.test/r', 'client_id' => 'c', 'enabled' => false]);
        OidcProvider::create(['panel' => 'admin', 'name' => 'Tenant', 'issuer' => 'https://t.test/r', 'client_id' => 'c', 'owner_type' => 'client', 'owner_id' => 1]);

        $logins = $this->getJson('/api/admin/system/bootstrap')->assertOk()->json('payload.externalLogins');

        $this->assertSame([[
            'label' => 'Sign in with Keycloak',
            'url' => url('admin/sso/redirect/'.$on->id),
            'icon' => 'key-round',
            'description' => null,
        ]], $logins);
    }

    #[Test]
    public function an_unknown_hint_leads_to_the_login_page(): void
    {
        $this->get('/admin/sso/to/acme')->assertRedirect(url('admin/login'));
        $this->assertSame([], session('admin-oidc.hint'));
    }

    #[Test]
    public function the_plugin_registers_its_resource_and_permission(): void
    {
        $admin = app(Admin::class);
        $this->assertContains(AdminOidcPlugin::class, (array) config('admin.plugins'));
        $this->assertContains(OidcProviderResource::class, $admin->getResources());
        $this->assertTrue($admin->getPermissionRegistry()->knows('admin.oidc.providers'));
    }

    #[Test]
    public function the_shell_does_not_swallow_the_sso_routes(): void
    {
        $this->get('/admin/sso/callback')->assertRedirect(url('admin/login'));
    }
}
