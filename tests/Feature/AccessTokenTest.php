<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelAdminOidc\Tests\Feature;

use Dskripchenko\LaravelAdminOidc\Models\OidcProvider;
use Dskripchenko\LaravelAdminOidc\Protocol\AccessTokenVerifier;
use Dskripchenko\LaravelAdminOidc\Protocol\Discovery;
use Dskripchenko\LaravelAdminOidc\Protocol\OidcException;
use Dskripchenko\LaravelAdminOidc\Tests\Support\FakeIdp;
use Dskripchenko\LaravelAdminOidc\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Access tokens a client brings to a resource server of the host (an API,
 * an MCP endpoint), checked against the provider's keys (RFC 9068).
 */
final class AccessTokenTest extends TestCase
{
    private const RESOURCE = 'https://app.test/api/mcp';

    private FakeIdp $idp;

    private OidcProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->idp = new FakeIdp;
        $this->idp->fake();
        $this->provider = OidcProvider::create([
            'panel' => 'admin', 'name' => 'Keycloak', 'issuer' => FakeIdp::ISSUER,
            'client_id' => 'panel-client', 'client_secret' => 'test-'.bin2hex(random_bytes(4)),
        ]);
        $this->idp->claims = ['sub' => 'alice-sub', 'aud' => [self::RESOURCE, 'account'], 'typ' => 'Bearer', 'scope' => 'openid mcp:read'];
    }

    /** @return array<string, mixed> */
    private function verify(string $token): array
    {
        return app(AccessTokenVerifier::class)->verify($token, $this->provider, app(Discovery::class)->for($this->provider), [self::RESOURCE]);
    }

    private function refused(string $token, string $reason): void
    {
        try {
            $this->verify($token);
            $this->fail('Accepted: '.$reason);
        } catch (OidcException $e) {
            $this->assertStringContainsString($reason, $e->getMessage());
        }
    }

    #[Test]
    public function it_accepts_a_token_meant_for_the_resource_and_reads_its_scopes(): void
    {
        $claims = $this->verify($this->idp->idToken());

        $this->assertSame('alice-sub', $claims['sub']);
        $this->assertSame(['openid', 'mcp:read'], AccessTokenVerifier::scopes($claims));
        $this->assertSame(['a', 'b'], AccessTokenVerifier::scopes(['scp' => ['a', 'b']]));
    }

    #[Test]
    public function it_refuses_tokens_for_other_resources_id_tokens_forged_and_expired_ones(): void
    {
        $this->idp->claims['aud'] = 'panel-client';
        $this->refused($this->idp->idToken(), 'not meant for this resource');

        $this->idp->claims['aud'] = self::RESOURCE;
        $this->idp->claims['typ'] = 'ID';
        $this->refused($this->idp->idToken(), 'ID token is not an access token');

        $this->idp->claims['typ'] = 'Bearer';
        $this->idp->forgeWith = FakeIdp::newKey();
        $this->refused($this->idp->idToken(), 'does not verify');

        $this->idp->forgeWith = null;
        $this->idp->claims['exp'] = time() - 3600;
        $this->refused($this->idp->idToken(), 'does not verify');

        $this->idp->claims['exp'] = time() + 300;
        $this->idp->claims['iss'] = 'https://elsewhere.test';
        $this->refused($this->idp->idToken(), 'another issuer');

        unset($this->idp->claims['iss']);
        $this->idp->header = ['alg' => 'none'];
        $this->refused($this->idp->idToken(), 'not allowed');
    }
}
