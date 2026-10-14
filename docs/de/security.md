# Sicherheit

**Der Ablauf.** Authorization Code mit PKCE (S256). `state`, `nonce` und der
PKCE-Verifier liegen in der Session unter dem `state` und werden einmal
entnommen: Ein wiederholter Callback findet nichts. Eine begonnene Anmeldung
läuft nach `flow_ttl` (600 s) ab.

**Das ID-Token** (OIDC Core §3.1.3.7):

- Signatur gegen das JWKS des Anbieters mit RS256/384/512, PS256, ES256/384
  oder EdDSA — nie `none`, nie HMAC; eine unbekannte Schlüssel-ID liest das
  JWKS einmal neu (Schlüsselrotation);
- `iss` entspricht dem konfigurierten Issuer, den das Discovery-Dokument
  exakt nennen muss;
- `aud` enthält die Client-ID, `azp` nennt sie bei mehreren Audiences;
- `exp` vorhanden und nicht abgelaufen, `iat`/`nbf` beachtet, mit
  `admin-oidc.leeway` (60 s);
- `nonce` entspricht dem der Anmeldung; `sub` vorhanden.

**Konten.** Verknüpft über (Anbieter, `sub`), nie nur über die E-Mail. Eine
erste Anmeldung übernimmt ein Konto mit derselben E-Mail nur, wenn der
Anbieter `email_verified: true` meldet und Verknüpfen erlaubt ist. Neue Konten
haben ein zufälliges Passwort, das niemand kennt.

**Rollen.** Bei jeder Anmeldung durch die Zuordnung ersetzt; nur Rollen, die
der `RoleCatalog` erlaubt; keine zugeordnete Rolle — keine Anmeldung.

**MFA.** Die lokale TOTP von laravel-admin gehört zum Passwortformular; eine
SSO-Anmeldung überlässt MFA dem Anbieter. Mit *MFA verlangen* wird die
Anmeldung abgelehnt, wenn `amr` keinen zweiten Faktor nennt
(`admin-oidc.mfa_amr_values`).

**Netzwerk.** Jede Anfrage an einen Anbieter: nur https (http über
`allow_http`), jede Adresse, in die der Host aufgelöst wird, geprüft, private,
Loopback- und Link-local-Bereiche verboten, außer in `allowed_cidrs`, keine
Weiterleitungen, ein Timeout und eine Größenbegrenzung der Antwort.

**Geheimnisse.** Das Client-Secret wird mit Laravels Encrypter gespeichert und
nie serialisiert; das ID-Token liegt für die Abmeldung beim Anbieter in der
Session.

**Abmeldung.** Wer das Panel nach einer SSO-Anmeldung verlässt, wird zum
`end_session_endpoint` des Anbieters geschickt, mit dem ID-Token als
`id_token_hint` und der Anmeldeseite des Panels als
`post_logout_redirect_uri` — das nächste „Anmelden mit …“ fragt wieder, wer es
ist. Pro Anbieter abschaltbar mit *Auch beim Anbieter abmelden*.
Back-Channel-Logout wird nicht unterstützt: Eine beim Anbieter beendete
Sitzung lebt im Panel bis zur Abmeldung dort oder bis zu ihrem Ablauf.
