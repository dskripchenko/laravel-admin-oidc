# Getting started

## 1. Install

```bash
composer require dskripchenko/laravel-admin-oidc
php artisan migrate
```

The plugin registers through package discovery and adds:

- routes under each panel it serves: `/{panel}/sso/redirect/{provider}`,
  `/{panel}/sso/callback`, `/{panel}/sso/to/{hint}`;
- "Sign in with …" buttons on that panel's login page;
- the **System → SSO providers** resource and its permission
  `admin.oidc.providers`.

Which panels it serves is `admin-oidc.panels` (by default `['admin']`). For a
second panel (`admin.panels.client`, say):

```php
// config/admin-oidc.php
'panels' => ['admin', 'client'],
```

## 2. A provider

In **System → SSO providers → Create**:

| Field | What goes there |
|---|---|
| Name | The button reads "Sign in with *Name*". |
| Panel | The panel this provider signs into. |
| Issuer | The address the provider publishes `/.well-known/openid-configuration` under. Keycloak: `https://keycloak.example.com/realms/<realm>`. |
| Client ID / secret | The client you create at the provider (below). The secret is stored encrypted; leave it empty when editing to keep it. |
| Scopes | `openid email profile` by default. |
| Where the roles are | A dot path into the claims: Keycloak `realm_access.roles` or `resource_access.<client>.roles`, Entra ID `roles`, Authentik `groups`. |
| Where to read the roles from | The ID token or userinfo. |
| Role mapping | Claim value → panel role. Sign-in needs at least one match. |
| Require MFA | Refuse when the `amr` claim names no second factor. |
| Link by email | A first sign-in may take over an account with the same, provider-verified email. |
| Sign out at the provider too | Signing out of the panel ends the provider's session as well (RP-initiated logout), when the provider announces an `end_session_endpoint`. On by default. |

Press **Check** on the row: the discovery document and the signing keys are
read, and a mistake shows up before anyone tries to sign in.

The form's help under *Issuer* lists the **redirect URIs** of the panels —
`https://your-app/admin/sso/callback` — for the provider's client settings.

## 3. Keycloak, step by step

1. **Clients → Create client**: type *OpenID Connect*, a client ID
   (`panel`), *Client authentication* on (a confidential client), *Standard
   flow* on, *Direct access grants* off.
2. **Valid redirect URIs**: the panel's callback,
   `https://your-app/admin/sso/callback`. **Valid post logout redirect
   URIs**: the panel's login page, `https://your-app/admin/login`.
3. **Advanced → Proof Key for Code Exchange**: `S256`.
4. **Credentials**: copy the client secret into the provider form.
5. **Roles in the ID token.** Keycloak puts realm roles into the access
   token only. Either set *Where to read the roles from* to *userinfo*, or
   add a mapper: **Client scopes → `<client>-dedicated` → Add mapper → By
   configuration → User Realm Role**, claim name `realm_access.roles`, *Add to
   ID token* on. For client roles use *User Client Role* and the path
   `resource_access.<client>.roles`.
6. **MFA** (optional): to let the panel require it, add the
   *Authentication Method Reference (AMR)* mapper to the client's dedicated
   scope, and turn on *Require MFA* in the provider form.
7. Create realm roles (`panel-admins`, `panel-viewers`), assign them to the
   users, and map them in the provider form.

## 4. Role mapping

The values at the configured claim path are matched against the mapping; the
panel roles they name replace the account's roles **at every sign-in** — a
role taken away at the provider is taken away here on the next sign-in.
When nothing matches, the person is turned away with "no access: ask your
administrator", and no account is created.

Roles given by hand alongside the provider's: set
`preserve_unmapped_roles` (env `ADMIN_OIDC_PRESERVE_UNMAPPED_ROLES=true`).
Then only the roles the mapping can hand out are replaced; the rest stay.

## 5. Network

Requests to the provider go through an address policy:

```php
'http' => [
    'allow_http' => false,             // a test stand only
    'block_private_networks' => true,
    'allowed_cidrs' => ['10.20.0.0/16'], // a Keycloak inside the network
],
```

## 6. Troubleshooting

The reason of a refused sign-in is in the log (`admin-oidc: sign-in stopped`)
and in the `OidcSignInRefused` event, with a stable key:

| Reason | Usually |
|---|---|
| `no-role` | The mapping has no value the token carries; or Keycloak does not put roles into the ID token (step 5). |
| `email-taken` | A local account has the email and the provider did not verify it, or linking is off. |
| `mfa-required` | The provider sends no `amr` with a second factor (step 6). |
| `token-invalid` | Wrong issuer or client ID, a clock far off, keys from another realm. |
| `provider-unreachable` | The issuer is wrong, the network policy refuses the address, the provider is down. |
| `flow-expired` | The sign-in took longer than `flow_ttl`, or the callback was replayed. |
