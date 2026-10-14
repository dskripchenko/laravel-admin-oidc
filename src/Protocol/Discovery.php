<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelAdminOidc\Protocol;

use Dskripchenko\LaravelAdminOidc\Http\ProviderHttp;
use Dskripchenko\LaravelAdminOidc\Models\OidcProvider;
use Illuminate\Support\Facades\Cache;

/**
 * The provider's `/.well-known/openid-configuration`, cached. The document
 * must name the configured issuer exactly: a document that speaks for another
 * issuer would let its keys vouch for tokens it should not.
 */
final class Discovery
{
    public function __construct(private readonly ProviderHttp $http) {}

    public function for(OidcProvider $provider, bool $fresh = false): ProviderMetadata
    {
        $issuer = $provider->normalizedIssuer();
        $key = 'admin-oidc:discovery:'.sha1($issuer);
        if ($fresh) {
            Cache::forget($key);
        }
        /** @var array<string, mixed> $doc */
        $doc = Cache::remember($key, $this->ttl(), fn (): array => $this->http->getJson($issuer.'/.well-known/openid-configuration'));
        $metadata = ProviderMetadata::fromDocument($doc);
        if ($metadata->issuer !== $issuer) {
            Cache::forget($key);
            throw new OidcException('discovery-invalid', sprintf('The discovery document is for %s, not %s', $metadata->issuer, $issuer));
        }

        return $metadata;
    }

    private function ttl(): int
    {
        return max(60, (int) config('admin-oidc.cache_ttl', 3600));
    }
}
