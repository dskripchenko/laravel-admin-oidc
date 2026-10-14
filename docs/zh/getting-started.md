# 快速开始

## 1. 安装

```bash
composer require dskripchenko/laravel-admin-oidc
php artisan migrate
```

插件通过 package discovery 自动注册，并添加：

- 每个受服务面板下的路由：`/{panel}/sso/redirect/{provider}`、`/{panel}/sso/callback`、`/{panel}/sso/to/{hint}`；
- 该面板登录页上的"通过……登录"按钮；
- 资源 **系统 → SSO 提供方** 及其权限 `admin.oidc.providers`。

服务哪些面板由 `admin-oidc.panels` 决定（默认 `['admin']`）。若要服务第二个面板（例如 `admin.panels.client`）：

```php
// config/admin-oidc.php
'panels' => ['admin', 'client'],
```

## 2. 添加提供方

**系统 → SSO 提供方 → 创建**：

| 字段 | 填写内容 |
|---|---|
| 名称 | 按钮显示为"通过 *名称* 登录"。 |
| 面板 | 通过该提供方登录的面板。 |
| Issuer | 提供方发布 `/.well-known/openid-configuration` 的地址。Keycloak：`https://keycloak.example.com/realms/<realm>`。 |
| Client ID / 密钥 | 在提供方处创建的客户端（见下文）。密钥加密存储；编辑时留空表示保留原值。 |
| Scopes | 默认 `openid email profile`。 |
| 令牌中角色的位置 | 指向声明的点路径：Keycloak 为 `realm_access.roles` 或 `resource_access.<client>.roles`，Entra ID 为 `roles`，Authentik 为 `groups`。 |
| 角色来源 | ID 令牌或 userinfo。 |
| 角色映射 | 声明值 → 面板角色。登录至少需要一个匹配。 |
| 要求 MFA | 当 `amr` 声明中没有第二因素时拒绝。 |
| 按邮箱关联 | 首次登录可以接管使用相同且经提供方确认的邮箱的账户。 |

在提供方所在行点击 **检查**：会读取 discovery 文档和签名密钥，在任何人尝试登录前发现配置错误。

*Issuer* 字段的提示中列出了各面板的 **重定向 URI**——`https://your-app/admin/sso/callback`——用于在提供方配置客户端。

## 3. Keycloak 分步配置

1. **Clients → Create client**：类型 *OpenID Connect*，客户端 ID（`panel`），开启 *Client authentication*（机密客户端），开启 *Standard flow*，关闭 *Direct access grants*。
2. **Valid redirect URIs**：面板的回调地址 `https://your-app/admin/sso/callback`；**Valid post logout redirect URIs**：面板地址 `https://your-app/admin`。
3. **Advanced → Proof Key for Code Exchange**：`S256`。
4. **Credentials**：将客户端密钥复制到提供方表单。
5. **ID 令牌中的角色。** Keycloak 默认只把 realm 角色放进访问令牌。可以把 *角色来源* 设为 *userinfo*，或者添加映射器：**Client scopes → `<client>-dedicated` → Add mapper → By configuration → User Realm Role**，声明名 `realm_access.roles`，开启 *Add to ID token*。客户端角色请使用 *User Client Role* 和路径 `resource_access.<client>.roles`。
6. **MFA**（可选）：若希望面板要求 MFA，请在客户端的专属 scope 中添加 *Authentication Method Reference (AMR)* 映射器，并在提供方表单中开启 *要求 MFA*。
7. 创建 realm 角色（`panel-admins`、`panel-viewers`），分配给用户，并在映射中对应。

## 4. 角色映射

配置的声明路径上的值会与映射比对；匹配到的面板角色会在**每次登录时替换**账户的角色——在提供方被撤销的角色，下次登录时在这里也会被撤销。没有任何匹配时，用户会看到"无访问权限：请联系管理员"，且不会创建账户。

## 5. 网络

对提供方的请求经过地址策略：

```php
'http' => [
    'allow_http' => false,               // 仅用于测试环境
    'block_private_networks' => true,
    'allowed_cidrs' => ['10.20.0.0/16'], // 内网中的 Keycloak
],
```

## 6. 故障排查

登录被拒的原因记录在日志（`admin-oidc: sign-in stopped`）和事件 `OidcSignInRefused` 中，带有稳定的键：

| 原因 | 通常是 |
|---|---|
| `no-role` | 映射中没有令牌携带的值；或 Keycloak 没有把角色放进 ID 令牌（第 5 步）。 |
| `email-taken` | 本地账户已使用该邮箱，而提供方未确认邮箱或关联被关闭。 |
| `mfa-required` | 提供方未发送包含第二因素的 `amr`（第 6 步）。 |
| `token-invalid` | issuer 或客户端 ID 错误、时钟偏差过大、使用了其他 realm 的密钥。 |
| `provider-unreachable` | issuer 错误、网络策略拒绝该地址、提供方不可用。 |
| `flow-expired` | 登录耗时超过 `flow_ttl`，或回调被重放。 |
