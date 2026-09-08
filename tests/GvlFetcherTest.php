<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Tests;

use Flenczewski\IabTcf\Gvl\GvlFetcher;
use Flenczewski\IabTcf\Tests\Support\RecordingHttpClient;
use PHPUnit\Framework\TestCase;

final class GvlFetcherTest extends TestCase
{
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
        self::assertIsString($fixtureJson);
        $client = RecordingHttpClient::returning($fixtureJson);
        $fetcher = new GvlFetcher($client);
        $gvl = $fetcher->fetchLatest();

        self::assertSame([GvlFetcher::urlForLatest()], $client->requestedUrls);
        self::assertSame(175, $gvl->vendorListVersion);
    }

    public function testFetchVersionRequestsTheVersionedUrl(): void
    {
        $fixtureJson = file_get_contents(__DIR__ . '/fixtures/vendor-list-sample.json');
        self::assertIsString($fixtureJson);
        $client = RecordingHttpClient::returning($fixtureJson);
        $fetcher = new GvlFetcher($client);
        $fetcher->fetchVersion(138);

        self::assertSame([GvlFetcher::urlForVersion(138)], $client->requestedUrls);
    }

    public function testFetchLatestRawReturnsUnparsedPayload(): void
    {
        $fixtureJson = file_get_contents(__DIR__ . '/fixtures/vendor-list-sample.json');
        self::assertIsString($fixtureJson);

        $fetcher = new GvlFetcher(RecordingHttpClient::returning($fixtureJson));

        self::assertSame($fixtureJson, $fetcher->fetchLatestRaw());
    }

    public function testThrowsWhenHttpClientFails(): void
    {
        $fetcher = new GvlFetcher(RecordingHttpClient::failing());

        $this->expectException(\RuntimeException::class);
        $fetcher->fetchLatest();
    }
}
