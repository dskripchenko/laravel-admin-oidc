<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelAdminOidc\Tests\Unit;

use Dskripchenko\LaravelAdminOidc\Protocol\Claims;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClaimsTest extends TestCase
{
    #[Test]
    public function values_are_read_by_dot_path_including_keys_with_dots(): void
    {
        $claims = new Claims([
            'sub' => 's',
            'realm_access' => ['roles' => ['a', 'b', 7]],
            'resource_access' => ['my.app' => ['roles' => ['c']]],
            'groups' => 'single',
        ]);

        $this->assertSame(['a', 'b'], $claims->valuesAt('realm_access.roles'));
        $this->assertSame(['c'], $claims->valuesAt('resource_access.my.app.roles'));
        $this->assertSame(['single'], $claims->valuesAt('groups'));
        $this->assertSame([], $claims->valuesAt('missing.path'));
    }

    #[Test]
    public function the_email_is_lowercased_and_verification_is_strict(): void
    {
        $this->assertSame('alice@x.test', (new Claims(['sub' => 's', 'email' => 'Alice@X.test']))->email());
        $this->assertFalse((new Claims(['sub' => 's', 'email_verified' => 'yes']))->emailVerified());
        $this->assertTrue((new Claims(['sub' => 's', 'email_verified' => true]))->emailVerified());
        $this->assertSame('Ann Lee', (new Claims(['sub' => 's', 'given_name' => 'Ann', 'family_name' => 'Lee']))->name());
    }
}
