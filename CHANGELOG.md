# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] — 2026-10-14

### Added

- OpenID Connect sign-in for laravel-admin panels: Authorization Code with
  PKCE (S256), `state` and `nonce`; discovery and JWKS cached, a key rotation
  followed at once; ID token checks (asymmetric algorithms only, issuer,
  audience and authorized party, expiry with leeway, nonce); userinfo when
  the roles live there or the token has no email.
- "Sign in with …" buttons on the login page through the core's external
  login hook; refusals shown above the form through `LoginNotice`.
- Accounts: linked by (provider, `sub`); a first sign-in takes over an
  account with the same email only when the provider verified it and the
  provider allows it; otherwise a new account without a usable password.
- Roles mapped from a claim path to panel roles and replaced at every
  sign-in; no mapped role — no sign-in and no account.
- Optional MFA requirement from the `amr` claim.
- Contracts for hosts with tenants: `ProviderResolver` (with sign-in links
  `/{panel}/sso/to/{hint}`), `UserProvisioner`, `RoleCatalog`.
- "SSO providers" resource with a "Check" action (discovery and keys).
- Outgoing requests through an address policy: https only unless allowed,
  every resolved address checked, private ranges refused unless whitelisted,
  no redirects, a timeout and a size cap.
- Tests against a fake provider and, in CI, against Keycloak 26.

[0.1.0]: https://github.com/dskripchenko/laravel-admin-oidc/releases/tag/v0.1.0
