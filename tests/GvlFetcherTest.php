<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Tests;

use Flenczewski\IabTcf\Exception\InvalidArgumentException;
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

    /**
     * Vendor list versions start at 1. A negative one used to build
     * ".../vendor-list-v-1.json" and only surface as a remote 404.
     *
     * @dataProvider impossibleVersions
     */
    public function testUrlForVersionRejectsAVersionBelowOne(int $version): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be 1 or greater');

        GvlFetcher::urlForVersion($version);
    }

    /** @return iterable<string,array{int}> */
    public static function impossibleVersions(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
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
