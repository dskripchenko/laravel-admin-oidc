<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelAdminOidc\Auth;

use Dskripchenko\LaravelAdmin\Audit\AuditLog;
use Dskripchenko\LaravelAdmin\Auth\AccountState;
use Dskripchenko\LaravelAdmin\Auth\PanelAccess;
use Dskripchenko\LaravelAdmin\Panel\Panels;
use Dskripchenko\LaravelAdmin\Support\TableColumns;
use Dskripchenko\LaravelAdminOidc\Contracts\RoleCatalog;
use Dskripchenko\LaravelAdminOidc\Contracts\UserProvisioner;
use Dskripchenko\LaravelAdminOidc\Events\OidcSignedIn;
use Dskripchenko\LaravelAdminOidc\Models\OidcIdentity;
use Dskripchenko\LaravelAdminOidc\Models\OidcProvider;
use Dskripchenko\LaravelAdminOidc\Protocol\Claims;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * From verified claims to a signed-in panel user.
 *
 * 1. MFA, when the provider is set to require it.
 * 2. The roles: the claim values through the provider's mapping, kept to the
 *    roles its catalog allows. None — no way in, no account created.
 * 3. The account: linked by (provider, `sub`); on a first sign-in an account
 *    with the same email is taken over only when the provider verified the
 *    email and the provider allows it — otherwise refused; no account at all
 *    — a new one.
 * 4. The core's checks (a disabled account, panel access), the roles
 *    replaced by the mapping, the guard's login, a fresh session.
 */
final class Authenticator
{
    public function __construct(
        private readonly UserProvisioner $provisioner,
        private readonly RoleCatalog $catalog,
    ) {}

    /**
     * @return array{0: Authenticatable&Model, 1: string} the user and how it was found
     */
    public function signIn(Request $request, OidcProvider $provider, Claims $claims, string $idToken): array
    {
        if ($provider->require_mfa && array_intersect($claims->amr(), (array) config('admin-oidc.mfa_amr_values', [])) === []) {
            throw new SignInRefused('mfa-required', 'The provider reported no second factor (amr: '.implode(',', $claims->amr()).')');
        }

        $roles = $this->roles($provider, $claims);
        if ($roles->isEmpty()) {
            throw new SignInRefused('no-role', sprintf('No role maps from %s = [%s]', $provider->roles_claim, implode(', ', $claims->valuesAt($provider->roles_claim))));
        }

        $panel = Panels::registry()->get($provider->panel) ?? throw new SignInRefused('panel-forbidden', "Unknown panel {$provider->panel}");
        Panels::registry()->setCurrent($panel->id);

        [$user, $how] = DB::transaction(fn (): array => $this->account($provider, $claims));

        if (AccountState::isDisabled($user)) {
            throw new SignInRefused('account-disabled', 'The account is disabled');
        }
        if (! PanelAccess::allows($user, $panel)) {
            throw new SignInRefused('panel-forbidden', 'The panel refuses the account');
        }

        $this->syncRoles($user, $roles);

        Auth::guard($panel->guard)->login($user);
        if (TableColumns::has($user, 'last_login_at')) {
            $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        }
        $request->session()->regenerate();
        // For a sign-out at the provider later (RP-initiated logout).
        $request->session()->put('admin-oidc.session', ['provider' => $provider->id, 'id_token' => $idToken]);

        $slugs = $roles->pluck('slug')->all();
        $this->audit($user, 'oidc.sign_in', ['provider' => $provider->id, 'how' => $how, 'roles' => $slugs]);
        event(new OidcSignedIn($user, $provider, $how, $slugs));

        return [$user, $how];
    }

    /**
     * @return \Illuminate\Support\Collection<int, \Dskripchenko\LaravelAdmin\Permission\Models\Role>
     */
    private function roles(OidcProvider $provider, Claims $claims): \Illuminate\Support\Collection
    {
        $slugs = $provider->mapRoles($claims->valuesAt($provider->roles_claim));

        return $this->catalog->roles($provider)->filter(static fn ($role): bool => in_array($role->slug, $slugs, true))->values();
    }

    /**
     * @return array{0: Authenticatable&Model, 1: string}
     */
    private function account(OidcProvider $provider, Claims $claims): array
    {
        $identity = OidcIdentity::query()->where('provider_id', $provider->id)->where('subject', $claims->subject())->lockForUpdate()->first();
        if ($identity !== null) {
            $user = $identity->user;
            if ($user instanceof Authenticatable) {
                $identity->update(['email' => $claims->email(), 'last_login_at' => now()]);

                return [$user, OidcSignedIn::EXISTING];
            }
            // The account was deleted: the person starts over.
            $identity->delete();
        }

        $how = OidcSignedIn::CREATED;
        $email = $claims->email();
        $user = $email !== null ? $this->provisioner->findByEmail($provider, $email) : null;
        if ($user !== null) {
            if (! $provider->link_by_email || ! $claims->emailVerified()) {
                throw new SignInRefused('email-taken', sprintf('%s belongs to an account the provider may not take over (email_verified: %s, link_by_email: %s)',
                    $email, $claims->emailVerified() ? 'yes' : 'no', $provider->link_by_email ? 'yes' : 'no'));
            }
            if (OidcIdentity::query()->where('provider_id', $provider->id)->where('user_type', $user->getMorphClass())->where('user_id', $user->getKey())->exists()) {
                throw new SignInRefused('email-taken', sprintf('%s is already linked to another subject of this provider', $email));
            }
            $how = OidcSignedIn::LINKED;
        } else {
            $user = $this->provisioner->create($provider, $claims);
        }

        OidcIdentity::query()->create([
            'provider_id' => $provider->id,
            'subject' => $claims->subject(),
            'user_type' => $user->getMorphClass(),
            'user_id' => $user->getKey(),
            'email' => $email,
            'last_login_at' => now(),
        ]);

        return [$user, $how];
    }

    /**
     * The roles come from the provider: what the mapping gave replaces what
     * the account had.
     *
     * @param  \Illuminate\Support\Collection<int, \Dskripchenko\LaravelAdmin\Permission\Models\Role>  $roles
     */
    private function syncRoles(Model $user, \Illuminate\Support\Collection $roles): void
    {
        if (! method_exists($user, 'roles')) {
            return;
        }
        $user->roles()->sync($roles->pluck('id')->all());
        $user->unsetRelation('roles');
    }

    /** @param array<string, mixed> $payload */
    private function audit(Model $user, string $event, array $payload): void
    {
        if (! (bool) config('admin.audit.log_auth_events', true) || ! class_exists(AuditLog::class)) {
            return;
        }
        try {
            AuditLog::create([
                'actor_type' => $user->getMorphClass(),
                'actor_id' => $user->getKey(),
                'subject_type' => $user->getMorphClass(),
                'subject_id' => $user->getKey(),
                'event' => $event,
                'changes' => ['payload' => $payload],
                // The core's context: lengths cut as configured, and the
                // callback's code and state masked — the audit log outlives them.
                ...AuditLog::requestContext(),
            ]);
        } catch (\Throwable $e) {
            // A missing audit table must not stop a sign-in.
            report($e);
        }
    }
}
