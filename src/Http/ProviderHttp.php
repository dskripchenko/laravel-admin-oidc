<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelAdminOidc\Http;

use Dskripchenko\LaravelAdminOidc\Protocol\OidcException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Requests to a provider: the address policy first, no redirects (a 302 to
 * an internal address would get around it), a timeout, a size cap, JSON in.
 */
final class ProviderHttp
{
    public function __construct(private readonly UrlPolicy $policy) {}

    public static function make(): self
    {
        return new self(UrlPolicy::fromConfig());
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    public function getJson(string $url, array $headers = []): array
    {
        return $this->send('GET', $url, [], $headers);
    }

    /**
     * @param  array<string, string>  $form
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    public function postForm(string $url, array $form, array $headers = []): array
    {
        return $this->send('POST', $url, $form, $headers);
    }

    /**
     * @param  array<string, string>  $form
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function send(string $method, string $url, array $form, array $headers): array
    {
        try {
            $this->policy->assertAllowed($url);
        } catch (UrlNotAllowed $e) {
            throw new OidcException('provider-unreachable', $e->getMessage(), $e);
        }
        $request = $this->request()->withHeaders(['Accept' => 'application/json', ...$headers]);
        try {
            $response = $method === 'POST' ? $request->asForm()->post($url, $form) : $request->get($url);
        } catch (ConnectionException $e) {
            throw new OidcException('provider-unreachable', sprintf('%s: %s', $url, $e->getMessage()), $e);
        }
        $body = $response->body();
        $max = max(1, (int) config('admin-oidc.http.max_response_kb', 512)) * 1024;
        if (strlen($body) > $max) {
            throw new OidcException('provider-unreachable', sprintf('%s: the answer is larger than %d KB', $url, intdiv($max, 1024)));
        }
        $json = json_decode($body, true);
        if (! $response->successful()) {
            $reason = is_array($json) ? (string) ($json['error_description'] ?? $json['error'] ?? '') : '';
            throw new OidcException('provider-error', sprintf('%s answered %d%s', $url, $response->status(), $reason !== '' ? ': '.$reason : ''));
        }
        if (! is_array($json)) {
            throw new OidcException('provider-error', sprintf('%s: the answer is not JSON', $url));
        }

        return $json;
    }

    private function request(): PendingRequest
    {
        return Http::timeout(max(1, (int) config('admin-oidc.http.timeout', 10)))
            ->withOptions(['allow_redirects' => false]);
    }
}
