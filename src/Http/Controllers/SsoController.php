<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelAdminOidc\Http\Controllers;

use Dskripchenko\LaravelAdmin\Auth\LoginNotice;
use Dskripchenko\LaravelAdmin\Panel\Panel;
use Dskripchenko\LaravelAdmin\Panel\Panels;
use Dskripchenko\LaravelAdminOidc\Auth\Authenticator;
use Dskripchenko\LaravelAdminOidc\Auth\SignInMessages;
use Dskripchenko\LaravelAdminOidc\Contracts\ProviderResolver;
use Dskripchenko\LaravelAdminOidc\Events\OidcSignInRefused;
use Dskripchenko\LaravelAdminOidc\Flow\SignInFlow;
use Dskripchenko\LaravelAdminOidc\Models\OidcProvider;
use Dskripchenko\LaravelAdminOidc\Protocol\OidcException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

/**
 * The three addresses of a panel's sign-in:
 *
 *   /{panel}/sso/redirect/{provider} — off to the provider;
 *   /{panel}/sso/callback            — back, signed in or refused with a
 *                                      reason on the login page;
 *   /{panel}/sso/to/{hint}           — a tenant's own link: remembered in the
 *                                      session, the login page then offers
 *                                      that tenant's provider.
 */
final class SsoController extends Controller
{
    public const HINT_SESSION = 'admin-oidc.hint';

    public function __construct(
        private readonly SignInFlow $flow,
        private readonly Authenticator $authenticator,
        private readonly ProviderResolver $resolver,
    ) {}

    public function redirect(Request $request, string $provider): RedirectResponse
    {
        $panel = $this->panel($request);
        $model = OidcProvider::query()->whereKey($provider)->where('panel', $panel->id)->where('enabled', true)->first();
        if ($model === null) {
            return $this->refuse($panel, null, new OidcException('flow-expired', "No enabled provider {$provider} for panel {$panel->id}"));
        }
        try {
            return redirect()->away($this->flow->start($request, $model, $panel));
        } catch (OidcException $e) {
            return $this->refuse($panel, $model, $e);
        }
    }

    public function callback(Request $request): RedirectResponse
    {
        $panel = $this->panel($request);
        $provider = null;
        try {
            [$provider, $claims, $idToken] = $this->flow->finish($request, $panel);
            $this->authenticator->signIn($request, $provider, $claims, $idToken);
        } catch (OidcException $e) {
            return $this->refuse($panel, $provider, $e, isset($claims) ? $claims->subject() : null);
        }

        return redirect()->to(url($panel->path === '' ? '/' : $panel->path));
    }

    public function hint(Request $request, string $hint): RedirectResponse
    {
        $panel = $this->panel($request);
        $hints = (array) $request->session()->get(self::HINT_SESSION, []);
        if ($this->resolver->knowsHint($panel->id, $hint)) {
            $hints[$panel->id] = $hint;
        } else {
            unset($hints[$panel->id]);
        }
        $request->session()->put(self::HINT_SESSION, $hints);

        return redirect()->to(self::loginUrl($panel));
    }

    public static function loginUrl(Panel $panel): string
    {
        return url(trim($panel->path.'/login', '/'));
    }

    private function refuse(Panel $panel, ?OidcProvider $provider, OidcException $e, ?string $subject = null): RedirectResponse
    {
        Log::warning('admin-oidc: sign-in stopped', [
            'panel' => $panel->id, 'provider' => $provider?->id, 'reason' => $e->reason, 'detail' => $e->getMessage(),
        ]);
        event(new OidcSignInRefused($provider, $e->reason, $e->getMessage(), $subject));
        $detail = $e->reason === 'provider-denied' ? $e->getMessage() : '';
        LoginNotice::put(SignInMessages::for($e->reason, $detail), in_array($e->reason, ['flow-expired'], true) ? 'warning' : 'danger');

        return redirect()->to(self::loginUrl($panel));
    }

    private function panel(Request $request): Panel
    {
        $id = (string) $request->route()?->defaults['adminPanel'];

        return Panels::registry()->get($id) ?? abort(404);
    }
}
