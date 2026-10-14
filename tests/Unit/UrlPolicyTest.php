<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelAdminOidc\Tests\Unit;

use Dskripchenko\LaravelAdminOidc\Http\UrlNotAllowed;
use Dskripchenko\LaravelAdminOidc\Http\UrlPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UrlPolicyTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function refused(): iterable
    {
        yield 'plain http' => ['http://8.8.8.8/realms/x'];
        yield 'cloud metadata' => ['https://169.254.169.254/latest/meta-data'];
        yield 'loopback' => ['https://127.0.0.1/realms/x'];
        yield 'private network' => ['https://10.1.2.3/realms/x'];
        yield 'ipv6 loopback' => ['https://[::1]/realms/x'];
        yield 'no host' => ['https:///x'];
        yield 'another scheme' => ['file:///etc/passwd'];
    }

    #[Test]
    #[DataProvider('refused')]
    public function it_refuses(string $url): void
    {
        $this->expectException(UrlNotAllowed::class);
        (new UrlPolicy)->assertAllowed($url);
    }

    #[Test]
    public function it_allows_a_public_https_address_and_a_whitelisted_private_one(): void
    {
        (new UrlPolicy)->assertAllowed('https://8.8.8.8/realms/x');
        (new UrlPolicy(allowedCidrs: ['10.1.0.0/16']))->assertAllowed('https://10.1.2.3/realms/x');
        (new UrlPolicy(allowHttp: true, allowedCidrs: ['127.0.0.1/32']))->assertAllowed('http://127.0.0.1:8080/realms/x');
        $this->addToAssertionCount(3);
    }
}
