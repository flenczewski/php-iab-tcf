<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Tests;

use Flenczewski\IabTcf\PublisherRestriction;
use Flenczewski\IabTcf\RestrictionType;
use Flenczewski\IabTcf\TcModel;
use Flenczewski\IabTcf\TcStringEncoder;
use PHPUnit\Framework\TestCase;

final class CliTest extends TestCase
{
    private function runCli(array $args): array
    {
        $cmd = array_merge(['php', dirname(__DIR__) . '/bin/iab-tcf'], $args);

        $process = proc_open(
            $cmd,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [$exitCode, $stdout, $stderr];
    }

    public function testDecodeKnownTcString(): void
    {
        $model = new TcModel(cmpId: 42, cmpVersion: 3, purposesConsent: [1, 2, 3]);
        $tcString = TcStringEncoder::encode($model);

        [$exitCode, $stdout, $stderr] = $this->runCli(['decode', $tcString]);

        self::assertSame(0, $exitCode, "stderr: {$stderr}");
        $decoded = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(42, $decoded['cmpId']);
        self::assertSame(3, $decoded['cmpVersion']);
        self::assertSame([1, 2, 3], $decoded['purposesConsent']);
    }

    public function testDecodeIncludesPublisherRestrictions(): void
    {
        $model = new TcModel(
            cmpId: 42,
            cmpVersion: 3,
            publisherRestrictions: [new PublisherRestriction(4, RestrictionType::REQUIRE_CONSENT, [7, 8])],
        );
        $tcString = TcStringEncoder::encode($model);

        [$exitCode, $stdout, $stderr] = $this->runCli(['decode', $tcString]);

        self::assertSame(0, $exitCode, "stderr: {$stderr}");
        $decoded = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(
            [['purposeId' => 4, 'type' => 'REQUIRE_CONSENT', 'vendorIds' => [7, 8]]],
            $decoded['publisherRestrictions'],
        );
    }

    public function testDecodeWithMissingArgumentFailsCleanly(): void
    {
        [$exitCode, , $stderr] = $this->runCli(['decode']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('missing TC String argument', $stderr);
    }

    public function testDecodeWithInvalidStringFailsCleanly(): void
    {
        [$exitCode, , $stderr] = $this->runCli(['decode', 'not-a-valid-tc-string!!!']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Error:', $stderr);
    }

    public function testUnknownCommandPrintsUsage(): void
    {
        [$exitCode, , $stderr] = $this->runCli(['bogus']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Usage:', $stderr);
    }

    public function testHelpFlagPrintsUsageToStdoutAndSucceeds(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runCli(['--help']);

        self::assertSame(0, $exitCode, "stderr: {$stderr}");
        self::assertStringContainsString('iab-tcf decode', $stdout);
    }
}
