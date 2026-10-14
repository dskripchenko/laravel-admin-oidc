<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelAdminOidc\Events;

use Dskripchenko\LaravelAdminOidc\Models\OidcProvider;
use Illuminate\Database\Eloquent\Model;

/** A person signed in through a provider; `$how` — existing, linked or created. */
final readonly class OidcSignedIn
{
    public const EXISTING = 'existing';

    public const LINKED = 'linked';

    public const CREATED = 'created';

    /** @param list<string> $roles the role slugs the mapping gave */
    public function __construct(
        public Model $user,
        public OidcProvider $provider,
        public string $how,
        public array $roles,
    ) {}
}
