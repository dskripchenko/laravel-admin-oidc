<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelAdminOidc\Defaults;

use Dskripchenko\LaravelAdmin\Permission\Models\Role;
use Dskripchenko\LaravelAdminOidc\Contracts\RoleCatalog;
use Dskripchenko\LaravelAdminOidc\Models\OidcProvider;
use Illuminate\Support\Collection;

/** Every role. A host that keeps roles of several kinds narrows it. */
class DefaultRoleCatalog implements RoleCatalog
{
    public function roles(OidcProvider $provider): Collection
    {
        return Role::query()->orderBy('name')->get();
    }
}
