<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelAdminOidc\Protocol;

/**
 * The verified claims of a sign-in, with the reads the package needs: the
 * values at a dot path (`realm_access.roles`, `resource_access.app.roles`),
 * the email when the provider vouches for it, the name.
 */
final readonly class Claims
{
    /** @param array<string, mixed> $claims */
    public function __construct(public array $claims) {}

    public function subject(): string
    {
        return (string) $this->claims['sub'];
    }

    public function email(): ?string
    {
        $email = $this->claims['email'] ?? null;

        return is_string($email) && $email !== '' ? mb_strtolower($email) : null;
    }

    public function emailVerified(): bool
    {
        $v = $this->claims['email_verified'] ?? false;

        return $v === true || $v === 'true' || $v === 1;
    }

    public function name(): string
    {
        foreach (['name', 'preferred_username'] as $key) {
            if (is_string($this->claims[$key] ?? null) && trim($this->claims[$key]) !== '') {
                return trim($this->claims[$key]);
            }
        }
        $full = trim(((string) ($this->claims['given_name'] ?? '')).' '.((string) ($this->claims['family_name'] ?? '')));

        return $full !== '' ? $full : ($this->email() ?? $this->subject());
    }

    /**
     * The string values at a dot path; a single string counts as one value.
     * Keys with dots of their own (a Keycloak client named "my.app") are
     * reached by trying the longest key first.
     *
     * @return list<string>
     */
    public function valuesAt(string $path): array
    {
        $value = self::dig($this->claims, $path === '' ? [] : explode('.', $path));
        if (is_string($value)) {
            return [$value];
        }
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    /** @return list<string> */
    public function amr(): array
    {
        return $this->valuesAt('amr');
    }

    /** @param list<string> $segments */
    private static function dig(mixed $node, array $segments): mixed
    {
        if ($segments === []) {
            return $node;
        }
        if (! is_array($node)) {
            return null;
        }
        for ($take = count($segments); $take >= 1; $take--) {
            $key = implode('.', array_slice($segments, 0, $take));
            if (array_key_exists($key, $node)) {
                return self::dig($node[$key], array_slice($segments, $take));
            }
        }

        return null;
    }
}
