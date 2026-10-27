# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).


## [Unreleased] — 0.3.0

### Added
- **`OidcSignedIn` carries the claims** of the sign-in (`$event->claims`), so a
  host can keep a profile in step with the provider — name, groups,
  department — at every sign-in. The new constructor argument is optional and
  last; existing listeners keep working.
- **`preserve_unmapped_roles`** (env `ADMIN_OIDC_PRESERVE_UNMAPPED_ROLES`, off
  by default): only the roles the provider's mapping can hand out are
  replaced at sign-in; roles an administrator gave by hand stay.
- `OidcProvider::mappedRoles()` — every role slug the mapping can hand out.
- **`Protocol\AccessTokenVerifier`** for the host's own resource servers (an
  API, an MCP endpoint) that take JWT access tokens of the provider
  (RFC 9068): signature by the provider's JWKS with the same algorithms as ID
  tokens (no `none`, no HMAC), issuer, expiry, an `aud` naming the resource,
  a subject; an ID token used as an access token is refused.
  `AccessTokenVerifier::scopes()` reads `scope` or `scp`.

## [0.2.0] — 2026-10-15

### Added

- **Signing out at the provider (RP-initiated logout).** Leaving the panel
  after an SSO sign-in sends the browser to the provider's
  `end_session_endpoint` with the ID token as `id_token_hint` and the panel's
  login page as `post_logout_redirect_uri`, so the provider's session ends
  too and the next "Sign in with …" asks again. A new provider switch, *Sign
  out at the provider too* (on by default, column `sign_out_at_provider` —
  run the migrations); its hint shows the address for the provider's *Valid
  post logout redirect URIs*. Built on the core's `Admin::logoutRedirect()`,
  hence laravel-admin 1.51.
- The Keycloak job of the CI checks the sign-out end to end: Keycloak accepts
  it, returns to the login page and asks for the password again.

### Changed

- Requires `dskripchenko/laravel-admin` ^1.51.
- The getting-started guide names the panel's login page
  (`/admin/login`) as the post logout redirect URI, not the panel's root.

## [0.1.2] — 2026-10-14

### Fixed

- Under Octane, an SSO sign-in pinned its panel in the core's panel
  registry, a singleton that outlives the request: the worker then served
  every later request as that panel — the login page of another panel came
  up as the signed-in one, with its buttons and API. The panel is now kept on
  the request (`admin.panel` attribute) and goes with it.

## [0.1.1] — 2026-10-14

### Security

- The audit row of an SSO sign-in no longer keeps the callback's
  authorization code and state: it takes the request context from the
  core's `AuditLog::requestContext()`, which masks them. Requires
  laravel-admin 1.50.1, where the audit columns hold long URLs — on
  PostgreSQL the callback URL used to fail the core's own audit insert.

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
[0.1.1]: https://github.com/dskripchenko/laravel-admin-oidc/releases/tag/v0.1.1
[0.1.2]: https://github.com/dskripchenko/laravel-admin-oidc/releases/tag/v0.1.2
[0.2.0]: https://github.com/dskripchenko/laravel-admin-oidc/releases/tag/v0.2.0
