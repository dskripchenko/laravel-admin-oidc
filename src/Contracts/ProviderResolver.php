<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelAdminOidc\Contracts;

use Dskripchenko\LaravelAdminOidc\Models\OidcProvider;
use Illuminate\Http\Request;

/**
 * Which providers a panel's login page offers.
 *
 * `$hint` is what the person arrived with — `/{panel}/sso/to/{hint}`, kept
 * in the session: a tenant's own sign-in link (`/app/sso/to/acme`). The
 * default resolver ignores it and offers the panel's own providers.
 */
interface ProviderResolver
{
    /** @return list<OidcProvider> enabled providers, in button order */
    public function offered(Request $request, string $panel, ?string $hint): array;

    /** Whether `/{panel}/sso/to/{hint}` names something; unknown hints go straight to the login page. */
    public function knowsHint(string $panel, string $hint): bool;
}
