# Tenants and contracts

A host with tenants — a SaaS where every client company has its own identity
provider — binds three contracts in `config/admin-oidc.php`.

## ProviderResolver — which providers a login page offers

```php
interface ProviderResolver
{
    public function offered(Request $request, string $panel, ?string $hint): array;
    public function knowsHint(string $panel, string $hint): bool;
}
```

A tenant's provider has an owner (`owner_type`/`owner_id`). Give the tenant a
link of its own, `/{panel}/sso/to/{hint}`: the package keeps the hint in the
session when `knowsHint()` says it is one, sends the person to the login
page, and `offered()` receives it — return that tenant's enabled providers.

## UserProvisioner — whose accounts a provider reaches

```php
interface UserProvisioner
{
    public function findByEmail(OidcProvider $provider, string $email): (Authenticatable&Model)|null;
    public function create(OidcProvider $provider, Claims $claims): Authenticatable&Model;
}
```

A tenant's provider must find and create users **of that tenant only**:
otherwise a provider one tenant controls could take over another tenant's
account by its email. `DefaultUserProvisioner` has a `fill()` hook for tenant
columns.

## RoleCatalog — which roles a mapping may hand out

```php
interface RoleCatalog
{
    public function roles(OidcProvider $provider): Collection;
}
```

Keep a tenant's providers to the tenant's roles, the panel's provider to the
panel's.

## Events

- `OidcSignedIn` — the user, the provider, how the account was found
  (`existing`, `linked`, `created`), the roles the mapping gave and the
  verified claims of the sign-in (`$event->claims`) — to keep a profile in
  step with the provider: name, groups, department.
- `OidcSignInRefused` — the provider (when known), the reason key, the detail.
