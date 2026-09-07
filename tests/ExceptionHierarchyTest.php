<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Tests;

use Flenczewski\IabTcf\Alpha2Code;
use Flenczewski\IabTcf\Base64Url;
use Flenczewski\IabTcf\BitReader;
use Flenczewski\IabTcf\BitWriter;
use Flenczewski\IabTcf\Exception\IabTcfException;
use Flenczewski\IabTcf\Exception\InvalidTcStringException;
use PHPUnit\Framework\TestCase;

final class ExceptionHierarchyTest extends TestCase
{
    /** @return iterable<string, array{callable(): mixed}> */
    public static function throwingOperations(): iterable
    {
        yield 'BitWriter negative value' => [static fn () => (new BitWriter())->writeUint(-1, 8)];
        yield 'BitWriter overflow' => [static fn () => (new BitWriter())->writeUint(999, 4)];
        yield 'BitReader overrun' => [static fn () => (new BitReader('101'))->readBits(8)];
        yield 'Base64Url garbage' => [static fn () => Base64Url::decodeToBits('!!!!')];
        yield 'Alpha2Code bad code' => [static fn () => Alpha2Code::encode('123')];
    }

    /**
     * @param callable(): mixed $operation
     * @dataProvider throwingOperations
     */
    public function testEveryFailureIsCatchableAsAPackageException(callable $operation): void
    {
        $this->expectException(IabTcfException::class);
        $operation();
    }

    public function testInvalidTcStringExceptionIsAnInvalidArgumentException(): void
    {
        $exception = new InvalidTcStringException('boom');

        self::assertInstanceOf(IabTcfException::class, $exception);
        self::assertInstanceOf(\InvalidArgumentException::class, $exception);
    }
}
