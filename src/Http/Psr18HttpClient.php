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
 * and refused past $maxResponseBytes, as {@see StreamHttpClient} does.
 */
final class Psr18HttpClient implements HttpClient
{
    private const READ_CHUNK_BYTES = 65536;

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
        while (!$stream->eof()) {
            $body .= $stream->read(self::READ_CHUNK_BYTES);
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
