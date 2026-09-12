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

    /** How much of the body to pull per read; large enough that a multi-megabyte list is not read byte-wise. */
    private const READ_CHUNK_BYTES = 65536;

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

        // Read the body in chunks rather than handing file_get_contents() a
        // $maxlen: before PHP 8.3 that argument is allocated up front, so the
        // 64 MB default would eagerly claim 64 MB for a 1 MB vendor list, and
        // a PHP_INT_MAX limit died outright with "Out of memory". Streaming
        // keeps the footprint proportional to what the endpoint actually sent
        // while still refusing to buffer more than the limit.
        $handle = @fopen($url, 'r', false, $context);
        if ($handle === false) {
            throw new GvlException(
                "HTTP request to {$url} failed: the host is unreachable, the request timed out, "
                . 'or allow_url_fopen is disabled.'
            );
        }

        try {
            $metadata = stream_get_meta_data($handle);
            /** @var list<string> $headers */
            $headers = array_values(array_filter(
                is_array($metadata['wrapper_data'] ?? null) ? $metadata['wrapper_data'] : [],
                is_string(...),
            ));
            $status = self::statusFrom($headers);
            if ($headers !== [] && ($status < 200 || $status >= 300)) {
                throw new GvlException("HTTP request to {$url} returned status {$status}.");
            }

            $body = '';
            while (!feof($handle)) {
                $chunk = fread($handle, self::READ_CHUNK_BYTES);
                if ($chunk === false) {
                    throw new GvlException("HTTP response from {$url} could not be read to completion.");
                }

                $body .= $chunk;
                if (strlen($body) > $this->maxResponseBytes) {
                    throw new GvlException(sprintf(
                        'HTTP response from %s exceeds the %d-byte limit; refusing to buffer it.',
                        $url,
                        $this->maxResponseBytes,
                    ));
                }
            }
        } finally {
            fclose($handle);
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
