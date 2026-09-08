<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Http;

use Flenczewski\IabTcf\Exception\GvlException;

/**
 * Zero-dependency default client built on PHP's HTTP stream wrapper.
 *
 * Unlike a bare file_get_contents() call it sets a timeout, does not follow
 * redirects, verifies TLS, checks the response status and converts warnings
 * into exceptions.
 */
final class StreamHttpClient implements HttpClient
{
    public function __construct(
        private readonly float $timeoutSeconds = 10.0,
        private readonly string $userAgent = 'php-iab-tcf (+https://github.com/flenczewski/php-iab-tcf)',
    ) {
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

        $body = @file_get_contents($url, false, $context);
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
