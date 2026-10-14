<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelAdminOidc\Flow;

use Dskripchenko\LaravelAdmin\Panel\Panel;
use Dskripchenko\LaravelAdminOidc\Auth\SignInRefused;
use Dskripchenko\LaravelAdminOidc\Http\ProviderHttp;
use Dskripchenko\LaravelAdminOidc\Models\OidcProvider;
use Dskripchenko\LaravelAdminOidc\Protocol\Claims;
use Dskripchenko\LaravelAdminOidc\Protocol\Discovery;
use Dskripchenko\LaravelAdminOidc\Protocol\IdTokenVerifier;
use Dskripchenko\LaravelAdminOidc\Protocol\OidcException;
use Dskripchenko\LaravelAdminOidc\Protocol\Pkce;
use Illuminate\Http\Request;

/**
 * The Authorization Code flow with PKCE (S256), `state` and `nonce`.
 *
 * What the callback must trust lives in the session, under the `state` the
 * provider sends back: the provider, the nonce, the PKCE verifier. It is
 * taken once — a replayed callback finds nothing.
 */
final class SignInFlow
{
    private const SESSION = 'admin-oidc.flows';

    /** A person may have several tabs open; older starts are dropped past this. */
    private const KEEP = 5;

    public function __construct(
        private readonly Discovery $discovery,
        private readonly IdTokenVerifier $verifier,
        private readonly ProviderHttp $http,
    ) {}

    /** Where to send the browser to start signing in with the provider. */
    public function start(Request $request, OidcProvider $provider, Panel $panel): string
    {
        $metadata = $this->discovery->for($provider);
        $state = Pkce::random();
        $nonce = Pkce::random();
        $verifier = Pkce::verifier();

        $flows = (array) $request->session()->get(self::SESSION, []);
        $flows[$state] = ['provider' => $provider->id, 'panel' => $panel->id, 'nonce' => $nonce, 'verifier' => $verifier, 'at' => time()];
        $request->session()->put(self::SESSION, array_slice($flows, -self::KEEP, null, true));

        return $metadata->authorizationEndpoint.(str_contains($metadata->authorizationEndpoint, '?') ? '&' : '?').http_build_query([
            'response_type' => 'code',
            'client_id' => $provider->client_id,
            'redirect_uri' => self::redirectUri($panel),
            'scope' => implode(' ', $provider->scopeList()),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => Pkce::challenge($verifier),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * The provider's answer: the code exchanged, the ID token verified, the
     * userinfo read when the roles live there or the token has no email.
     *
     * @return array{0: OidcProvider, 1: Claims, 2: string} the provider, the claims, the raw ID token
     */
    public function finish(Request $request, Panel $panel): array
    {
        $state = (string) $request->query('state', '');
        $flows = (array) $request->session()->get(self::SESSION, []);
        $flow = $state !== '' ? ($flows[$state] ?? null) : null;
        unset($flows[$state]);
        $request->session()->put(self::SESSION, $flows);

        if (! is_array($flow) || ($flow['panel'] ?? null) !== $panel->id
            || time() - (int) ($flow['at'] ?? 0) > max(60, (int) config('admin-oidc.flow_ttl', 600))) {
            throw new SignInRefused('flow-expired', 'No sign-in was started with this state, or it is too old');
        }
        $provider = OidcProvider::query()->whereKey($flow['provider'])->where('enabled', true)->first()
            ?? throw new SignInRefused('flow-expired', 'The provider is gone or switched off');

        if ($request->query('error') !== null) {
            throw new SignInRefused('provider-denied', (string) ($request->query('error_description') ?? $request->query('error')));
        }
        $code = (string) $request->query('code', '');
        if ($code === '') {
            throw new OidcException('provider-error', 'The provider sent no authorization code');
        }

        $metadata = $this->discovery->for($provider);
        $tokens = $this->exchange($provider, $metadata->tokenEndpoint, $metadata->tokenAuthMethods, $code, (string) $flow['verifier'], $panel);
        $idToken = $tokens['id_token'] ?? null;
        if (! is_string($idToken) || $idToken === '') {
            throw new OidcException('provider-error', 'The token response has no id_token — is the "openid" scope allowed for the client?');
        }
        $claims = $this->verifier->verify($idToken, $provider, $metadata, (string) $flow['nonce']);

        $needUserinfo = $provider->roles_source === OidcProvider::ROLES_FROM_USERINFO || ! isset($claims['email']);
        if ($needUserinfo && $metadata->userinfoEndpoint !== null && is_string($tokens['access_token'] ?? null)) {
            $info = $this->http->getJson($metadata->userinfoEndpoint, ['Authorization' => 'Bearer '.$tokens['access_token']]);
            // OIDC Core §5.3.2: a userinfo answer about someone else is discarded.
            if (($info['sub'] ?? null) === $claims['sub']) {
                $claims = [...$info, ...$claims, ...($provider->roles_source === OidcProvider::ROLES_FROM_USERINFO ? $info : [])];
            }
        }

        return [$provider, new Claims($claims), $idToken];
    }

    public static function redirectUri(Panel $panel): string
    {
        return url(trim($panel->path.'/'.self::prefix().'/callback', '/'));
    }

    public static function prefix(): string
    {
        return trim((string) config('admin-oidc.route_prefix', 'sso'), '/');
    }

    /**
     * @param  list<string>  $authMethods
     * @return array<string, mixed>
     */
    private function exchange(OidcProvider $provider, string $endpoint, array $authMethods, string $code, string $verifier, Panel $panel): array
    {
        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::redirectUri($panel),
            'code_verifier' => $verifier,
        ];
        $headers = [];
        $secret = (string) ($provider->client_secret ?? '');
        if ($secret !== '' && (in_array('client_secret_basic', $authMethods, true) || ! in_array('client_secret_post', $authMethods, true))) {
            // RFC 6749 §2.3.1: both parts form-encoded before base64.
            $headers['Authorization'] = 'Basic '.base64_encode(rawurlencode($provider->client_id).':'.rawurlencode($secret));
        } else {
            $form['client_id'] = $provider->client_id;
            if ($secret !== '') {
                $form['client_secret'] = $secret;
            }
        }

        return $this->http->postForm($endpoint, $form, $headers);
    }
}
