<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Http;

use Flenczewski\IabTcf\Exception\GvlException;
use Flenczewski\IabTcf\Exception\InvalidArgumentException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/**
 * Adapts a PSR-18 client onto {@see HttpClient}.
 *
 * Requires psr/http-client and psr/http-factory, which this package only
 * suggests — instantiate this class solely if your project already has them.
 *
 * Timeouts are the wrapped client's to configure. The body is read in chunks
 * and refused past $maxResponseBytes, as {@see StreamHttpClient} does — but
 * that bounds only what this class copies into memory. A client that buffers
 * the whole response before returning it (Guzzle's default) has already
 * downloaded it by then; to stop an oversized transfer itself, have the client
 * stream the body (Guzzle: 'stream' => true).
 */
final class Psr18HttpClient implements HttpClient
{
    private const READ_CHUNK_BYTES = 65536;

    /**
     * A filtered stream (gzip decoding, say) may return '' while it consumes
     * input, so one empty read is no proof of a stall — but a stream that keeps
     * returning nothing without reaching eof() would otherwise spin forever.
     */
    private const MAX_CONSECUTIVE_EMPTY_READS = 1000;

    /** @param positive-int $maxResponseBytes */
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly int $maxResponseBytes = StreamHttpClient::DEFAULT_MAX_RESPONSE_BYTES,
    ) {
        if ($maxResponseBytes < 1) {
            throw new InvalidArgumentException(
                "maxResponseBytes must be at least 1, got {$maxResponseBytes}."
            );
        }
    }

    public function get(string $url): string
    {
        $request = $this->requestFactory->createRequest('GET', $url)
            ->withHeader('Accept', 'application/json');

        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new GvlException("HTTP request to {$url} failed: {$e->getMessage()}", 0, $e);
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new GvlException("HTTP request to {$url} returned status {$status}.");
        }

        // Not (string) $response->getBody(): that buffers whatever the
        // endpoint sends, which is the one allocation StreamHttpClient bounds.
        $stream = $response->getBody();
        $size = $stream->getSize();
        if ($size !== null && $size > $this->maxResponseBytes) {
            throw $this->tooLarge($url);
        }

        $body = '';
        $emptyReads = 0;
        while (!$stream->eof()) {
            try {
                $chunk = $stream->read(self::READ_CHUNK_BYTES);
            } catch (\RuntimeException $e) {
                // PSR-7's read() throws on I/O errors, e.g. a connection
                // dropped mid-body; keep it inside the HttpClient contract.
                throw new GvlException(
                    "HTTP response from {$url} could not be read to completion: {$e->getMessage()}",
                    0,
                    $e,
                );
            }

            if ($chunk === '') {
                if (++$emptyReads >= self::MAX_CONSECUTIVE_EMPTY_READS) {
                    throw new GvlException("HTTP response from {$url} stopped producing data before its end.");
                }
                continue;
            }
            $emptyReads = 0;

            $body .= $chunk;
            if (strlen($body) > $this->maxResponseBytes) {
                throw $this->tooLarge($url);
            }
        }

        return $body;
    }

    private function tooLarge(string $url): GvlException
    {
        return new GvlException(sprintf(
            'HTTP response from %s exceeds the %d-byte limit; refusing to buffer it.',
            $url,
            $this->maxResponseBytes,
        ));
    }
}
