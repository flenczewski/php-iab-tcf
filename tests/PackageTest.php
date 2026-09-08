<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Tests;

use PHPUnit\Framework\TestCase;

final class PackageTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function composerJson(): array
    {
        $json = file_get_contents(dirname(__DIR__) . '/composer.json');
        self::assertIsString($json);

        /** @var array<string,mixed> $data */
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $data;
    }

    public function testDeclaresEveryExtensionItUses(): void
    {
        /** @var array<string,string> $require */
        $require = self::composerJson()['require'];

        self::assertArrayHasKey('ext-json', $require, 'json_encode/json_decode are used throughout.');
    }

    public function testHasNoRuntimeDependenciesBeyondPhpAndExtensions(): void
    {
        /** @var array<string,string> $require */
        $require = self::composerJson()['require'];

        foreach (array_keys($require) as $package) {
            self::assertMatchesRegularExpression(
                '/^(php|ext-[a-z0-9]+)$/',
                $package,
                "This package promises zero runtime dependencies; found {$package}.",
            );
        }
    }

    public function testExcludesDevelopmentFilesFromDistributions(): void
    {
        $gitattributes = file_get_contents(dirname(__DIR__) . '/.gitattributes');
        self::assertIsString($gitattributes);

        foreach (['/tests', '/.github', '/phpunit.xml.dist', '/tools'] as $path) {
            self::assertStringContainsString(
                $path,
                $gitattributes,
                "{$path} should be export-ignored so composer require does not ship it.",
            );
        }
    }
}
