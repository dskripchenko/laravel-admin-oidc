# Security

**The flow.** Authorization Code with PKCE (S256). `state`, `nonce` and the
PKCE verifier live in the session under the `state`, taken once: a replayed
callback finds nothing. A started sign-in expires after `flow_ttl` (600 s).

**The ID token** (OIDC Core §3.1.3.7):

- signature against the provider's JWKS with RS256/384/512, PS256,
  ES256/384 or EdDSA — never `none`, never HMAC; an unknown key id re-reads
  the JWKS once (key rotation);
- `iss` equals the configured issuer, which the discovery document must name
  exactly;
- `aud` contains the client ID, `azp` names it when there are several
  audiences;
- `exp` present and not past, `iat`/`nbf` respected, with
  `admin-oidc.leeway` (60 s);
- `nonce` equals the sign-in's; `sub` present.

**Accounts.** Linked by (provider, `sub`), never by email alone. A first
sign-in takes over an account with the same email only when the provider says
`email_verified: true` and the provider allows linking. New accounts have a
random password nobody knows.

**Roles.** Replaced by the mapping at every sign-in; only roles the
`RoleCatalog` allows; no mapped role — no sign-in.

**MFA.** The local TOTP of laravel-admin belongs to the password form; an SSO
sign-in leaves MFA to the provider. With *Require MFA* the sign-in is refused
unless `amr` names a second factor (`admin-oidc.mfa_amr_values`).

**Network.** Every request to a provider: https only (http by
`allow_http`), every address the host resolves to checked, private, loopback
and link-local ranges refused unless in `allowed_cidrs`, no redirects
followed, a timeout and a response size cap.

**Secrets.** The client secret is stored with Laravel's encrypter and never
serialized; the ID token is kept in the session for the sign-out at the
provider.

**Signing out.** Leaving the panel after an SSO sign-in sends the browser to
the provider's `end_session_endpoint` with the ID token as `id_token_hint`
and the panel's login page as `post_logout_redirect_uri`, so the next "Sign
in with …" asks who it is again. Off per provider with *Sign out at the
provider too*. Back-channel logout is not supported: a session ended at the
provider lives on in the panel until the panel's own sign-out or expiry.
