<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Http;

use Flenczewski\IabTcf\Exception\GvlException;
use Flenczewski\IabTcf\Exception\InvalidArgumentException;

/**
 * Zero-dependency default client built on PHP's HTTP stream wrapper.
 *
 * Unlike a bare file_get_contents() call it sets a timeout, does not follow
 * redirects, verifies TLS, checks the response status and converts warnings
 * into exceptions.
 */
final class StreamHttpClient implements HttpClient
{
    /**
     * The real Global Vendor List is a few megabytes, so this leaves ample
     * headroom while still bounding what a hostile or misconfigured endpoint
     * can make the process allocate. Everything else in this package caps its
     * allocations from untrusted input; a network read should not be the gap.
     */
    public const DEFAULT_MAX_RESPONSE_BYTES = 64 * 1024 * 1024;

    /** @param positive-int $maxResponseBytes */
    public function __construct(
        private readonly float $timeoutSeconds = 10.0,
        private readonly string $userAgent = 'php-iab-tcf (+https://github.com/flenczewski/php-iab-tcf)',
        private readonly int $maxResponseBytes = self::DEFAULT_MAX_RESPONSE_BYTES,
    ) {
        if ($maxResponseBytes < 1) {
            throw new InvalidArgumentException(
                "maxResponseBytes must be at least 1, got {$maxResponseBytes}."
            );
        }
    }

    public function get(string $url): string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->timeoutSeconds,
                'follow_location' => 0,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\nUser-Agent: {$this->userAgent}\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        // Read one byte past the limit so an over-long body is detectable
        // rather than silently truncated into a "corrupt JSON" error. The min()
        // keeps that +1 from overflowing to a float for a PHP_INT_MAX limit,
        // which file_get_contents()'s int $length would reject outright.
        $readLength = min($this->maxResponseBytes, \PHP_INT_MAX - 1) + 1;
        $body = @file_get_contents($url, false, $context, 0, $readLength);
        if ($body === false) {
            throw new GvlException(
                "HTTP request to {$url} failed: the host is unreachable, the request timed out, "
                . 'or allow_url_fopen is disabled.'
            );
        }

        /** @var list<string> $http_response_header set by the HTTP stream wrapper */
        $headers = $http_response_header ?? [];
        $status = self::statusFrom($headers);
        if ($headers !== [] && ($status < 200 || $status >= 300)) {
            throw new GvlException("HTTP request to {$url} returned status {$status}.");
        }

        if (strlen($body) > $this->maxResponseBytes) {
            throw new GvlException(sprintf(
                'HTTP response from %s exceeds the %d-byte limit; refusing to buffer it.',
                $url,
                $this->maxResponseBytes,
            ));
        }

        return $body;
    }

    /** @param list<string> $headers */
    private static function statusFrom(array $headers): int
    {
        $status = 0;
        foreach ($headers as $header) {
            // Take the last status line so redirects and 100-continue do not win.
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        return $status;
    }
}
