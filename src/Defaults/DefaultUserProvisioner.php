<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelAdminOidc\Defaults;

use Dskripchenko\LaravelAdmin\Panel\Panels;
use Dskripchenko\LaravelAdminOidc\Contracts\UserProvisioner;
use Dskripchenko\LaravelAdminOidc\Models\OidcProvider;
use Dskripchenko\LaravelAdminOidc\Protocol\Claims;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Users of the panel's own model, looked up and created by email. */
class DefaultUserProvisioner implements UserProvisioner
{
    public function findByEmail(OidcProvider $provider, string $email): (Authenticatable&Model)|null
    {
        $user = $this->model($provider)->newQuery()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->first();

        return $user instanceof Authenticatable ? $user : null;
    }

    public function create(OidcProvider $provider, Claims $claims): Authenticatable&Model
    {
        $user = $this->model($provider)->newInstance();
        $user->forceFill([
            'name' => $claims->name(),
            'email' => $claims->email() ?? $claims->subject().'@'.parse_url($provider->issuer, PHP_URL_HOST),
            // Nobody knows it: the account signs in through the provider
            // until an administrator sets a password.
            'password' => Hash::make(Str::random(64)),
        ]);
        $this->fill($user, $provider, $claims);
        $user->save();

        return $user instanceof Authenticatable ? $user : throw new \LogicException($user::class.' is not Authenticatable');
    }

    /** A hook for subclasses: tenant columns and the like. */
    protected function fill(Model $user, OidcProvider $provider, Claims $claims): void {}

    protected function model(OidcProvider $provider): Model
    {
        $panel = Panels::registry()->get($provider->panel) ?? throw new \LogicException("Unknown panel {$provider->panel}");
        $class = $panel->authModel();
        $model = new $class;

        return $model instanceof Model ? $model : throw new \LogicException("{$class} is not an Eloquent model");
    }
}
