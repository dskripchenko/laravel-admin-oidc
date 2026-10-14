<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelAdminOidc\Tests\Keycloak;

use Dskripchenko\LaravelAdmin\Models\AdminUser;
use Dskripchenko\LaravelAdmin\Permission\Models\Role;
use Dskripchenko\LaravelAdminOidc\Models\OidcProvider;
use Dskripchenko\LaravelAdminOidc\Tests\TestCase;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The sign-in against a real Keycloak (tests/Keycloak/realm.json), the way a
 * browser goes through it: the package's redirect, Keycloak's login form,
 * the code back to the callback, the package talking to Keycloak's token
 * endpoint and JWKS for real.
 *
 * Runs when KEYCLOAK_URL is set (the CI job starts Keycloak in docker):
 *   KEYCLOAK_URL=http://localhost:8080 vendor/bin/phpunit --group keycloak
 */
#[Group('keycloak')]
final class KeycloakSignInTest extends TestCase
{
    private string $keycloak;

    protected function setUp(): void
    {
        parent::setUp();
        $this->keycloak = rtrim((string) getenv('KEYCLOAK_URL'), '/');
        if ($this->keycloak === '') {
            $this->markTestSkipped('KEYCLOAK_URL is not set');
        }
        // A local Keycloak over http: what the policy allows only when told to.
        config([
            'admin-oidc.http.allow_http' => true,
            'admin-oidc.http.block_private_networks' => true,
            'admin-oidc.http.allowed_cidrs' => ['127.0.0.0/8', '::1/128', '172.16.0.0/12'],
        ]);
        Role::create(['name' => 'Super', 'slug' => 'super', 'permissions' => ['*']]);
    }

    private function provider(): OidcProvider
    {
        return OidcProvider::create([
            'panel' => 'admin', 'name' => 'Keycloak', 'issuer' => $this->keycloak.'/realms/panel-test',
            'client_id' => 'panel-client', 'client_secret' => 'panel-client-ci-secret',
            'roles_claim' => 'realm_access.roles',
            'role_mapping' => [['value' => 'panel-admins', 'role' => 'super']],
        ]);
    }

    /** Keycloak's login form filled in; the callback URL it sends the browser to. */
    private function loginAtKeycloak(string $authorizeUrl, string $username, string $password): string
    {
        $browser = new Client(['allow_redirects' => false, 'http_errors' => false, 'timeout' => 20]);
        $page = $browser->get($authorizeUrl);
        $form = (string) $page->getBody();
        $this->assertSame(1, preg_match('/<form[^>]+id="kc-form-login"[^>]+action="([^"]+)"/', $form, $m), 'no Keycloak login form');
        // Keycloak marks its cookies Secure even over http; a browser on
        // localhost sends them anyway, Guzzle's jar would not — so by hand.
        $cookies = implode('; ', array_map(static fn (string $c): string => explode(';', $c, 2)[0], $page->getHeader('Set-Cookie')));
        $answer = $browser->post(html_entity_decode($m[1]), [
            'headers' => ['Cookie' => $cookies],
            'form_params' => ['username' => $username, 'password' => $password, 'credentialId' => ''],
        ]);
        $location = $answer->getHeaderLine('Location');
        $this->assertStringStartsWith('http://localhost/admin/sso/callback', $location, 'Keycloak did not send the browser back: '.$answer->getStatusCode());

        return $location;
    }

    #[Test]
    public function a_keycloak_user_with_the_mapped_role_signs_in(): void
    {
        $provider = $this->provider();
        $authorize = (string) $this->get('/admin/sso/redirect/'.$provider->id)->headers->get('Location');
        $callback = $this->loginAtKeycloak($authorize, 'alice', 'alice-ci-password');

        $this->get(substr($callback, strlen('http://localhost')))->assertRedirect(url('admin'));

        $user = AdminUser::query()->where('email', 'alice@panel.test')->firstOrFail();
        $this->assertSame($user->id, Auth::guard('admin')->id());
        $this->assertSame(['super'], $user->roles()->pluck('slug')->all());
        $this->assertSame('Alice Admin', $user->name);
    }

    #[Test]
    public function a_keycloak_user_without_a_mapped_role_is_turned_away(): void
    {
        $provider = $this->provider();
        $authorize = (string) $this->get('/admin/sso/redirect/'.$provider->id)->headers->get('Location');
        $callback = $this->loginAtKeycloak($authorize, 'bob', 'bob-ci-password');

        $this->get(substr($callback, strlen('http://localhost')))->assertRedirect(url('admin/login'));
        $this->assertFalse(Auth::guard('admin')->check());
        $this->assertSame(0, AdminUser::query()->where('email', 'bob@panel.test')->count());
    }
}
