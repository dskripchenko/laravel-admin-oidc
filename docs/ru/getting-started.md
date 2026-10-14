# Быстрый старт

## 1. Установка

```bash
composer require dskripchenko/laravel-admin-oidc
php artisan migrate
```

Плагин подключается через package discovery и добавляет:

- маршруты под каждой обслуживаемой панелью: `/{panel}/sso/redirect/{provider}`,
  `/{panel}/sso/callback`, `/{panel}/sso/to/{hint}`;
- кнопки «Войти через …» на экране входа этой панели;
- ресурс **Система → SSO-провайдеры** и право `admin.oidc.providers`.

Какие панели обслуживаются — `admin-oidc.panels` (по умолчанию `['admin']`).
Для второй панели (`admin.panels.client`):

```php
// config/admin-oidc.php
'panels' => ['admin', 'client'],
```

## 2. Провайдер

**Система → SSO-провайдеры → Создать**:

| Поле | Что указать |
|---|---|
| Название | Кнопка: «Войти через *Название*». |
| Панель | Куда входят через этого провайдера. |
| Issuer | Адрес, где провайдер публикует `/.well-known/openid-configuration`. Keycloak: `https://keycloak.example.com/realms/<realm>`. |
| Client ID / secret | Клиент, заведённый у провайдера (ниже). Секрет хранится зашифрованным; пусто при редактировании — оставить текущий. |
| Scopes | По умолчанию `openid email profile`. |
| Где роли в токене | Путь через точку: Keycloak — `realm_access.roles` или `resource_access.<client>.roles`, Entra ID — `roles`, Authentik — `groups`. |
| Откуда брать роли | ID-токен или userinfo. |
| Маппинг ролей | Значение в токене → роль панели. Для входа нужно хотя бы одно совпадение. |
| Требовать MFA | Отказ, если в claim `amr` нет второго фактора. |
| Связывать по email | Первый вход может занять учётку с тем же email, подтверждённым провайдером. |
| Выходить и у провайдера | Выход из панели завершает и сессию у провайдера (RP-initiated logout), если провайдер объявил `end_session_endpoint`. Включено по умолчанию. |

Кнопка **Проверить** в строке провайдера читает discovery и ключи подписи —
ошибка настройки видна до того, как кто-то попробует войти.

В подсказке поля *Issuer* — **redirect URI** панелей
(`https://your-app/admin/sso/callback`) для настроек клиента у провайдера.

## 3. Keycloak по шагам

1. **Clients → Create client**: тип *OpenID Connect*, client ID (`panel`),
   *Client authentication* — вкл. (конфиденциальный клиент), *Standard flow* —
   вкл., *Direct access grants* — выкл.
2. **Valid redirect URIs** — callback панели,
   `https://your-app/admin/sso/callback`; **Valid post logout redirect URIs** —
   экран входа панели, `https://your-app/admin/login`.
3. **Advanced → Proof Key for Code Exchange** — `S256`.
4. **Credentials** — скопируйте client secret в форму провайдера.
5. **Роли в ID-токене.** Keycloak кладёт роли realm только в access-токен.
   Либо выберите *Откуда брать роли — userinfo*, либо добавьте маппер:
   **Client scopes → `<client>-dedicated` → Add mapper → By configuration →
   User Realm Role**, claim name `realm_access.roles`, *Add to ID token* — вкл.
   Для ролей клиента — *User Client Role* и путь `resource_access.<client>.roles`.
6. **MFA** (по желанию): чтобы панель могла его требовать, добавьте в
   dedicated scope клиента маппер *Authentication Method Reference (AMR)* и
   включите *Требовать MFA* в форме провайдера.
7. Заведите роли realm (`panel-admins`, `panel-viewers`), назначьте
   пользователям и сопоставьте в маппинге.

## 4. Маппинг ролей

Значения по настроенному пути сравниваются с маппингом; роли панели, которые
они называют, **заменяют роли учётки при каждом входе** — роль, снятая у
провайдера, снимается и здесь при следующем входе. Если совпадений нет,
человек видит «нет прав на доступ — обратитесь к администратору», и учётка не
создаётся.

## 5. Сеть

Запросы к провайдеру идут через политику адресов:

```php
'http' => [
    'allow_http' => false,               // только для тестового стенда
    'block_private_networks' => true,
    'allowed_cidrs' => ['10.20.0.0/16'], // Keycloak во внутренней сети
],
```

## 6. Если вход не удался

Причина — в логе (`admin-oidc: sign-in stopped`) и в событии
`OidcSignInRefused`, со стабильным ключом:

| Причина | Обычно |
|---|---|
| `no-role` | В маппинге нет значения из токена; или Keycloak не кладёт роли в ID-токен (шаг 5). |
| `email-taken` | Локальная учётка с этим email есть, а провайдер email не подтвердил или связывание выключено. |
| `mfa-required` | Провайдер не присылает `amr` со вторым фактором (шаг 6). |
| `token-invalid` | Не тот issuer или client ID, сильно расходятся часы, ключи другого realm. |
| `provider-unreachable` | Неверный issuer, адрес запрещён политикой сети, провайдер недоступен. |
| `flow-expired` | Вход длился дольше `flow_ttl` или callback повторили. |
