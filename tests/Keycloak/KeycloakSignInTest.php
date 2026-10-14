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

    /** Keycloak's cookies as a browser keeps them: by name, the latest wins. */
    private array $keycloakCookies = [];

    /** @param list<string> $setCookies */
    private function keepCookies(array $setCookies): string
    {
        foreach ($setCookies as $c) {
            [$name, $value] = explode('=', explode(';', $c, 2)[0], 2) + [1 => ''];
            $this->keycloakCookies[$name] = $value;
        }
        $this->keycloakCookies = array_filter($this->keycloakCookies, static fn (string $v): bool => $v !== '');

        return implode('; ', array_map(static fn (string $n, string $v): string => $n.'='.$v, array_keys($this->keycloakCookies), $this->keycloakCookies));
    }

    private function browser(): Client
    {
        return new Client(['allow_redirects' => false, 'http_errors' => false, 'timeout' => 20]);
    }

    /** Keycloak's login form filled in; the callback URL it sends the browser to. */
    private function loginAtKeycloak(string $authorizeUrl, string $username, string $password): string
    {
        $browser = $this->browser();
        $page = $browser->get($authorizeUrl);
        $form = (string) $page->getBody();
        $this->assertSame(1, preg_match('/<form[^>]+id="kc-form-login"[^>]+action="([^"]+)"/', $form, $m), 'no Keycloak login form');
        // Keycloak marks its cookies Secure even over http; a browser on
        // localhost sends them anyway, Guzzle's jar would not — so by hand.
        $answer = $browser->post(html_entity_decode($m[1]), [
            'headers' => ['Cookie' => $this->keepCookies($page->getHeader('Set-Cookie'))],
            'form_params' => ['username' => $username, 'password' => $password, 'credentialId' => ''],
        ]);
        $this->keepCookies($answer->getHeader('Set-Cookie'));
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

    #[Test]
    public function signing_out_of_the_panel_ends_the_keycloak_session_too(): void
    {
        $provider = $this->provider();
        $authorize = (string) $this->get('/admin/sso/redirect/'.$provider->id)->headers->get('Location');
        $this->get(substr($this->loginAtKeycloak($authorize, 'alice', 'alice-ci-password'), strlen('http://localhost')))->assertRedirect(url('admin'));

        // Signed in at Keycloak: a new sign-in goes straight back, no form.
        $again = (string) $this->get('/admin/sso/redirect/'.$provider->id)->headers->get('Location');
        $this->assertStringStartsWith('http://localhost/admin/sso/callback', $this->browser()->get($again, ['headers' => ['Cookie' => $this->keepCookies([])]])->getHeaderLine('Location'));

        $redirect = (string) $this->postJson('/api/admin/auth/logout')->assertOk()->json('payload.redirect');
        $this->assertStringStartsWith($this->keycloak.'/realms/panel-test/protocol/openid-connect/logout?', $redirect);

        // Keycloak accepts the hint and the return address, ends its session
        // and sends the browser back to the panel's login page.
        $bye = $this->browser()->get($redirect, ['headers' => ['Cookie' => $this->keepCookies([])]]);
        $this->assertSame(302, $bye->getStatusCode(), 'Keycloak refused the sign-out: '.substr((string) $bye->getBody(), 0, 300));
        $this->assertSame('http://localhost/admin/login', $bye->getHeaderLine('Location'));
        $this->keepCookies($bye->getHeader('Set-Cookie'));

        // And asks who it is the next time.
        $next = (string) $this->get('/admin/sso/redirect/'.$provider->id)->headers->get('Location');
        $page = $this->browser()->get($next, ['headers' => ['Cookie' => $this->keepCookies([])]]);
        $this->assertSame(200, $page->getStatusCode());
        $this->assertStringContainsString('kc-form-login', (string) $page->getBody());
    }
}
