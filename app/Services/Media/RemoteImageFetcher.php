<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Downloads an image from another server over HTTP(S). Guards against the
 * obvious server-side-request-forgery routes (non-http schemes, hosts that
 * resolve to private/reserved addresses), non-image responses and oversized
 * bodies, since the URLs come from supplier spreadsheets.
 */
class RemoteImageFetcher
{
    public const MAX_BYTES = 15 * 1024 * 1024;

    public function fetch(string $url): string
    {
        $this->assertSafeUrl($url);

        $response = Http::timeout(30)
            ->connectTimeout(10)
            ->withUserAgent('Mozilla/5.0 (compatible; TiroMediaFetcher/1.0)')
            ->accept('image/*')
            ->withOptions(['allow_redirects' => ['max' => 3, 'protocols' => ['http', 'https']]])
            ->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("The server answered HTTP {$response->status()}.");
        }

        $body = $response->body();

        if ($body === '' || strlen($body) > self::MAX_BYTES) {
            throw new RuntimeException('The download was empty or larger than 15 MB.');
        }

        if (@getimagesizefromstring($body) === false) {
            throw new RuntimeException('The URL did not return an image.');
        }

        return $body;
    }

    private function assertSafeUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new RuntimeException('Only http(s) image URLs can be downloaded.');
        }

        if (! config('media.block_private_hosts', true)) {
            return;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        if ($ips === []) {
            throw new RuntimeException("The host {$host} could not be resolved.");
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new RuntimeException('The URL points at a private or reserved address.');
            }
        }
    }
}
