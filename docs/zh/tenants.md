# 租户与契约

带租户的宿主——每个客户公司都有自己身份提供方的 SaaS——在 `config/admin-oidc.php` 中绑定三个契约。

## ProviderResolver——登录页提供哪些提供方

```php
interface ProviderResolver
{
    public function offered(Request $request, string $panel, ?string $hint): array;
    public function knowsHint(string $panel, string $hint): bool;
}
```

租户的提供方有所有者（`owner_type`/`owner_id`）。为租户提供专属链接 `/{panel}/sso/to/{hint}`：当 `knowsHint()` 确认该提示时，扩展包会把它保存在会话中，把用户带到登录页，而 `offered()` 会收到它——请返回该租户已启用的提供方。

## UserProvisioner——提供方可以触及谁的账户

```php
interface UserProvisioner
{
    public function findByEmail(OidcProvider $provider, string $email): (Authenticatable&Model)|null;
    public function create(OidcProvider $provider, Claims $claims): Authenticatable&Model;
}
```

租户的提供方只能查找和创建**该租户的**用户：否则一个租户控制的提供方可能通过邮箱接管另一个租户的账户。`DefaultUserProvisioner` 提供 `fill()` 钩子用于填写租户字段。

## RoleCatalog——映射可以授予哪些角色

```php
interface RoleCatalog
{
    public function roles(OidcProvider $provider): Collection;
}
```

租户的提供方只授予该租户的角色，面板的提供方只授予面板的角色。

## 事件

- `OidcSignedIn`——用户、提供方、账户的获得方式（`existing`、`linked`、`created`）以及映射授予的角色。
- `OidcSignInRefused`——提供方（若已知）、原因键、详情。
