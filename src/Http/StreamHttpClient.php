<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Http;

use Flenczewski\IabTcf\Exception\GvlException;
use Flenczewski\IabTcf\Exception\InvalidArgumentException;

/**
 * Zero-dependency default client built on PHP's HTTP stream wrapper.
 *
 * Unlike a bare file_get_contents() call it bounds each read and the body
 * download in time, does not follow redirects, verifies TLS, checks the
 * response status and converts warnings into exceptions.
 *
 * The total deadline starts before connecting but is enforced only once the
 * headers are in: PHP's HTTP wrapper connects and reads the headers inside
 * fopen(), where just the per-read timeout applies. A server trickling its
 * headers can therefore still hold the call open past $totalTimeoutSeconds;
 * if that matters, use a PSR-18 client with a hard overall timeout.
 *
 * Any URL PHP's stream wrappers accept is fetched, `file://` included, and the
 * status check applies only to responses that carry HTTP headers. Never pass a
 * URL taken from untrusted input.
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

    /**
     * @param float $timeoutSeconds longest wait for any single read, including connecting
     * @param positive-int $maxResponseBytes
     * @param float $totalTimeoutSeconds longest the request may take once the headers are in (see the class
     *                                   docblock); without it a server trickling body bytes just inside
     *                                   $timeoutSeconds could hold the call open forever
     */
    public function __construct(
        private readonly float $timeoutSeconds = 10.0,
        private readonly string $userAgent = 'php-iab-tcf (+https://github.com/flenczewski/php-iab-tcf)',
        private readonly int $maxResponseBytes = self::DEFAULT_MAX_RESPONSE_BYTES,
        private readonly float $totalTimeoutSeconds = 60.0,
    ) {
        $timeouts = ['timeoutSeconds' => $timeoutSeconds, 'totalTimeoutSeconds' => $totalTimeoutSeconds];
        foreach ($timeouts as $name => $value) {
            if (!($value > 0)) {
                throw new InvalidArgumentException("{$name} must be greater than 0, got {$value}.");
            }
        }
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
                // Applies per read while fopen() connects and reads the headers,
                // which the deadline below cannot interrupt; capping it at the
                // total at least keeps any single wait there within it.
                'timeout' => min($this->timeoutSeconds, $this->totalTimeoutSeconds),
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
        $deadline = microtime(true) + $this->totalTimeoutSeconds;
        error_clear_last();
        $handle = @fopen($url, 'r', false, $context);
        if ($handle === false) {
            // The warning is suppressed so it cannot leak past the exception,
            // but its text is the only record of *why* the open failed.
            $reason = error_get_last()['message'] ?? 'the host is unreachable, the request timed out, '
                . 'or allow_url_fopen is disabled';
            throw new GvlException("HTTP request to {$url} failed: {$reason}");
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
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    throw self::deadlineExceeded($url, $this->totalTimeoutSeconds);
                }
                // Shrink the per-read timeout to what is left of the deadline,
                // so a single blocked read cannot overrun it.
                $wait = min($this->timeoutSeconds, $remaining);
                stream_set_timeout($handle, (int) $wait, (int) (($wait - (int) $wait) * 1_000_000));

                $chunk = fread($handle, self::READ_CHUNK_BYTES);
                // A read that times out returns false or '' depending on the
                // wrapper, so ask the stream why before reporting a failure.
                if (($chunk === false || $chunk === '') && stream_get_meta_data($handle)['timed_out']) {
                    // Judged by which limit this read was given, not by the
                    // clock: select() may wake a hair before the deadline.
                    throw $wait < $this->timeoutSeconds
                        ? self::deadlineExceeded($url, $this->totalTimeoutSeconds)
                        : new GvlException("HTTP response from {$url} stalled for {$this->timeoutSeconds} seconds.");
                }
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

            // feof() also becomes true when the peer hangs up mid-body, which
            // would otherwise hand back a truncated vendor list as if it were
            // complete. Compare against the length the server promised.
            $expected = self::contentLengthFrom($headers);
            if ($expected !== null && strlen($body) !== $expected) {
                throw new GvlException(sprintf(
                    'HTTP response from %s is %d bytes but declared Content-Length: %d.',
                    $url,
                    strlen($body),
                    $expected,
                ));
            }
        } finally {
            fclose($handle);
        }

        return $body;
    }

    private static function deadlineExceeded(string $url, float $seconds): GvlException
    {
        return new GvlException("HTTP request to {$url} did not complete within {$seconds} seconds.");
    }

    /** @param list<string> $headers */
    private static function contentLengthFrom(array $headers): ?int
    {
        $length = null;
        foreach ($headers as $header) {
            // Take the last one: a redirect chain leaves earlier headers behind.
            if (preg_match('#^Content-Length:\s*(\d+)\s*\z#i', $header, $matches) === 1) {
                $length = (int) $matches[1];
            }
        }

        return $length;
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
