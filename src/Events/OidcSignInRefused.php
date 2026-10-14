<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelAdminOidc\Events;

use Dskripchenko\LaravelAdminOidc\Models\OidcProvider;

/** A sign-in through a provider stopped; `$reason` is the stable key. */
final readonly class OidcSignInRefused
{
    public function __construct(
        public ?OidcProvider $provider,
        public string $reason,
        public string $detail,
        public ?string $subject = null,
    ) {}
}
