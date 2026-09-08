<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Tests\Http;

use Flenczewski\IabTcf\Exception\GvlException;
use Flenczewski\IabTcf\Http\Psr18HttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class Psr18HttpClientTest extends TestCase
{
    public function testReturnsTheBodyAndSendsAGetWithAJsonAcceptHeader(): void
    {
        $client = new RecordingPsr18Client(new Response(200, [], '{"vendorListVersion":175}'));

        $body = (new Psr18HttpClient($client, new Psr17Factory()))
            ->get('https://example.test/vendor-list.json');

        self::assertSame('{"vendorListVersion":175}', $body);
        self::assertNotNull($client->lastRequest);
        self::assertSame('GET', $client->lastRequest->getMethod());
        self::assertSame('https://example.test/vendor-list.json', (string) $client->lastRequest->getUri());
        self::assertSame('application/json', $client->lastRequest->getHeaderLine('Accept'));
    }

    /** @return iterable<string, array{int}> */
    public static function nonSuccessStatuses(): iterable
    {
        yield 'not found' => [404];
        yield 'server error' => [500];
        yield 'redirect (deliberately not followed)' => [301];
        yield 'informational' => [199];
    }

    /** @dataProvider nonSuccessStatuses */
    public function testNonSuccessStatusThrows(int $status): void
    {
        $client = new RecordingPsr18Client(new Response($status, [], 'nope'));

        $this->expectException(GvlException::class);
        $this->expectExceptionMessage("returned status {$status}");

        (new Psr18HttpClient($client, new Psr17Factory()))->get('https://example.test/list.json');
    }

    public function testABodyIsStillReturnedForOtherSuccessStatuses(): void
    {
        $client = new RecordingPsr18Client(new Response(204, [], ''));

        self::assertSame('', (new Psr18HttpClient($client, new Psr17Factory()))->get('https://example.test/l'));
    }

    public function testTransportFailureIsWrappedAndKeepsItsCause(): void
    {
        $cause = new class ('connection refused') extends \RuntimeException implements ClientExceptionInterface {
        };
        $client = new class ($cause) implements ClientInterface {
            public function __construct(private readonly \Throwable $cause)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw $this->cause;
            }
        };

        try {
            (new Psr18HttpClient($client, new Psr17Factory()))->get('https://example.test/list.json');
            self::fail('Expected a GvlException.');
        } catch (GvlException $e) {
            self::assertStringContainsString('connection refused', $e->getMessage());
            self::assertSame($cause, $e->getPrevious(), 'The transport failure must be preserved.');
        }
    }
}
