<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelAdminOidc\Tests\Support;

use Firebase\JWT\JWT;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * An OpenID Connect provider in-process: discovery, JWKS and a token
 * endpoint behind Http::fake, signing ID tokens with a key made per test.
 * The token endpoint answers with whatever `$claims` holds at the moment of
 * the exchange, plus the nonce of the authorization request it is given.
 */
final class FakeIdp
{
    public const ISSUER = 'https://idp.test/realms/company';

    /** @var array<string, mixed> */
    public array $claims = [];

    /** Sign with this key instead of the published one — a forged token. */
    public ?\OpenSSLAsymmetricKey $forgeWith = null;

    /** Overrides of the token's header (alg, kid). */
    /** @var array<string, string> */
    public array $header = [];

    public string $kid = 'key-1';

    public ?string $lastNonce = null;

    /** @var list<array<string, mixed>> */
    public array $tokenRequests = [];

    private \OpenSSLAsymmetricKey $key;

    public function __construct()
    {
        $this->key = self::newKey();
    }

    public static function newKey(): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        return $key !== false ? $key : throw new \RuntimeException('openssl_pkey_new failed');
    }

    /** A new signing key under a new key id — what a key rotation looks like. */
    public function rotate(string $kid): void
    {
        $this->key = self::newKey();
        $this->kid = $kid;
    }

    public function fake(): void
    {
        Http::fake([
            'idp.test/realms/company/.well-known/openid-configuration' => fn () => Http::response([
                'issuer' => self::ISSUER,
                'authorization_endpoint' => self::ISSUER.'/protocol/openid-connect/auth',
                'token_endpoint' => self::ISSUER.'/protocol/openid-connect/token',
                'jwks_uri' => self::ISSUER.'/protocol/openid-connect/certs',
                'userinfo_endpoint' => self::ISSUER.'/protocol/openid-connect/userinfo',
                'end_session_endpoint' => self::ISSUER.'/protocol/openid-connect/logout',
                'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
            ]),
            'idp.test/realms/company/protocol/openid-connect/certs' => fn () => Http::response(['keys' => [$this->jwk()]]),
            'idp.test/realms/company/protocol/openid-connect/token' => function (Request $request) {
                $this->tokenRequests[] = ['data' => $request->data(), 'headers' => $request->headers()];

                return Http::response([
                    'access_token' => 'access-'.bin2hex(random_bytes(4)),
                    'token_type' => 'Bearer',
                    'id_token' => $this->idToken(),
                ]);
            },
            'idp.test/realms/company/protocol/openid-connect/userinfo' => fn () => Http::response(['sub' => $this->claims['sub'] ?? 'nobody', ...($this->claims['_userinfo'] ?? [])]),
        ]);
    }

    /** Takes the nonce out of the authorization redirect, as the browser would carry it. */
    public function authorize(string $location): string
    {
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->lastNonce = (string) ($query['nonce'] ?? '');

        return (string) ($query['state'] ?? '');
    }

    public function idToken(): string
    {
        $claims = $this->claims;
        unset($claims['_userinfo']);
        $claims += [
            'iss' => self::ISSUER,
            'aud' => 'panel-client',
            'sub' => 'subject-1',
            'iat' => time(),
            'exp' => time() + 300,
            'nonce' => $this->lastNonce,
        ];
        $alg = $this->header['alg'] ?? 'RS256';
        if ($alg === 'none') {
            return rtrim(strtr(base64_encode((string) json_encode(['alg' => 'none', 'typ' => 'JWT'])), '+/', '-_'), '=').'.'
                .rtrim(strtr(base64_encode((string) json_encode($claims)), '+/', '-_'), '=').'.';
        }

        return JWT::encode($claims, $this->forgeWith ?? $this->key, $alg, $this->header['kid'] ?? $this->kid);
    }

    /** @return array<string, string> */
    private function jwk(): array
    {
        $details = openssl_pkey_get_details($this->key);
        $b64 = static fn (string $v): string => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');

        return [
            'kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => $this->kid,
            'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e']),
        ];
    }
}
