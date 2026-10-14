<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelAdminOidc\Protocol;

use Dskripchenko\LaravelAdminOidc\Http\ProviderHttp;
use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;

/**
 * The provider's signing keys. Cached; a token signed with a key id the cache
 * does not know reads the JWKS again, once — a key rotation at the provider
 * needs no wait for the cache to expire.
 */
final class Jwks
{
    public function __construct(private readonly ProviderHttp $http) {}

    /**
     * @return array<string, Key> by key id
     */
    public function keysFor(ProviderMetadata $metadata, ?string $kid): array
    {
        $key = 'admin-oidc:jwks:'.sha1($metadata->jwksUri);
        $keys = $this->parse(Cache::remember($key, $this->ttl(), fn (): array => $this->http->getJson($metadata->jwksUri)));
        if ($kid !== null && ! isset($keys[$kid])) {
            $fresh = $this->http->getJson($metadata->jwksUri);
            Cache::put($key, $fresh, $this->ttl());
            $keys = $this->parse($fresh);
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $set
     * @return array<string, Key>
     */
    private function parse(array $set): array
    {
        // Encryption keys are not ours to verify with; JWK::parseKeySet would
        // refuse a set with a key it does not know the use of.
        $set['keys'] = array_values(array_map(self::withAlg(...), array_filter(
            (array) ($set['keys'] ?? []),
            static fn (mixed $k): bool => is_array($k) && ($k['use'] ?? 'sig') === 'sig' && isset($k['kid']),
        )));
        try {
            return JWK::parseKeySet($set, 'RS256');
        } catch (\Throwable $e) {
            throw new OidcException('jwks-invalid', 'The provider\'s JWKS cannot be read: '.$e->getMessage(), $e);
        }
    }

    /**
     * A key without `alg` gets the one its type means: the library would
     * otherwise label every such key RS256, and an EC key labelled so never
     * verifies anything.
     *
     * @param  array<string, mixed>  $key
     * @return array<string, mixed>
     */
    private static function withAlg(array $key): array
    {
        if (isset($key['alg'])) {
            return $key;
        }
        $key['alg'] = match ([$key['kty'] ?? null, $key['crv'] ?? null]) {
            ['EC', 'P-256'] => 'ES256',
            ['EC', 'P-384'] => 'ES384',
            ['OKP', 'Ed25519'] => 'EdDSA',
            default => 'RS256',
        };

        return $key;
    }

    private function ttl(): int
    {
        return max(60, (int) config('admin-oidc.cache_ttl', 3600));
    }
}
