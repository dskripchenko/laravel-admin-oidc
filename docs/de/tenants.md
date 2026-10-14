# Mandanten und Verträge

Ein Host mit Mandanten — ein SaaS, in dem jedes Kundenunternehmen einen
eigenen Identitätsanbieter hat — bindet drei Verträge in
`config/admin-oidc.php`.

## ProviderResolver — welche Anbieter eine Anmeldeseite anbietet

```php
interface ProviderResolver
{
    public function offered(Request $request, string $panel, ?string $hint): array;
    public function knowsHint(string $panel, string $hint): bool;
}
```

Der Anbieter eines Mandanten hat einen Eigentümer (`owner_type`/`owner_id`).
Geben Sie dem Mandanten einen eigenen Link `/{panel}/sso/to/{hint}`: Wenn
`knowsHint()` den Hinweis bestätigt, merkt sich das Paket ihn in der Session,
schickt die Person zur Anmeldeseite, und `offered()` erhält ihn — geben Sie
die aktivierten Anbieter dieses Mandanten zurück.

## UserProvisioner — wessen Konten ein Anbieter erreicht

```php
interface UserProvisioner
{
    public function findByEmail(OidcProvider $provider, string $email): (Authenticatable&Model)|null;
    public function create(OidcProvider $provider, Claims $claims): Authenticatable&Model;
}
```

Der Anbieter eines Mandanten darf **nur Benutzer dieses Mandanten** finden und
anlegen: Sonst könnte ein Anbieter, den ein Mandant steuert, über die E-Mail
das Konto eines anderen Mandanten übernehmen. `DefaultUserProvisioner` hat
einen `fill()`-Hook für Mandantenspalten.

## RoleCatalog — welche Rollen eine Zuordnung vergeben darf

```php
interface RoleCatalog
{
    public function roles(OidcProvider $provider): Collection;
}
```

Anbieter eines Mandanten bekommen nur dessen Rollen, der Anbieter eines Panels
nur die Rollen des Panels.

## Ereignisse

- `OidcSignedIn` — Benutzer, Anbieter, wie das Konto gefunden wurde
  (`existing`, `linked`, `created`), und die vergebenen Rollen.
- `OidcSignInRefused` — der Anbieter (falls bekannt), der Grundschlüssel, das
  Detail.
