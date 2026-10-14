<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelAdminOidc\Protocol;

/** What a provider's discovery document says, the parts the sign-in uses. */
final readonly class ProviderMetadata
{
    /**
     * @param  list<string>  $tokenAuthMethods
     */
    public function __construct(
        public string $issuer,
        public string $authorizationEndpoint,
        public string $tokenEndpoint,
        public string $jwksUri,
        public ?string $userinfoEndpoint,
        public ?string $endSessionEndpoint,
        public array $tokenAuthMethods,
    ) {}

    /** @param array<string, mixed> $doc */
    public static function fromDocument(array $doc): self
    {
        foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $key) {
            if (! is_string($doc[$key] ?? null) || $doc[$key] === '') {
                throw new OidcException('discovery-invalid', sprintf('The discovery document has no %s', $key));
            }
        }
        $methods = array_values(array_filter((array) ($doc['token_endpoint_auth_methods_supported'] ?? ['client_secret_basic']), 'is_string'));

        return new self(
            rtrim((string) $doc['issuer'], '/'),
            (string) $doc['authorization_endpoint'],
            (string) $doc['token_endpoint'],
            (string) $doc['jwks_uri'],
            is_string($doc['userinfo_endpoint'] ?? null) ? $doc['userinfo_endpoint'] : null,
            is_string($doc['end_session_endpoint'] ?? null) ? $doc['end_session_endpoint'] : null,
            $methods,
        );
    }
}
