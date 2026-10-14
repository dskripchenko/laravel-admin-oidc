<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelAdminOidc\Contracts;

use Dskripchenko\LaravelAdminOidc\Models\OidcProvider;
use Dskripchenko\LaravelAdminOidc\Protocol\Claims;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * The panel users a provider's sign-in may reach: an existing account with
 * the same verified email, or a new one. A tenant's provider must find and
 * create users of that tenant only.
 */
interface UserProvisioner
{
    /** A local account of this provider's scope with the email, or null. */
    public function findByEmail(OidcProvider $provider, string $email): (Authenticatable&Model)|null;

    /** A new account for the person; it has no usable password. */
    public function create(OidcProvider $provider, Claims $claims): Authenticatable&Model;
}
