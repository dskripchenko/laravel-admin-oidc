<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelAdminOidc\Auth;

use Dskripchenko\LaravelAdminOidc\Protocol\OidcException;

/**
 * The provider let the person in, the panel does not: no mapped role, an
 * email taken by an account that may not be linked, MFA required but not
 * done, a disabled account. The reason decides the sentence they read.
 */
final class SignInRefused extends OidcException {}
