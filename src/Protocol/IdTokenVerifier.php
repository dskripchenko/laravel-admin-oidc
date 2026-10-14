<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelAdminOidc\Protocol;

use Dskripchenko\LaravelAdminOidc\Models\OidcProvider;
use Firebase\JWT\JWT;

/**
 * Checks an ID token the way OpenID Connect Core §3.1.3.7 asks: signed by
 * the provider's key with an asymmetric algorithm we allow, issued by the
 * configured issuer for our client, not expired, and answering our nonce.
 * The signature and the time claims are firebase/php-jwt's; the rest is here.
 */
final class IdTokenVerifier
{
    /** No `none`, no HMAC: a provider's public key must not become a shared secret. */
    public const ALGORITHMS = ['RS256', 'RS384', 'RS512', 'PS256', 'ES256', 'ES384', 'EdDSA'];

    public function __construct(private readonly Jwks $jwks) {}

    /**
     * @return array<string, mixed> the claims
     */
    public function verify(string $idToken, OidcProvider $provider, ProviderMetadata $metadata, string $nonce): array
    {
        $header = self::header($idToken);
        $alg = (string) ($header['alg'] ?? '');
        if (! in_array($alg, self::ALGORITHMS, true)) {
            throw new OidcException('token-invalid', sprintf('The ID token is signed with %s, which is not allowed', $alg === '' ? 'no algorithm' : $alg));
        }
        $keys = $this->jwks->keysFor($metadata, isset($header['kid']) ? (string) $header['kid'] : null);

        $leeway = JWT::$leeway;
        JWT::$leeway = max(0, (int) config('admin-oidc.leeway', 60));
        try {
            $claims = (array) json_decode((string) json_encode(JWT::decode($idToken, $keys)), true);
        } catch (\Throwable $e) {
            throw new OidcException('token-invalid', 'The ID token does not verify: '.$e->getMessage(), $e);
        } finally {
            JWT::$leeway = $leeway;
        }

        if (rtrim((string) ($claims['iss'] ?? ''), '/') !== $provider->normalizedIssuer()) {
            throw new OidcException('token-invalid', 'The ID token was issued by another issuer');
        }
        $aud = (array) ($claims['aud'] ?? []);
        if (! in_array($provider->client_id, $aud, true)) {
            throw new OidcException('token-invalid', 'The ID token is not for this client');
        }
        if (count($aud) > 1 && ($claims['azp'] ?? null) !== $provider->client_id) {
            throw new OidcException('token-invalid', 'The ID token names another authorized party');
        }
        if (! isset($claims['exp'])) {
            throw new OidcException('token-invalid', 'The ID token has no expiry');
        }
        if (! is_string($claims['nonce'] ?? null) || ! hash_equals($nonce, $claims['nonce'])) {
            throw new OidcException('token-invalid', 'The ID token does not answer this sign-in (nonce)');
        }
        if (! is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw new OidcException('token-invalid', 'The ID token names no subject');
        }

        return $claims;
    }

    /** @return array<string, mixed> */
    private static function header(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new OidcException('token-invalid', 'The ID token is not a JWS');
        }
        $json = json_decode((string) JWT::urlsafeB64Decode($parts[0]), true);

        return is_array($json) ? $json : throw new OidcException('token-invalid', 'The ID token header is unreadable');
    }
}
