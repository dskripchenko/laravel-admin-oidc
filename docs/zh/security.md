# 安全

**流程。** 使用 PKCE（S256）的 Authorization Code。`state`、`nonce` 和 PKCE verifier 以 `state` 为键保存在会话中，只能取用一次：重放的回调找不到任何内容。已开始的登录在 `flow_ttl`（600 秒）后过期。

**ID 令牌**（OIDC Core §3.1.3.7）：

- 依据提供方的 JWKS，以 RS256/384/512、PS256、ES256/384 或 EdDSA 验证签名——绝不接受 `none`，也绝不接受 HMAC；遇到未知的密钥 ID 时重新读取一次 JWKS（密钥轮换）；
- `iss` 等于配置的 issuer，discovery 文档必须精确地声明该 issuer；
- `aud` 包含客户端 ID，存在多个受众时 `azp` 必须为该客户端 ID；
- `exp` 必须存在且未过期，遵守 `iat`/`nbf`，容差为 `admin-oidc.leeway`（60 秒）；
- `nonce` 与本次登录一致；`sub` 必须存在。

**账户。** 通过（提供方，`sub`）关联，绝不只凭邮箱。只有当提供方返回 `email_verified: true` 且允许关联时，首次登录才会接管使用相同邮箱的账户。新账户使用无人知晓的随机密码。

**角色。** 每次登录时由映射替换；只授予 `RoleCatalog` 允许的角色；没有映射到任何角色——不允许登录。

**MFA。** laravel-admin 的本地 TOTP 属于密码表单；SSO 登录把 MFA 交给提供方。开启 *要求 MFA* 时，若 `amr` 没有声明第二因素（`admin-oidc.mfa_amr_values`），登录会被拒绝。

**网络。** 对提供方的每个请求：仅 https（http 需开启 `allow_http`），检查主机解析出的每个地址，禁止私有、回环和链路本地地址段（`allowed_cidrs` 中的除外），不跟随重定向，并设置超时和响应大小上限。

**密钥。** 客户端密钥使用 Laravel 的加密器存储，永不序列化；ID 令牌保存在会话中，供在提供方处退出时使用。

**退出。** 通过 SSO 登录后退出面板时，浏览器会被送往提供方的 `end_session_endpoint`，`id_token_hint` 为 ID 令牌，`post_logout_redirect_uri` 为面板登录页——下一次“通过 … 登录”时会重新确认身份。可在提供方设置中通过 *同时在提供方处退出* 关闭。不支持 back-channel logout：在提供方处结束的会话会在面板中保留，直到在面板中退出或会话过期。


## 用于自有 API 的访问令牌

宿主应用也可以是资源服务器：接收提供方 JWT 访问令牌（RFC 9068）的 API 或 MCP 端点。`AccessTokenVerifier` 以 `IdTokenVerifier` 校验 ID 令牌的方式校验访问令牌——由提供方密钥以非对称算法签名（不接受 `none` 和 HMAC）、由配置的签发方签发、未过期、带有主体——并要求 `aud` 指向你的资源。被当作访问令牌提交的 ID 令牌（`typ: ID`）会被拒绝。

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

请通过 `OidcIdentity`（提供方与主体）把主体映射到用户，而不是通过电子邮件。错误为 `OidcException`，原因写在消息中。
