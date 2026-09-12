<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Tests\Http;

use Flenczewski\IabTcf\Exception\GvlException;
use Flenczewski\IabTcf\Exception\InvalidArgumentException;
use Flenczewski\IabTcf\Http\StreamHttpClient;
use PHPUnit\Framework\TestCase;

final class StreamHttpClientTest extends TestCase
{
    public function testReadsALocalFileUrl(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'gvl');
        self::assertIsString($path);
        file_put_contents($path, '{"ok":true}');

        try {
            self::assertSame('{"ok":true}', (new StreamHttpClient())->get('file://' . $path));
        } finally {
            unlink($path);
        }
    }

    public function testUnreachableUrlThrowsAGvlExceptionRatherThanEmittingAWarning(): void
    {
        $this->expectException(GvlException::class);

        // .invalid is reserved by RFC 2606 and can never resolve.
        (new StreamHttpClient(timeoutSeconds: 1.0))->get('http://iab-tcf-test.invalid/vendor-list.json');
    }

    /** @var resource|null */
    private static $server = null;
    private static ?string $baseUrl = null;

    public static function setUpBeforeClass(): void
    {
        // Bind an ephemeral port first so the server cannot collide with
        // anything else on the machine, then hand that port to the server.
        $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($probe === false) {
            return;
        }
        $name = stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int) substr((string) $name, strrpos((string) $name, ':') + 1);

        // Discard the server's output rather than piping it: nothing drains a
        // pipe here, so a chattier router or a longer test list would
        // eventually fill the OS buffer and wedge the child.
        $server = @proc_open(
            ['php', '-S', "127.0.0.1:{$port}", __DIR__ . '/fixtures/router.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        if (!is_resource($server)) {
            return;
        }

        for ($i = 0; $i < 100; $i++) {
            $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($sock !== false) {
                fclose($sock);
                self::$server = $server;
                self::$baseUrl = "http://127.0.0.1:{$port}";

                return;
            }
            usleep(50_000);
        }

        proc_terminate($server);
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        self::$server = null;
        self::$baseUrl = null;
    }

    private function baseUrl(): string
    {
        if (self::$baseUrl === null) {
            self::markTestSkipped('Could not start PHP built-in server for HTTP status tests.');
        }

        return self::$baseUrl;
    }

    public function testSuccessfulResponseReturnsTheBody(): void
    {
        self::assertSame(
            '{"vendorListVersion":175}',
            (new StreamHttpClient())->get($this->baseUrl() . '/ok')
        );
    }

    public function testSendsAJsonAcceptHeader(): void
    {
        self::assertStringContainsString(
            'application/json',
            (new StreamHttpClient())->get($this->baseUrl() . '/echo-accept')
        );
    }

    /** @return iterable<string, array{int}> */
    public static function errorStatuses(): iterable
    {
        yield 'not found' => [404];
        yield 'server error' => [500];
        yield 'gateway timeout' => [504];
    }

    /** @dataProvider errorStatuses */
    public function testNonSuccessStatusThrowsInsteadOfReturningTheErrorBody(int $status): void
    {
        $this->expectException(GvlException::class);
        $this->expectExceptionMessage("returned status {$status}");

        (new StreamHttpClient())->get($this->baseUrl() . '/status/' . $status);
    }

    /**
     * Everything else in this package bounds what untrusted input can make it
     * allocate; an unbounded file_get_contents() was the one gap. A hostile or
     * misconfigured endpoint should not be able to stream the process to death.
     */
    public function testAResponseAboveTheByteLimitIsRefused(): void
    {
        $this->expectException(GvlException::class);
        $this->expectExceptionMessage('exceeds the 100-byte limit');

        (new StreamHttpClient(maxResponseBytes: 100))->get($this->baseUrl() . '/bytes/5000');
    }

    public function testAResponseExactlyAtTheByteLimitIsAccepted(): void
    {
        $body = (new StreamHttpClient(maxResponseBytes: 100))->get($this->baseUrl() . '/bytes/100');

        self::assertSame(100, strlen($body));
    }

    public function testTheDefaultLimitDoesNotInterfereWithARealisticPayload(): void
    {
        $body = (new StreamHttpClient())->get($this->baseUrl() . '/bytes/5000');

        self::assertSame(5000, strlen($body));
    }

    public function testRejectsANonPositiveByteLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('maxResponseBytes must be at least 1');

        /** @phpstan-ignore argument.type (the runtime guard is what is under test) */
        new StreamHttpClient(maxResponseBytes: 0);
    }

    public function testAnIntMaxByteLimitReadsWhatWasSentRatherThanReservingTheLimit(): void
    {
        // The limit used to be passed to file_get_contents() as $maxlen, which
        // PHP allocates up front before 8.3 — so this died with "Out of memory
        // (tried to allocate 9223372036854775832 bytes)" rather than reading
        // 5000 bytes. Streaming makes the footprint follow the response.
        $body = (new StreamHttpClient(maxResponseBytes: \PHP_INT_MAX))->get($this->baseUrl() . '/bytes/5000');

        self::assertSame(5000, strlen($body));
    }

    public function testRedirectsAreNotFollowedAndSurfaceAsAnError(): void
    {
        // A vendor list served from an unexpected redirect is not something to
        // follow silently — follow_location is off, so the 302 must surface.
        $this->expectException(GvlException::class);
        $this->expectExceptionMessage('returned status 302');

        (new StreamHttpClient())->get($this->baseUrl() . '/redirect');
    }
}
