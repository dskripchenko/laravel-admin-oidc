<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelAdminOidc\Contracts;

use Dskripchenko\LaravelAdmin\Permission\Models\Role;
use Dskripchenko\LaravelAdminOidc\Models\OidcProvider;
use Illuminate\Support\Collection;

/** The roles a provider's mapping may hand out — the panel's, a tenant's. */
interface RoleCatalog
{
    /** @return Collection<int, Role> */
    public function roles(OidcProvider $provider): Collection;
}
