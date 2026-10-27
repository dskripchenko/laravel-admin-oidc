<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelAdminOidc\Events;

use Dskripchenko\LaravelAdminOidc\Models\OidcProvider;
use Dskripchenko\LaravelAdminOidc\Protocol\Claims;
use Illuminate\Database\Eloquent\Model;

/**
 * A person signed in through a provider; `$how` — existing, linked or created.
 * `$claims` — the verified claims of this sign-in, for hosts that keep a
 * profile in step with the provider (name, groups, department).
 */
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
        public ?Claims $claims = null,
    ) {}
}
