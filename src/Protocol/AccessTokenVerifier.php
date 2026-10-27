<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelAdminOidc\Protocol;

use Dskripchenko\LaravelAdminOidc\Models\OidcProvider;
use Firebase\JWT\JWT;

/**
 * Checks a JWT access token a client brings to a resource server — an API
 * the provider's users call with tokens it issued (RFC 9068): signed by the
 * provider's key with an asymmetric algorithm we allow, issued by the
 * configured issuer, not expired, and meant for this resource — its `aud`
 * names one of the given audiences. An ID token is not an access token and
 * is refused (`typ: ID`, as Keycloak marks them).
 */
final class AccessTokenVerifier
{
    public function __construct(private readonly Jwks $jwks) {}

    /**
     * @param  list<string>  $audiences  what this resource answers to: its URL, a configured audience
     * @return array<string, mixed> the claims
     */
    public function verify(string $token, OidcProvider $provider, ProviderMetadata $metadata, array $audiences): array
    {
        $header = self::header($token);
        $alg = (string) ($header['alg'] ?? '');
        if (! in_array($alg, IdTokenVerifier::ALGORITHMS, true)) {
            throw new OidcException('token-invalid', sprintf('The access token is signed with %s, which is not allowed', $alg === '' ? 'no algorithm' : $alg));
        }
        $keys = $this->jwks->keysFor($metadata, isset($header['kid']) ? (string) $header['kid'] : null);

        $leeway = JWT::$leeway;
        JWT::$leeway = max(0, (int) config('admin-oidc.leeway', 60));
        try {
            $claims = (array) json_decode((string) json_encode(JWT::decode($token, $keys)), true);
        } catch (\Throwable $e) {
            throw new OidcException('token-invalid', 'The access token does not verify: '.$e->getMessage(), $e);
        } finally {
            JWT::$leeway = $leeway;
        }

        if (rtrim((string) ($claims['iss'] ?? ''), '/') !== $provider->normalizedIssuer()) {
            throw new OidcException('token-invalid', 'The access token was issued by another issuer');
        }
        if (! isset($claims['exp'])) {
            throw new OidcException('token-invalid', 'The access token has no expiry');
        }
        if (strtoupper((string) ($claims['typ'] ?? '')) === 'ID') {
            throw new OidcException('token-invalid', 'An ID token is not an access token');
        }
        $aud = array_map('strval', (array) ($claims['aud'] ?? []));
        if (array_intersect($aud, $audiences) === []) {
            throw new OidcException('token-invalid', 'The access token is not meant for this resource');
        }
        if (! is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw new OidcException('token-invalid', 'The access token names no subject');
        }

        return $claims;
    }

    /**
     * The scopes the token grants, from `scope` (a space-separated string)
     * or `scp` (a list, as some providers write it).
     *
     * @param  array<string, mixed>  $claims
     * @return list<string>
     */
    public static function scopes(array $claims): array
    {
        $raw = $claims['scope'] ?? $claims['scp'] ?? [];
        $list = is_string($raw) ? preg_split('/\s+/', trim($raw)) : (array) $raw;

        return array_values(array_filter(array_map('strval', (array) $list), static fn (string $s): bool => $s !== ''));
    }

    /** @return array<string, mixed> */
    private static function header(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new OidcException('token-invalid', 'The access token is not a JWS');
        }
        $json = json_decode((string) JWT::urlsafeB64Decode($parts[0]), true);

        return is_array($json) ? $json : throw new OidcException('token-invalid', 'The access token header is unreadable');
    }
}
