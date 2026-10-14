# dskripchenko/laravel-admin-oidc

> 🌐 **English** · [Русский](docs/ru/README.md) · [Deutsch](docs/de/README.md) · [中文](docs/zh/README.md)

Sign in to [`dskripchenko/laravel-admin`](https://github.com/dskripchenko/laravel-admin)
panels with OpenID Connect — Keycloak, Microsoft Entra ID, ADFS, Authentik,
Okta or any OIDC provider. Plain OIDC, no provider-specific code: the
differences between providers are settings.

- **"Sign in with …" on the login page**, under the password form, which stays.
- **Accounts created on the first sign-in**; an existing account with the same
  email is taken over only when the provider verified the email.
- **Roles from the provider**: claim values (`realm_access.roles`, `groups`, …)
  mapped to panel roles, replaced at every sign-in. No mapped role — no way in.
- **Several panels, per-tenant providers**: the panel's own provider, or a
  tenant's behind a link of its own (`/{panel}/sso/to/{tenant}`), through
  three small contracts the host implements.
- **Checked the way OIDC Core asks**: Authorization Code + PKCE (S256),
  `state` and `nonce`, the ID token verified against the provider's JWKS
  (asymmetric algorithms only, key rotation followed), issuer, audience,
  expiry. Requests to the provider go through an address policy (https,
  private networks refused unless whitelisted).
- Optional **MFA requirement** from the provider's `amr` claim.

[![Packagist](https://img.shields.io/packagist/v/dskripchenko/laravel-admin-oidc)](https://packagist.org/packages/dskripchenko/laravel-admin-oidc)
[![License](https://img.shields.io/packagist/l/dskripchenko/laravel-admin-oidc)](LICENSE)

## Install

```bash
composer require dskripchenko/laravel-admin-oidc
php artisan migrate
```

Requires laravel-admin 1.50+. The plugin registers itself through package
discovery; the providers are managed in the panel, **System → SSO providers**.
To publish the config:

```bash
php artisan vendor:publish --tag=admin-oidc-config
```

## Documentation

- [Getting started](docs/en/getting-started.md) — install, a provider, Keycloak step by step, role mapping
- [Tenants and contracts](docs/en/tenants.md) — per-tenant providers, custom user provisioning and role catalogs
- [Security](docs/en/security.md) — what is checked, the network policy, MFA

## License

MIT.
