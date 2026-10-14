<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelAdminOidc\Http;

/**
 * Where the package may send a request. The provider addresses are typed in
 * by an administrator and come back from discovery documents, so a request
 * to them could be pointed at the cloud's metadata or a service inside the
 * network: only https (http by explicit permission), every address the host
 * resolves to is checked, private, loopback and link-local ranges are refused
 * unless whitelisted.
 */
final class UrlPolicy
{
    /**
     * @param  list<string>  $allowedCidrs
     */
    public function __construct(
        private readonly bool $allowHttp = false,
        private readonly bool $blockPrivate = true,
        private readonly array $allowedCidrs = [],
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (bool) config('admin-oidc.http.allow_http', false),
            (bool) config('admin-oidc.http.block_private_networks', true),
            array_values(array_filter((array) config('admin-oidc.http.allowed_cidrs', []), 'is_string')),
        );
    }

    /** @throws UrlNotAllowed */
    public function assertAllowed(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if ($host === '' || ! in_array($scheme, $this->allowHttp ? ['https', 'http'] : ['https'], true)) {
            throw new UrlNotAllowed(sprintf('%s: only %s addresses are allowed', $url, $this->allowHttp ? 'http(s)' : 'https'));
        }
        if (! $this->blockPrivate) {
            return;
        }
        $host = trim($host, '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->resolve($host);
        if ($ips === []) {
            throw new UrlNotAllowed(sprintf('%s: the host does not resolve', $url));
        }
        foreach ($ips as $ip) {
            if ($this->isPrivate($ip) && ! $this->whitelisted($ip)) {
                throw new UrlNotAllowed(sprintf('%s: %s is a private address; add it to admin-oidc.http.allowed_cidrs to allow it', $url, $ip));
            }
        }
    }

    /** @return list<string> */
    private function resolve(string $host): array
    {
        $ips = [];
        foreach ([DNS_A, DNS_AAAA] as $type) {
            foreach (@dns_get_record($host, $type) ?: [] as $record) {
                $ip = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($ip)) {
                    $ips[] = $ip;
                }
            }
        }
        if ($ips === []) {
            $v4 = gethostbynamel($host);
            $ips = $v4 !== false ? $v4 : [];
        }

        return array_values(array_unique($ips));
    }

    private function isPrivate(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    private function whitelisted(string $ip): bool
    {
        foreach ($this->allowedCidrs as $cidr) {
            if (self::inCidr($ip, trim($cidr))) {
                return true;
            }
        }

        return false;
    }

    private static function inCidr(string $ip, string $cidr): bool
    {
        [$net, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton((string) $net);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $bits = $bits === null ? strlen($ipBin) * 8 : (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = chr((0xFF << (8 - $rest)) & 0xFF);

        return (ord($ipBin[$bytes]) & ord($mask)) === (ord($netBin[$bytes]) & ord($mask));
    }
}
