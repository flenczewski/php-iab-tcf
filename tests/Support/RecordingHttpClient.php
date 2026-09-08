<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Tests\Support;

use Flenczewski\IabTcf\Exception\GvlException;
use Flenczewski\IabTcf\Http\HttpClient;

/**
 * Test double that records the URLs it was asked for and replays a fixed body,
 * or fails, so GvlFetcher can be exercised without touching the network.
 */
final class RecordingHttpClient implements HttpClient
{
    /** @var list<string> URLs passed to get(), in order */
    public array $requestedUrls = [];

    private function __construct(
        private readonly ?string $body,
    ) {
    }

    public static function returning(string $body): self
    {
        return new self($body);
    }

    /** A client whose every request fails the way a transport error would. */
    public static function failing(): self
    {
        return new self(null);
    }

    public function get(string $url): string
    {
        $this->requestedUrls[] = $url;

        if ($this->body === null) {
            throw new GvlException("Failed to fetch the Global Vendor List from {$url}.");
        }

        return $this->body;
    }
}
