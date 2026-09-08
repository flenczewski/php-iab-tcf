<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Tests;

use Flenczewski\IabTcf\Exception\GvlException;
use Flenczewski\IabTcf\Gvl\GvlFetcher;
use Flenczewski\IabTcf\Http\HttpClient;
use PHPUnit\Framework\TestCase;

final class GvlFetcherTest extends TestCase
{
    /**
     * A client that records the URLs it is asked for and always returns $body.
     *
     * @param list<string> $requestedUrls captured by reference so tests can assert on it
     */
    private static function recordingClient(array &$requestedUrls, string $body): HttpClient
    {
        return new class ($requestedUrls, $body) implements HttpClient {
            /** @param list<string> $requestedUrls */
            public function __construct(private array &$requestedUrls, private readonly string $body)
            {
            }

            public function get(string $url): string
            {
                $this->requestedUrls[] = $url;

                return $this->body;
            }
        };
    }

    private static function clientReturning(string $body): HttpClient
    {
        return new class ($body) implements HttpClient {
            public function __construct(private readonly string $body)
            {
            }

            public function get(string $url): string
            {
                return $this->body;
            }
        };
    }

    private static function failingClient(): HttpClient
    {
        return new class implements HttpClient {
            public function get(string $url): string
            {
                throw new GvlException("Failed to fetch the Global Vendor List from {$url}.");
            }
        };
    }

    public function testUrlForLatest(): void
    {
        self::assertSame('https://vendor-list.consensu.org/v3/vendor-list.json', GvlFetcher::urlForLatest());
    }

    public function testUrlForVersion(): void
    {
        self::assertSame(
            'https://vendor-list.consensu.org/v3/archives/vendor-list-v138.json',
            GvlFetcher::urlForVersion(138),
        );
    }

    public function testFetchLatestUsesInjectedHttpClientAndParsesResult(): void
    {
        $fixtureJson = file_get_contents(__DIR__ . '/fixtures/vendor-list-sample.json');
        $requestedUrls = [];

        $fetcher = new GvlFetcher(self::recordingClient($requestedUrls, $fixtureJson));
        $gvl = $fetcher->fetchLatest();

        self::assertSame([GvlFetcher::urlForLatest()], $requestedUrls);
        self::assertSame(175, $gvl->vendorListVersion);
    }

    public function testFetchVersionRequestsTheVersionedUrl(): void
    {
        $fixtureJson = file_get_contents(__DIR__ . '/fixtures/vendor-list-sample.json');
        $requestedUrls = [];

        $fetcher = new GvlFetcher(self::recordingClient($requestedUrls, $fixtureJson));
        $fetcher->fetchVersion(138);

        self::assertSame([GvlFetcher::urlForVersion(138)], $requestedUrls);
    }

    public function testFetchLatestRawReturnsUnparsedPayload(): void
    {
        $fixtureJson = file_get_contents(__DIR__ . '/fixtures/vendor-list-sample.json');

        $fetcher = new GvlFetcher(self::clientReturning($fixtureJson));

        self::assertSame($fixtureJson, $fetcher->fetchLatestRaw());
    }

    public function testThrowsWhenHttpClientFails(): void
    {
        $fetcher = new GvlFetcher(self::failingClient());

        $this->expectException(\RuntimeException::class);
        $fetcher->fetchLatest();
    }
}
