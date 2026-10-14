# Арендаторы и контракты

Хост с арендаторами — SaaS, где у каждой компании-клиента свой провайдер
идентификации, — связывает три контракта в `config/admin-oidc.php`.

## ProviderResolver — каких провайдеров предлагает экран входа

```php
interface ProviderResolver
{
    public function offered(Request $request, string $panel, ?string $hint): array;
    public function knowsHint(string $panel, string $hint): bool;
}
```

У провайдера арендатора есть владелец (`owner_type`/`owner_id`). Дайте
арендатору собственную ссылку `/{panel}/sso/to/{hint}`: если `knowsHint()`
подтверждает подсказку, пакет запоминает её в сессии и отправляет человека на
экран входа, а `offered()` получает её — верните включённых провайдеров этого
арендатора.

## UserProvisioner — чьи учётки достаются провайдеру

```php
interface UserProvisioner
{
    public function findByEmail(OidcProvider $provider, string $email): (Authenticatable&Model)|null;
    public function create(OidcProvider $provider, Claims $claims): Authenticatable&Model;
}
```

Провайдер арендатора должен находить и создавать пользователей **только этого
арендатора**: иначе провайдер, которым управляет один арендатор, мог бы занять
учётку другого по её email. У `DefaultUserProvisioner` есть хук `fill()` для
колонок арендатора.

## RoleCatalog — какие роли может выдать маппинг

```php
interface RoleCatalog
{
    public function roles(OidcProvider $provider): Collection;
}
```

Провайдерам арендатора — только роли арендатора, провайдеру панели — роли
панели.

## События

- `OidcSignedIn` — пользователь, провайдер, как найдена учётка (`existing`,
  `linked`, `created`) и роли, выданные маппингом.
- `OidcSignInRefused` — провайдер (если известен), ключ причины, подробности.
