<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Tests;

use Flenczewski\IabTcf\BitReader;
use Flenczewski\IabTcf\BitWriter;
use Flenczewski\IabTcf\RangeSection;
use PHPUnit\Framework\TestCase;

/**
 * Property test for the one invariant range-list decoding must never break:
 * the result equals the entries expanded, sorted and de-duplicated.
 *
 * The decoder skips that sort/unique pass when it judges the decoded entries
 * already ascending and non-overlapping, which is what makes decoding a large
 * list affordable. If that judgement is ever wrong, callers silently receive an
 * unsorted or duplicate-bearing vendor list — a corrupted consent signal rather
 * than an error. A worked example is easy to get right; the shortcut has to
 * hold across every shape, so this compares it against a brute-force reference.
 */
final class RangeListPropertyTest extends TestCase
{
    /**
     * Brute force: expand every entry, then sort and de-duplicate.
     *
     * @param array<int, array{0: int, 1: int}> $entries
     * @return int[]
     */
    private static function reference(array $entries): array
    {
        $ids = [];
        foreach ($entries as [$start, $end]) {
            for ($id = $start; $id <= $end; $id++) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /** @param array<int, array{0: int, 1: int}> $entries */
    private static function encode(array $entries): string
    {
        $writer = new BitWriter();
        $writer->writeUint(count($entries), 12);
        foreach ($entries as [$start, $end]) {
            $isRange = $start !== $end;
            $writer->writeBool($isRange)->writeUint($start, 16);
            if ($isRange) {
                $writer->writeUint($end, 16);
            }
        }

        return $writer->toBitString();
    }

    /** @return iterable<string, array{array<int, array{0: int, 1: int}>}> */
    public static function edgeCases(): iterable
    {
        yield 'empty list' => [[]];
        yield 'single point' => [[[5, 5]]];
        yield 'single range' => [[[5, 9]]];
        yield 'adjacent, touching exactly' => [[[1, 3], [4, 6]]];
        yield 'adjacent, sharing an id' => [[[1, 3], [3, 5]]];
        yield 'duplicate points' => [[[7, 7], [7, 7]]];
        yield 'descending order' => [[[10, 12], [1, 3]]];
        yield 'overlapping' => [[[1, 5], [3, 7]]];
        yield 'one contained in another' => [[[1, 10], [4, 6]]];
        yield 'point then range starting on it' => [[[4, 4], [4, 8]]];
        yield 'at the vendor id boundaries' => [[[1, 1], [65535, 65535]]];
    }

    /**
     * @param array<int, array{0: int, 1: int}> $entries
     * @dataProvider edgeCases
     */
    public function testEdgeCasesMatchTheBruteForceReference(array $entries): void
    {
        $decoded = RangeSection::decodeRangeList(new BitReader(self::encode($entries)));

        self::assertSame(self::reference($entries), $decoded);
    }

    public function testRandomisedListsAlwaysMatchTheBruteForceReference(): void
    {
        // Fixed seed: a failure has to be reproducible for whoever picks it up.
        mt_srand(20260908);
        $checked = 0;

        for ($trial = 0; $trial < 2000; $trial++) {
            $entries = [];
            for ($i = 0, $n = mt_rand(0, 5); $i < $n; $i++) {
                $start = mt_rand(1, 30);
                $end = mt_rand(0, 1) === 1 ? $start : min(30, $start + mt_rand(0, 6));
                $entries[] = [$start, $end];
            }

            $decoded = RangeSection::decodeRangeList(new BitReader(self::encode($entries)));
            self::assertSame(
                self::reference($entries),
                $decoded,
                'Mismatch for entries ' . json_encode($entries),
            );
            $checked++;
        }

        self::assertSame(2000, $checked);
    }
}
