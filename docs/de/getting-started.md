# Erste Schritte

## 1. Installation

```bash
composer require dskripchenko/laravel-admin-oidc
php artisan migrate
```

Das Plugin registriert sich über Package Discovery und fügt hinzu:

- Routen unter jedem bedienten Panel: `/{panel}/sso/redirect/{provider}`,
  `/{panel}/sso/callback`, `/{panel}/sso/to/{hint}`;
- Schaltflächen „Anmelden mit …" auf der Anmeldeseite dieses Panels;
- die Ressource **System → SSO-Anbieter** und ihre Berechtigung
  `admin.oidc.providers`.

Welche Panels bedient werden, steht in `admin-oidc.panels` (Standard
`['admin']`). Für ein zweites Panel (etwa `admin.panels.client`):

```php
// config/admin-oidc.php
'panels' => ['admin', 'client'],
```

## 2. Ein Anbieter

**System → SSO-Anbieter → Erstellen**:

| Feld | Inhalt |
|---|---|
| Name | Die Schaltfläche lautet „Anmelden mit *Name*". |
| Panel | Das Panel, in das dieser Anbieter anmeldet. |
| Issuer | Die Adresse, unter der der Anbieter `/.well-known/openid-configuration` veröffentlicht. Keycloak: `https://keycloak.example.com/realms/<realm>`. |
| Client ID / Secret | Der beim Anbieter angelegte Client (unten). Das Secret wird verschlüsselt gespeichert; beim Bearbeiten leer lassen, um es zu behalten. |
| Scopes | Standard `openid email profile`. |
| Wo die Rollen im Token stehen | Ein Punktpfad in die Claims: Keycloak `realm_access.roles` oder `resource_access.<client>.roles`, Entra ID `roles`, Authentik `groups`. |
| Woher die Rollen kommen | ID-Token oder userinfo. |
| Rollenzuordnung | Claim-Wert → Panel-Rolle. Für die Anmeldung braucht es mindestens einen Treffer. |
| MFA verlangen | Ablehnen, wenn der Claim `amr` keinen zweiten Faktor nennt. |
| Per E-Mail verknüpfen | Eine erste Anmeldung darf ein Konto mit derselben, vom Anbieter bestätigten E-Mail übernehmen. |

**Prüfen** in der Zeile liest das Discovery-Dokument und die
Signaturschlüssel — ein Fehler zeigt sich, bevor sich jemand anmeldet.

Die Hilfe unter *Issuer* nennt die **Redirect-URIs** der Panels —
`https://your-app/admin/sso/callback` — für die Client-Einstellungen beim
Anbieter.

## 3. Keycloak Schritt für Schritt

1. **Clients → Create client**: Typ *OpenID Connect*, eine Client-ID
   (`panel`), *Client authentication* an (vertraulicher Client), *Standard
   flow* an, *Direct access grants* aus.
2. **Valid redirect URIs**: der Callback des Panels,
   `https://your-app/admin/sso/callback`; **Valid post logout redirect URIs**:
   das Panel, `https://your-app/admin`.
3. **Advanced → Proof Key for Code Exchange**: `S256`.
4. **Credentials**: das Client-Secret ins Anbieterformular kopieren.
5. **Rollen im ID-Token.** Keycloak legt Realm-Rollen nur in das
   Access-Token. Entweder *Woher die Rollen kommen* auf *userinfo* stellen
   oder einen Mapper hinzufügen: **Client scopes → `<client>-dedicated` → Add
   mapper → By configuration → User Realm Role**, Claim-Name
   `realm_access.roles`, *Add to ID token* an. Für Client-Rollen *User Client
   Role* und den Pfad `resource_access.<client>.roles`.
6. **MFA** (optional): Damit das Panel sie verlangen kann, den Mapper
   *Authentication Method Reference (AMR)* zum dedizierten Scope des Clients
   hinzufügen und *MFA verlangen* im Anbieterformular einschalten.
7. Realm-Rollen anlegen (`panel-admins`, `panel-viewers`), den Benutzern
   zuweisen und in der Zuordnung eintragen.

## 4. Rollenzuordnung

Die Werte am konfigurierten Claim-Pfad werden mit der Zuordnung verglichen;
die genannten Panel-Rollen ersetzen die Rollen des Kontos **bei jeder
Anmeldung** — eine beim Anbieter entzogene Rolle fällt hier bei der nächsten
Anmeldung weg. Ohne Treffer sieht die Person „Kein Zugriff: Wenden Sie sich an
Ihren Administrator", und es wird kein Konto angelegt.

## 5. Netzwerk

Anfragen an den Anbieter laufen über eine Adressrichtlinie:

```php
'http' => [
    'allow_http' => false,               // nur für eine Teststellung
    'block_private_networks' => true,
    'allowed_cidrs' => ['10.20.0.0/16'], // ein Keycloak im internen Netz
],
```

## 6. Fehlersuche

Der Grund einer abgelehnten Anmeldung steht im Log (`admin-oidc: sign-in
stopped`) und im Ereignis `OidcSignInRefused`, mit stabilem Schlüssel:

| Grund | Meist |
|---|---|
| `no-role` | Die Zuordnung enthält keinen Wert aus dem Token; oder Keycloak legt keine Rollen ins ID-Token (Schritt 5). |
| `email-taken` | Ein lokales Konto hat die E-Mail, und der Anbieter hat sie nicht bestätigt oder Verknüpfen ist aus. |
| `mfa-required` | Der Anbieter sendet kein `amr` mit zweitem Faktor (Schritt 6). |
| `token-invalid` | Falscher Issuer oder Client-ID, stark abweichende Uhr, Schlüssel eines anderen Realms. |
| `provider-unreachable` | Falscher Issuer, die Netzwerkrichtlinie verbietet die Adresse, der Anbieter ist nicht erreichbar. |
| `flow-expired` | Die Anmeldung dauerte länger als `flow_ttl`, oder der Callback wurde wiederholt. |
