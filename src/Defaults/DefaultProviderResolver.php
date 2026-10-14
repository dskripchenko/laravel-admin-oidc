<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelAdminOidc\Defaults;

use Dskripchenko\LaravelAdminOidc\Contracts\ProviderResolver;
use Dskripchenko\LaravelAdminOidc\Models\OidcProvider;
use Illuminate\Http\Request;

/** The panel's own enabled providers; no tenants, hints ignored. */
class DefaultProviderResolver implements ProviderResolver
{
    public function offered(Request $request, string $panel, ?string $hint): array
    {
        return OidcProvider::query()
            ->where('panel', $panel)
            ->whereNull('owner_type')
            ->where('enabled', true)
            ->orderBy('name')
            ->get()
            ->all();
    }

    public function knowsHint(string $panel, string $hint): bool
    {
        return false;
    }
}
