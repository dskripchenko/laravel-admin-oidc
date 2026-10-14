# dskripchenko/laravel-admin-oidc

> 🌐 [English](../../README.md) · [Русский](../ru/README.md) · [Deutsch](../de/README.md) · **中文**

通过 OpenID Connect 登录 [`dskripchenko/laravel-admin`](https://github.com/dskripchenko/laravel-admin) 面板——Keycloak、Microsoft Entra ID、ADFS、Authentik、Okta 或任何 OIDC 提供方。纯 OIDC，没有针对特定产品的代码：提供方之间的差异只是配置。

- **登录页上的"通过……登录"按钮**，位于密码表单下方，密码表单保留。
- **首次登录时自动创建账户**；只有当提供方确认了邮箱时，才会接管使用相同邮箱的已有账户。
- **角色来自提供方**：声明值（`realm_access.roles`、`groups` 等）映射为面板角色，并在每次登录时替换。没有映射到任何角色——不允许登录。
- **多个面板、按租户配置提供方**——通过专属链接（`/{panel}/sso/to/{租户}`）以及宿主实现的三个小契约。
- **按 OIDC Core 校验**：Authorization Code + PKCE（S256）、`state` 和 `nonce`，ID 令牌依据提供方的 JWKS 验证（仅非对称算法，支持密钥轮换）、issuer、受众、有效期。对提供方的请求经过地址策略（仅 https，禁止私有网络，白名单除外）。
- 可选的 **MFA 要求**，依据 `amr` 声明。

## 安装

```bash
composer require dskripchenko/laravel-admin-oidc
php artisan migrate
```

需要 laravel-admin 1.50+。提供方在面板中管理：**系统 → SSO 提供方**。

## 文档

- [快速开始](getting-started.md)——安装、添加提供方、Keycloak 分步配置、角色映射
- [租户与契约](tenants.md)——按租户的提供方、自定义账户创建与角色目录
- [安全](security.md)——校验内容、网络策略、MFA

## 许可证

MIT。
