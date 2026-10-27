<?php

declare(strict_types=1);

return [

    /*
    | The panels that offer "Sign in with SSO": the ids of the laravel-admin
    | panels ('admin' and those under admin.panels). The package adds its
    | routes under each panel's path — /{path}/sso/redirect, /callback, /to.
    */
    'panels' => ['admin'],

    'route_prefix' => 'sso',

    /*
    | The host's seams (see the contracts in src/Contracts). The defaults: one
    | provider per panel, users of the panel's own model, every role.
    */
    'resolver' => Dskripchenko\LaravelAdminOidc\Defaults\DefaultProviderResolver::class,
    'provisioner' => Dskripchenko\LaravelAdminOidc\Defaults\DefaultUserProvisioner::class,
    'role_catalog' => Dskripchenko\LaravelAdminOidc\Defaults\DefaultRoleCatalog::class,

    /*
    | The roles of an account are replaced at every sign-in by what the mapping
    | gives. With this on, only the roles the provider's mapping can hand out
    | are replaced, and roles given by hand outside the mapping stay.
    */
    'preserve_unmapped_roles' => (bool) env('ADMIN_OIDC_PRESERVE_UNMAPPED_ROLES', false),

    /*
    | Allowed clock skew when checking exp / iat / nbf, seconds.
    */
    'leeway' => 60,

    /*
    | How long a started sign-in may take, seconds — from the redirect to the
    | provider until the callback.
    */
    'flow_ttl' => 600,

    /*
    | Discovery documents and JWKS are cached this long, seconds. An unknown
    | key id re-reads the JWKS once, so a key rotation needs no wait.
    */
    'cache_ttl' => 3600,

    /*
    | The `amr` values that count as multi-factor, for providers set to
    | require MFA. Keycloak sends `amr` with its "Authentication Method
    | Reference (AMR)" mapper.
    */
    'mfa_amr_values' => ['mfa', 'otp', 'totp', 'hwk', 'swk', 'sms', 'fido', 'webauthn'],

    /*
    | Outgoing requests to the providers. The addresses are typed in by an
    | administrator, so private networks are refused unless listed — a
    | Keycloak inside the customer's network goes into allowed_cidrs. Plain
    | http only for a test stand.
    */
    'http' => [
        'timeout' => (int) env('ADMIN_OIDC_HTTP_TIMEOUT', 10),
        'max_response_kb' => (int) env('ADMIN_OIDC_MAX_RESPONSE_KB', 512),
        'allow_http' => (bool) env('ADMIN_OIDC_ALLOW_HTTP', false),
        'block_private_networks' => (bool) env('ADMIN_OIDC_BLOCK_PRIVATE', true),
        'allowed_cidrs' => array_values(array_filter(explode(',', (string) env('ADMIN_OIDC_ALLOWED_CIDRS', '')))),
    ],

    /*
    | The "SSO providers" resource of the service panel. The owner column
    | (a tenant's own provider) is edited by the host where the owner lives;
    | the resource lists the panels' own providers only, unless told otherwise.
    */
    'resource' => [
        'panel' => 'admin',
        'show_owned' => false,
    ],
];
