# dskripchenko/laravel-admin-oidc

> 🌐 [English](../../README.md) · [Русский](../ru/README.md) · **Deutsch** · [中文](../zh/README.md)

Anmeldung an [`dskripchenko/laravel-admin`](https://github.com/dskripchenko/laravel-admin)-Panels
per OpenID Connect — Keycloak, Microsoft Entra ID, ADFS, Authentik, Okta oder
jeder OIDC-Anbieter. Reines OIDC ohne anbieterspezifischen Code: Unterschiede
zwischen Anbietern sind Einstellungen.

- **„Anmelden mit …" auf der Anmeldeseite**, unter dem Passwortformular, das bleibt.
- **Konten werden bei der ersten Anmeldung angelegt**; ein vorhandenes Konto
  mit derselben E-Mail wird nur übernommen, wenn der Anbieter die E-Mail
  bestätigt hat.
- **Rollen vom Anbieter**: Claim-Werte (`realm_access.roles`, `groups` …)
  werden Panel-Rollen zugeordnet und bei jeder Anmeldung ersetzt. Keine
  zugeordnete Rolle — kein Zugang.
- **Mehrere Panels, Anbieter je Mandant** — über einen eigenen Link
  (`/{panel}/sso/to/{mandant}`) und drei kleine Verträge des Hosts.
- **Geprüft nach OIDC Core**: Authorization Code + PKCE (S256), `state` und
  `nonce`, ID-Token gegen das JWKS des Anbieters (nur asymmetrische
  Algorithmen, Schlüsselrotation), Issuer, Audience, Ablauf. Anfragen an den
  Anbieter laufen über eine Adressrichtlinie (https, private Netze verboten,
  außer auf der Whitelist).
- Optional **MFA-Pflicht** über den Claim `amr`.

## Installation

```bash
composer require dskripchenko/laravel-admin-oidc
php artisan migrate
```

Erfordert laravel-admin 1.50+. Die Anbieter werden im Panel verwaltet:
**System → SSO-Anbieter**.

## Dokumentation

- [Erste Schritte](getting-started.md) — Installation, ein Anbieter, Keycloak Schritt für Schritt, Rollenzuordnung
- [Mandanten und Verträge](tenants.md) — Anbieter je Mandant, eigene Kontoanlage und Rollenkataloge
- [Sicherheit](security.md) — was geprüft wird, die Netzwerkrichtlinie, MFA

## Lizenz

MIT.
