<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelAdminOidc\Tests\Feature;

use Dskripchenko\LaravelAdmin\Auth\LoginNotice;
use Dskripchenko\LaravelAdmin\Models\AdminUser;
use Dskripchenko\LaravelAdmin\Panel\Panels;
use Dskripchenko\LaravelAdmin\Permission\Models\Role;
use Dskripchenko\LaravelAdminOidc\Models\OidcIdentity;
use Dskripchenko\LaravelAdminOidc\Models\OidcProvider;
use Dskripchenko\LaravelAdminOidc\Tests\Support\FakeIdp;
use Dskripchenko\LaravelAdminOidc\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * The whole sign-in against a fake provider: off to the provider, back with
 * a code, the ID token verified, the account found, linked or created, the
 * roles mapped — and each way it must stop.
 */
final class SignInTest extends TestCase
{
    private FakeIdp $idp;

    private OidcProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->idp = new FakeIdp;
        $this->idp->fake();
        Role::create(['name' => 'Super', 'slug' => 'super', 'permissions' => ['*']]);
        Role::create(['name' => 'Viewer', 'slug' => 'viewer', 'permissions' => ['admin.oidc.providers']]);
        $this->provider = OidcProvider::create([
            'panel' => 'admin', 'name' => 'Keycloak', 'issuer' => FakeIdp::ISSUER,
            'client_id' => 'panel-client', 'client_secret' => 'test-'.bin2hex(random_bytes(4)),
            'roles_claim' => 'realm_access.roles',
            'role_mapping' => [['value' => 'panel-admins', 'role' => 'super'], ['value' => 'panel-viewers', 'role' => 'viewer']],
        ]);
        $this->idp->claims = [
            'sub' => 'alice-sub', 'email' => 'alice@company.test', 'email_verified' => true, 'name' => 'Alice',
            'realm_access' => ['roles' => ['panel-admins', 'offline_access']],
        ];
    }

    /** Off to the provider and back; the response of the callback. */
    private function signIn(): \Illuminate\Testing\TestResponse
    {
        $start = $this->get('/admin/sso/redirect/'.$this->provider->id);
        $start->assertRedirect();
        $state = $this->idp->authorize((string) $start->headers->get('Location'));

        return $this->get('/admin/sso/callback?code=code-1&state='.urlencode($state));
    }

    private function notice(): ?string
    {
        return session(LoginNotice::SESSION_KEY)['message'] ?? null;
    }

    #[Test]
    public function the_redirect_carries_pkce_state_and_nonce(): void
    {
        $location = (string) $this->get('/admin/sso/redirect/'.$this->provider->id)->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);

        $this->assertStringStartsWith(FakeIdp::ISSUER.'/protocol/openid-connect/auth?', $location);
        $this->assertSame(['code', 'panel-client', 'S256'], [$q['response_type'], $q['client_id'], $q['code_challenge_method']]);
        $this->assertSame(url('admin/sso/callback'), $q['redirect_uri']);
        $this->assertSame('openid email profile', $q['scope']);
        $this->assertNotEmpty($q['state']);
        $this->assertNotEmpty($q['nonce']);
        $this->assertNotEmpty($q['code_challenge']);
    }

    #[Test]
    public function a_first_sign_in_creates_the_account_with_the_mapped_role(): void
    {
        $this->signIn()->assertRedirect(url('admin'));

        $user = AdminUser::query()->where('email', 'alice@company.test')->firstOrFail();
        $this->assertTrue(Auth::guard('admin')->check());
        $this->assertSame($user->id, Auth::guard('admin')->id());
        $this->assertSame(['super'], $user->roles()->pluck('slug')->all());
        $this->assertSame('Alice', $user->name);
        $this->assertDatabaseHas('admin_oidc_identities', ['provider_id' => $this->provider->id, 'subject' => 'alice-sub', 'user_id' => $user->id]);

        // PKCE verifier and client authentication reached the token endpoint.
        $request = $this->idp->tokenRequests[0];
        $this->assertNotEmpty($request['data']['code_verifier']);
        $this->assertStringStartsWith('Basic ', $request['headers']['Authorization'][0]);
    }

    #[Test]
    public function the_roles_follow_the_provider_on_every_sign_in(): void
    {
        $this->signIn();
        Auth::guard('admin')->logout();

        $this->idp->claims['realm_access']['roles'] = ['panel-viewers'];
        $this->signIn()->assertRedirect(url('admin'));

        $user = AdminUser::query()->where('email', 'alice@company.test')->firstOrFail();
        $this->assertSame(['viewer'], $user->roles()->pluck('slug')->all());
        $this->assertSame(1, AdminUser::query()->count());
    }

    #[Test]
    public function no_mapped_role_no_way_in_and_no_account(): void
    {
        $this->idp->claims['realm_access']['roles'] = ['offline_access'];

        $this->signIn()->assertRedirect(url('admin/login'));

        $this->assertFalse(Auth::guard('admin')->check());
        $this->assertSame(0, AdminUser::query()->count());
        $this->assertStringContainsString('No access', (string) $this->notice());
    }

    #[Test]
    public function an_existing_account_is_linked_only_by_a_verified_email(): void
    {
        $existing = AdminUser::create(['name' => 'Alice Local', 'email' => 'Alice@company.test', 'password' => 'local-'.bin2hex(random_bytes(4))]);

        $this->idp->claims['email_verified'] = false;
        $this->signIn()->assertRedirect(url('admin/login'));
        $this->assertStringContainsString('already exists', (string) $this->notice());
        $this->assertSame(0, OidcIdentity::query()->count());

        $this->idp->claims['email_verified'] = true;
        $this->signIn()->assertRedirect(url('admin'));
        $this->assertSame($existing->id, Auth::guard('admin')->id());
        $this->assertSame(1, AdminUser::query()->count());
    }

    #[Test]
    public function linking_by_email_can_be_switched_off(): void
    {
        AdminUser::create(['name' => 'Alice Local', 'email' => 'alice@company.test', 'password' => 'local-'.bin2hex(random_bytes(4))]);
        $this->provider->update(['link_by_email' => false]);

        $this->signIn()->assertRedirect(url('admin/login'));
        $this->assertFalse(Auth::guard('admin')->check());
    }

    #[Test]
    public function a_forged_token_is_refused(): void
    {
        $this->idp->forgeWith = FakeIdp::newKey();

        $this->signIn()->assertRedirect(url('admin/login'));
        $this->assertFalse(Auth::guard('admin')->check());
        $this->assertStringContainsString('unreachable or misconfigured', (string) $this->notice());
    }

    #[Test]
    public function a_token_for_another_client_or_issuer_is_refused(): void
    {
        $this->idp->claims['aud'] = 'another-client';
        $this->signIn()->assertRedirect(url('admin/login'));
        $this->assertFalse(Auth::guard('admin')->check());

        $this->idp->claims['aud'] = 'panel-client';
        $this->idp->claims['iss'] = 'https://evil.test/realms/company';
        $this->signIn()->assertRedirect(url('admin/login'));
        $this->assertFalse(Auth::guard('admin')->check());
    }

    #[Test]
    public function an_unsigned_or_expired_token_is_refused(): void
    {
        $this->idp->header = ['alg' => 'none'];
        $this->signIn()->assertRedirect(url('admin/login'));
        $this->assertFalse(Auth::guard('admin')->check());

        $this->idp->header = [];
        $this->idp->claims['exp'] = time() - 3600;
        $this->idp->claims['iat'] = time() - 7200;
        $this->signIn()->assertRedirect(url('admin/login'));
        $this->assertFalse(Auth::guard('admin')->check());
    }

    #[Test]
    public function a_wrong_nonce_or_a_replayed_callback_is_refused(): void
    {
        $start = $this->get('/admin/sso/redirect/'.$this->provider->id);
        $state = $this->idp->authorize((string) $start->headers->get('Location'));
        $this->idp->lastNonce = 'not-ours';
        $this->get('/admin/sso/callback?code=c&state='.urlencode($state))->assertRedirect(url('admin/login'));
        $this->assertFalse(Auth::guard('admin')->check());

        // The same state again: taken already.
        $this->get('/admin/sso/callback?code=c&state='.urlencode($state))->assertRedirect(url('admin/login'));
        $this->assertStringContainsString('did not complete or has expired', (string) $this->notice());
    }

    #[Test]
    public function a_rotated_key_is_fetched_once_without_waiting_for_the_cache(): void
    {
        $this->signIn()->assertRedirect(url('admin'));
        Auth::guard('admin')->logout();

        $this->idp->rotate('key-2');
        $this->signIn()->assertRedirect(url('admin'));
        $this->assertTrue(Auth::guard('admin')->check());
    }

    #[Test]
    public function mfa_is_required_when_the_provider_is_set_so(): void
    {
        $this->provider->update(['require_mfa' => true]);

        $this->idp->claims['amr'] = ['pwd'];
        $this->signIn()->assertRedirect(url('admin/login'));
        $this->assertStringContainsString('second factor', (string) $this->notice());

        $this->idp->claims['amr'] = ['pwd', 'otp'];
        $this->signIn()->assertRedirect(url('admin'));
    }

    #[Test]
    public function roles_can_come_from_userinfo(): void
    {
        $this->provider->update(['roles_source' => OidcProvider::ROLES_FROM_USERINFO, 'roles_claim' => 'groups']);
        $this->idp->claims['_userinfo'] = ['groups' => ['panel-viewers']];

        $this->signIn()->assertRedirect(url('admin'));
        $this->assertSame(['viewer'], AdminUser::query()->firstOrFail()->roles()->pluck('slug')->all());
    }

    #[Test]
    public function the_provider_saying_no_is_shown_with_its_reason(): void
    {
        $start = $this->get('/admin/sso/redirect/'.$this->provider->id);
        $state = $this->idp->authorize((string) $start->headers->get('Location'));

        $this->get('/admin/sso/callback?error=access_denied&error_description=User+cancelled&state='.urlencode($state))
            ->assertRedirect(url('admin/login'));
        $this->assertStringContainsString('User cancelled', (string) $this->notice());
    }

    #[Test]
    public function a_disabled_account_does_not_get_in(): void
    {
        $user = AdminUser::create(['name' => 'Alice', 'email' => 'alice@company.test', 'password' => 'x-'.bin2hex(random_bytes(4)), 'is_active' => false]);

        $this->signIn()->assertRedirect(url('admin/login'));
        $this->assertFalse(Auth::guard('admin')->check());
        $this->assertNotNull($user->fresh());
    }

    #[Test]
    public function an_unreachable_provider_is_a_message_not_an_error_page(): void
    {
        Http::fake(['idp-down.test/*' => Http::response('nope', 503)]);
        $this->provider->update(['issuer' => 'https://idp-down.test/realms/x']);

        $this->get('/admin/sso/redirect/'.$this->provider->id)->assertRedirect(url('admin/login'));
        $this->assertStringContainsString('unreachable', (string) $this->notice());
    }

    #[Test]
    public function a_sign_in_leaves_the_next_requests_their_own_panel(): void
    {
        // The application outlives the request here as it does under Octane:
        // a panel pinned in the registry would serve the next request too.
        config()->set('admin.panels.team', ['path' => 'team']);
        Panels::registry()->flush();

        $this->signIn()->assertRedirect(url('admin'));

        $next = Request::create('/team/login');
        $next->attributes->set('admin.panel', 'team');
        $this->app->instance('request', $next);
        $this->assertSame('team', Panels::current()->id);
    }
}
