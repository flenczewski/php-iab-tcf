<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Http;

use Flenczewski\IabTcf\Exception\GvlException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/**
 * Adapts a PSR-18 client onto {@see HttpClient}.
 *
 * Requires psr/http-client and psr/http-factory, which this package only
 * suggests — instantiate this class solely if your project already has them.
 */
final class Psr18HttpClient implements HttpClient
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
    ) {
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

        return (string) $response->getBody();
    }
}
