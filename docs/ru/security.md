# Безопасность

**Поток.** Authorization Code с PKCE (S256). `state`, `nonce` и PKCE verifier
лежат в сессии под `state` и забираются один раз: повторный callback ничего не
находит. Начатый вход истекает через `flow_ttl` (600 с).

**ID-токен** (OIDC Core §3.1.3.7):

- подпись по JWKS провайдера алгоритмом RS256/384/512, PS256, ES256/384 или
  EdDSA — никогда `none` и никогда HMAC; незнакомый идентификатор ключа
  перечитывает JWKS один раз (смена ключей);
- `iss` равен настроенному issuer, который discovery-документ обязан называть
  точно;
- `aud` содержит client ID, `azp` называет его, если аудиторий несколько;
- `exp` есть и не прошёл, `iat`/`nbf` учитываются, допуск —
  `admin-oidc.leeway` (60 с);
- `nonce` совпадает с нонсом входа; `sub` есть.

**Учётки.** Связываются по паре (провайдер, `sub`), никогда только по email.
Первый вход занимает учётку с тем же email, только если провайдер сообщил
`email_verified: true` и связывание разрешено. У новых учёток случайный
пароль, которого никто не знает.

**Роли.** Заменяются маппингом при каждом входе; только те, что разрешает
`RoleCatalog`; ни одной роли — нет входа.

**MFA.** Локальная TOTP laravel-admin относится к форме пароля; вход через SSO
оставляет MFA провайдеру. С флагом *Требовать MFA* вход отклоняется, если
`amr` не называет второй фактор (`admin-oidc.mfa_amr_values`).

**Сеть.** Каждый запрос к провайдеру: только https (http — через
`allow_http`), проверяется каждый адрес, в который разрешается хост, частные,
loopback и link-local диапазоны запрещены, если их нет в `allowed_cidrs`,
редиректы не выполняются, есть таймаут и предел размера ответа.

**Секреты.** Client secret хранится через шифровальщик Laravel и никогда не
сериализуется; ID-токен лежит в сессии для выхода у провайдера.

**Выход.** Выход из панели после входа через SSO отправляет браузер на
`end_session_endpoint` провайдера с ID-токеном в `id_token_hint` и экраном
входа панели в `post_logout_redirect_uri` — следующее «Войти через …» снова
спросит, кто это. Отключается у провайдера флагом *Выходить и у провайдера*.
Back-channel logout не поддерживается: сессия, завершённая у провайдера,
живёт в панели до выхода из панели или своего истечения.


## Access-токены для собственных API

Хост может быть и сервером ресурсов: API или MCP-эндпоинтом, принимающим
JWT access-токены провайдера (RFC 9068). `AccessTokenVerifier` проверяет их
так же, как `IdTokenVerifier` — ID-токены: подпись ключом провайдера
асимметричным алгоритмом (без `none` и HMAC), настроенный issuer, срок,
наличие субъекта, — и требует, чтобы `aud` называл ваш ресурс. ID-токен,
поданный вместо access-токена (`typ: ID`), отклоняется.

```php
use Dskripchenko\LaravelAdminOidc\Models\OidcProvider;
use Dskripchenko\LaravelAdminOidc\Protocol\AccessTokenVerifier;
use Dskripchenko\LaravelAdminOidc\Protocol\Discovery;

$claims = app(AccessTokenVerifier::class)->verify(
    $request->bearerToken(),
    $provider,                                   // an enabled OidcProvider
    app(Discovery::class)->for($provider),
    ['https://app.example.com/api/mcp'],         // what this resource answers to
);
$scopes = AccessTokenVerifier::scopes($claims); // from `scope` or `scp`
```

Пользователя сопоставляйте по `OidcIdentity` (провайдер и субъект), а не по
e-mail. Ошибки — `OidcException` с причиной в сообщении.
