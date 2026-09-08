<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Tests\Http;

use Flenczewski\IabTcf\Exception\GvlException;
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
}
