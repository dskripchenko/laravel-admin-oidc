<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelAdminOidc\Tests\Feature;

use Dskripchenko\LaravelAdmin\Models\AdminUser;
use Dskripchenko\LaravelAdmin\Permission\Models\Role;
use Dskripchenko\LaravelAdminOidc\Flow\SignOut;
use Dskripchenko\LaravelAdminOidc\Models\OidcProvider;
use Dskripchenko\LaravelAdminOidc\Tests\Support\FakeIdp;
use Dskripchenko\LaravelAdminOidc\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;

/**
 * Signing out of the panel after an SSO sign-in sends the browser to the
 * provider's own sign-out (RP-initiated logout) — and only then.
 */
final class SignOutTest extends TestCase
{
    private FakeIdp $idp;

    private OidcProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->idp = new FakeIdp;
        $this->idp->fake();
        Role::create(['name' => 'Super', 'slug' => 'super', 'permissions' => ['*']]);
        $this->provider = OidcProvider::create([
            'panel' => 'admin', 'name' => 'Keycloak', 'issuer' => FakeIdp::ISSUER,
            'client_id' => 'panel-client', 'client_secret' => 'test-'.bin2hex(random_bytes(4)),
            'roles_claim' => 'realm_access.roles',
            'role_mapping' => [['value' => 'panel-admins', 'role' => 'super']],
        ]);
        $this->idp->claims = [
            'sub' => 'alice-sub', 'email' => 'alice@company.test', 'email_verified' => true, 'name' => 'Alice',
            'realm_access' => ['roles' => ['panel-admins']],
        ];
    }

    private function signIn(): void
    {
        $start = $this->get('/admin/sso/redirect/'.$this->provider->id);
        $state = $this->idp->authorize((string) $start->headers->get('Location'));
        $this->get('/admin/sso/callback?code=code-1&state='.urlencode($state))->assertRedirect(url('admin'));
    }

    #[Test]
    public function the_sign_out_goes_through_the_provider_and_back_to_the_login_page(): void
    {
        $this->signIn();
        $idToken = session(SignOut::SESSION)['id_token'];

        $redirect = (string) $this->postJson('/api/admin/auth/logout')->assertOk()->json('payload.redirect');
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $q);

        $this->assertStringStartsWith(FakeIdp::ISSUER.'/protocol/openid-connect/logout?', $redirect);
        $this->assertSame([$idToken, url('admin/login'), 'panel-client'], [$q['id_token_hint'], $q['post_logout_redirect_uri'], $q['client_id']]);
        $this->assertFalse(auth('admin')->check());
        $this->assertNull(session(SignOut::SESSION));
    }

    #[Test]
    public function switched_off_for_the_provider_the_panel_opens_its_own_login_page(): void
    {
        $this->provider->update(['sign_out_at_provider' => false]);
        $this->signIn();

        $this->postJson('/api/admin/auth/logout')->assertOk()->assertJsonPath('payload.redirect', null);
    }

    #[Test]
    public function a_provider_without_an_end_session_endpoint_is_left_alone(): void
    {
        $this->signIn();
        Cache::flush();
        $this->idp->announcesSignOut = false;

        $this->postJson('/api/admin/auth/logout')->assertOk()->assertJsonPath('payload.redirect', null);
    }

    #[Test]
    public function a_password_sign_in_or_a_deleted_provider_signs_out_as_before(): void
    {
        $this->actingAs(AdminUser::create(['name' => 'Bob', 'email' => 'bob@company.test', 'password' => bin2hex(random_bytes(8))]), 'admin');
        $this->postJson('/api/admin/auth/logout')->assertOk()->assertJsonPath('payload.redirect', null);

        $this->signIn();
        $this->provider->delete();
        $this->postJson('/api/admin/auth/logout')->assertOk()->assertJsonPath('payload.redirect', null);
    }
}
