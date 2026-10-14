<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelAdminOidc\Protocol;

/** Proof Key for Code Exchange, S256 (RFC 7636). */
final class Pkce
{
    public static function verifier(): string
    {
        return self::b64(random_bytes(32));
    }

    public static function challenge(string $verifier): string
    {
        return self::b64(hash('sha256', $verifier, true));
    }

    public static function random(): string
    {
        return self::b64(random_bytes(24));
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
