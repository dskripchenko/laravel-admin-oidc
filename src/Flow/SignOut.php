<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelAdminOidc\Flow;

use Dskripchenko\LaravelAdmin\Panel\Panel;
use Dskripchenko\LaravelAdminOidc\Http\Controllers\SsoController;
use Dskripchenko\LaravelAdminOidc\Models\OidcProvider;
use Dskripchenko\LaravelAdminOidc\Protocol\Discovery;
use Illuminate\Http\Request;

/**
 * Signing out at the provider (RP-initiated logout): where the browser goes
 * after leaving the panel, so that the provider's session ends too and the
 * next "Sign in with …" asks who it is again.
 *
 * The sign-in keeps the provider and the ID token in the session; the
 * sign-out sends that token back as `id_token_hint` and asks to return to the
 * panel's login page. Nothing to do — signed in with a password, the provider
 * gone, switched off for it, or announcing no `end_session_endpoint` — null,
 * and the panel opens its own login page as before.
 */
final class SignOut
{
    /** Where the sign-in keeps what the sign-out needs. */
    public const SESSION = 'admin-oidc.session';

    public function __construct(private readonly Discovery $discovery) {}

    public function redirectFor(Request $request, Panel $panel): ?string
    {
        $kept = $request->hasSession() ? $request->session()->get(self::SESSION) : null;
        if (! is_array($kept) || ! is_string($kept['id_token'] ?? null)) {
            return null;
        }
        $provider = OidcProvider::query()->whereKey($kept['provider'] ?? null)->where('panel', $panel->id)->first();
        if ($provider === null || ! $provider->sign_out_at_provider) {
            return null;
        }
        $endpoint = $this->discovery->for($provider)->endSessionEndpoint;
        if ($endpoint === null) {
            return null;
        }

        return $endpoint.(str_contains($endpoint, '?') ? '&' : '?').http_build_query([
            'id_token_hint' => $kept['id_token'],
            'post_logout_redirect_uri' => SsoController::loginUrl($panel),
            'client_id' => $provider->client_id,
        ]);
    }
}
