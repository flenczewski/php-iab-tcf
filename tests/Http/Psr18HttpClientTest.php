<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Tests\Http;

use Flenczewski\IabTcf\Exception\GvlException;
use Flenczewski\IabTcf\Exception\InvalidArgumentException;
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

    public function testAResponseAboveTheByteLimitIsRefused(): void
    {
        $client = new RecordingPsr18Client(new Response(200, [], str_repeat('x', 5000)));

        $this->expectException(GvlException::class);
        $this->expectExceptionMessage('exceeds the 100-byte limit');

        (new Psr18HttpClient($client, new Psr17Factory(), maxResponseBytes: 100))->get('https://example.test/l');
    }

    /** A body of unknown size (a live network stream) is cut off while it is read, not after. */
    public function testABodyOfUnknownSizeIsRefusedWhileItIsRead(): void
    {
        $unsized = new UnsizedStream((new Psr17Factory())->createStream(str_repeat('x', 200_000)));
        $client = new RecordingPsr18Client((new Response(200))->withBody($unsized));

        try {
            (new Psr18HttpClient($client, new Psr17Factory(), maxResponseBytes: 100))->get('https://example.test/l');
            self::fail('Expected a GvlException.');
        } catch (GvlException $e) {
            self::assertStringContainsString('exceeds the 100-byte limit', $e->getMessage());
            self::assertLessThan(200_000, $unsized->bytesRead, 'Reading must stop at the limit.');
        }
    }

    /** PSR-7 read() throws \RuntimeException on I/O errors; that must not escape the HttpClient contract. */
    public function testAReadFailureMidBodyIsWrappedAndKeepsItsCause(): void
    {
        $cause = new \RuntimeException('connection reset by peer');
        $stream = new ScriptedReadStream(static fn (int $read): string => $read === 1 ? '{"vendor' : throw $cause);
        $client = new RecordingPsr18Client((new Response(200))->withBody($stream));

        try {
            (new Psr18HttpClient($client, new Psr17Factory()))->get('https://example.test/l');
            self::fail('Expected a GvlException.');
        } catch (GvlException $e) {
            self::assertStringContainsString('could not be read to completion', $e->getMessage());
            self::assertStringContainsString('connection reset by peer', $e->getMessage());
            self::assertSame($cause, $e->getPrevious());
        }
    }

    public function testABodyThatNeverEndsNorProducesDataIsRefusedInsteadOfSpinning(): void
    {
        $stream = new ScriptedReadStream(static fn (): string => '');
        $client = new RecordingPsr18Client((new Response(200))->withBody($stream));

        try {
            (new Psr18HttpClient($client, new Psr17Factory()))->get('https://example.test/l');
            self::fail('Expected a GvlException.');
        } catch (GvlException $e) {
            self::assertStringContainsString('stopped producing data', $e->getMessage());
            self::assertLessThanOrEqual(1000, $stream->reads);
        }
    }

    /** A decoding stream may return '' while it consumes input; occasional empty reads are not a stall. */
    public function testOccasionalEmptyReadsAreTolerated(): void
    {
        $parts = ['', '{"a"', '', '', ':1}'];
        $stream = new ScriptedReadStream(static fn (int $read): string => $parts[$read - 1], eofAfterReads: 5);
        $client = new RecordingPsr18Client((new Response(200))->withBody($stream));

        self::assertSame('{"a":1}', (new Psr18HttpClient($client, new Psr17Factory()))->get('https://example.test/l'));
    }

    public function testAResponseExactlyAtTheByteLimitIsAccepted(): void
    {
        $client = new RecordingPsr18Client(new Response(200, [], str_repeat('x', 100)));

        $body = (new Psr18HttpClient($client, new Psr17Factory(), maxResponseBytes: 100))
            ->get('https://example.test/l');

        self::assertSame(100, strlen($body));
    }

    public function testRejectsANonPositiveByteLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('maxResponseBytes must be at least 1');

        /** @phpstan-ignore argument.type (the runtime guard is what is under test) */
        new Psr18HttpClient(new RecordingPsr18Client(new Response(200)), new Psr17Factory(), maxResponseBytes: 0);
    }
}
