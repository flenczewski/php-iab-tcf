# php-iab-tcf 2.0.0 Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close every finding in the 2026-09-07 audit — a remote-DoS hole, silent consent-data loss, an unvalidated GVL parser, a missing exception hierarchy — and ship the result as a properly packaged, statically analysed, reference-tested 2.0.0.

**Architecture:** The package stays a zero-runtime-dependency, static-codec library. Three new seams are introduced: a `Flenczewski\IabTcf\Exception\*` hierarchy behind a single `IabTcfException` marker interface, a `Spec` class holding every TCF field bound as a named constant, and an internal `HttpClient` interface with a hardened stream default plus an optional PSR-18 adapter. All validation is centralised: `TcModel` becomes valid-by-construction, and `TcStringDecoder::decode()` funnels every internal failure into one catchable `InvalidTcStringException`.

**Tech Stack:** PHP 8.1+ (`declare(strict_types=1)` everywhere), PHPUnit 10.5, PHPStan level max, PHP-CS-Fixer (PSR-12), GitHub Actions, Docker (test runner).

**Spec:** `docs/superpowers/specs/2026-09-07-v2-hardening-spec.md` — read it before starting. It records what is already verified-correct and must not regress.

## Global Constraints

- **PHP floor: 8.1.** No `readonly class` (8.2), no `json_validate()` (8.3), no property hooks (8.4). Per-property `readonly` and enums are fine.
- **Zero runtime dependencies.** `require` may contain only `php` and `ext-json`. PSR packages go in `require-dev` + `suggest`, never `require`.
- **Namespace:** `Flenczewski\IabTcf\` → `src/`, `Flenczewski\IabTcf\Tests\` → `tests/`.
- **Every class is `final`** and starts with `declare(strict_types=1);`.
- **Hard format bounds** (from the TCF v2 Consent String spec, all copied into `Spec` in Task 3): vendor id `1..65535`, purpose id `1..24`, special feature id `1..12`, cmpId/cmpVersion/vendorListVersion `0..4095`, consentScreen/tcfPolicyVersion `0..63`, `NumEntries` `0..4095`.
- **Do not enforce `end <= maxVendorId`** when decoding a range list — deliberate interop leniency, see spec F1.
- **Test command:** `composer test` (Task 1 makes this work everywhere). Never claim a test passes without pasting the runner output.
- **Commit style:** Conventional Commits. Breaking changes get a `!` and a `BREAKING CHANGE:` footer.
- **Do not touch** `resources/vendor-list.json` (908 KB generated artifact) in any commit.

## File Structure

**New files**

| Path | Responsibility |
|---|---|
| `src/Exception/IabTcfException.php` | Marker interface — the one thing consumers catch |
| `src/Exception/InvalidArgumentException.php` | Bad input to an encoder/primitive |
| `src/Exception/OutOfRangeException.php` | Bit-buffer overrun (`BitReader`) |
| `src/Exception/InvalidTcStringException.php` | Any failure to decode a TC String |
| `src/Exception/GvlException.php` | GVL parse/fetch failures |
| `src/Spec.php` | Every TCF field bound as a named constant — single source of truth |
| `src/Http/HttpClient.php` | Internal `get(string $url): string` seam |
| `src/Http/StreamHttpClient.php` | Zero-dependency default: timeout, UA, status check |
| `src/Http/Psr18HttpClient.php` | Optional PSR-18 adapter |
| `tools/test.sh` | Runs PHPUnit natively, or in Docker when extensions are missing |
| `tests/ReferenceVectorTest.php` | Decodes externally-produced TC Strings — the only spec-conformance proof |
| `tests/MalformedInputTest.php` | Hostile and truncated input |
| `tests/SpecBoundaryTest.php` | 65535 / 4095 / 24 / 12 edge values |
| `CHANGELOG.md`, `UPGRADE-2.0.md`, `SECURITY.md`, `CONTRIBUTING.md`, `.editorconfig`, `.gitattributes`, `phpstan.neon.dist`, `.php-cs-fixer.dist.php` | Packaging and OSS hygiene |

**Modified files:** every file in `src/` except `PublisherRestriction.php` and `RestrictionType.php`; `bin/iab-tcf`; `composer.json`; `phpunit.xml.dist`; `.gitignore`; `README.md`; both workflows.

---

## Phase 0 — Make the suite trustworthy

### Task 1: Runnable, warning-strict test suite

Nothing else in this plan can be verified until `composer test` works and PHP warnings fail the build. Finding F3 is *invisible* today precisely because warnings pass CI.

**Files:**
- Create: `tools/test.sh`
- Modify: `phpunit.xml.dist`, `composer.json` (scripts), `.gitignore`

**Interfaces:**
- Consumes: nothing
- Produces: `composer test` — used as the verification command by every later task

- [ ] **Step 1: Delete the stale root-owned result cache**

```bash
rm -f .phpunit.result.cache
```

- [ ] **Step 2: Create the test runner**

Create `tools/test.sh`:

```sh
#!/usr/bin/env sh
# Runs PHPUnit natively when the required extensions are present, otherwise
# falls back to the official composer image, which ships dom/mbstring/xmlwriter.
set -eu

missing=""
for ext in dom mbstring xmlwriter tokenizer; do
    php -m 2>/dev/null | grep -qx "$ext" || missing="$missing $ext"
done

if [ -z "$missing" ]; then
    exec vendor/bin/phpunit "$@"
fi

echo "Missing PHP extensions:$missing — running the suite in Docker instead." >&2
exec docker run --rm \
    -v "$PWD":/app -w /app \
    -u "$(id -u):$(id -g)" \
    -e COMPOSER_HOME=/tmp/composer \
    composer:2 \
    sh -c 'composer install --no-interaction --quiet && exec vendor/bin/phpunit "$@"' -- "$@"
```

```bash
chmod +x tools/test.sh
```

- [ ] **Step 3: Verify the runner reproduces the known-good baseline**

Run: `./tools/test.sh --no-coverage`
Expected: `OK (60 tests, 160 assertions)`. If the count differs, stop — the working tree is not at the audited baseline.

- [ ] **Step 4: Make warnings fatal**

Replace the opening tag of `phpunit.xml.dist` with:

```xml
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/10.5/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         cacheDirectory=".phpunit.cache"
         colors="true"
         failOnWarning="true"
         failOnRisky="true"
         failOnNotice="true"
         failOnDeprecation="true"
         beStrictAboutOutputDuringTests="true">
```

- [ ] **Step 5: Wire up the composer script**

In `composer.json`, replace `"test": "phpunit"` with:

```json
"test": "tools/test.sh"
```

Add `/.phpunit.cache/` to `.gitignore` if it is not already there (it is — verify, do not duplicate).

- [ ] **Step 6: Confirm the strict config still passes**

Run: `composer test -- --no-coverage`
Expected: `OK (60 tests, 160 assertions)`, no `Warning:` lines about the result cache.

- [ ] **Step 7: Commit**

```bash
git add tools/test.sh phpunit.xml.dist composer.json .gitignore
git commit -m "test: add portable runner and make warnings fail the suite"
```

---

## Phase 1 — Security and correctness (audit 🔴)

### Task 2: Exception hierarchy

**Files:**
- Create: `src/Exception/IabTcfException.php`, `src/Exception/InvalidArgumentException.php`, `src/Exception/OutOfRangeException.php`, `src/Exception/InvalidTcStringException.php`, `src/Exception/GvlException.php`
- Modify: `src/BitWriter.php:18,21`, `src/BitReader.php:47`, `src/Base64Url.php:36`, `src/Alpha2Code.php:16`, `src/Gvl/GvlFetcher.php:63`
- Test: `tests/ExceptionHierarchyTest.php`

**Interfaces:**
- Consumes: nothing
- Produces: `Flenczewski\IabTcf\Exception\IabTcfException` (interface), `…\InvalidArgumentException`, `…\OutOfRangeException`, `…\InvalidTcStringException`, `…\GvlException`. Every later task throws from this set and never from SPL directly.

Each concrete class extends its closest SPL ancestor *and* implements the marker, so `catch (\InvalidArgumentException)` keeps working while `catch (IabTcfException)` becomes possible.

- [ ] **Step 1: Write the failing test**

Create `tests/ExceptionHierarchyTest.php`:

```php
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
```

- [ ] **Step 2: Run it and watch it fail**

Run: `composer test -- --filter ExceptionHierarchyTest`
Expected: FAIL — `Class "Flenczewski\IabTcf\Exception\IabTcfException" not found`.

- [ ] **Step 3: Create the five exception types**

`src/Exception/IabTcfException.php`:

```php
<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Exception;

/**
 * Implemented by every exception this package throws.
 *
 * Catch this to handle "anything went wrong inside php-iab-tcf" without
 * catching unrelated SPL exceptions from your own code.
 */
interface IabTcfException extends \Throwable
{
}
```

`src/Exception/InvalidArgumentException.php`:

```php
<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Exception;

/** A value handed to this package is outside the range the TCF spec allows. */
class InvalidArgumentException extends \InvalidArgumentException implements IabTcfException
{
}
```

`src/Exception/OutOfRangeException.php`:

```php
<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Exception;

/** A read ran past the end of a bit buffer — the input is truncated or malformed. */
class OutOfRangeException extends \OutOfRangeException implements IabTcfException
{
}
```

`src/Exception/InvalidTcStringException.php`:

```php
<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Exception;

/**
 * A TC String could not be decoded. TcStringDecoder::decode() funnels every
 * internal failure into this type, so a single catch covers all of them; the
 * original cause is always available via getPrevious().
 */
class InvalidTcStringException extends InvalidArgumentException
{
}
```

`src/Exception/GvlException.php`:

```php
<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Exception;

/** The Global Vendor List could not be fetched or parsed. */
class GvlException extends \RuntimeException implements IabTcfException
{
}
```

- [ ] **Step 4: Repoint the existing throw sites**

These classes are **not** `final` (subclassing `InvalidTcStringException` from `InvalidArgumentException` requires it), which is the one documented exception to the global "everything is final" constraint.

In `src/BitWriter.php`, `src/Base64Url.php`, `src/Alpha2Code.php` add
`use Flenczewski\IabTcf\Exception\InvalidArgumentException;` and drop the `\` prefix from
`throw new \InvalidArgumentException(` at `BitWriter.php:18`, `BitWriter.php:21`,
`Base64Url.php:36`, `Alpha2Code.php:16`.

In `src/BitReader.php` add `use Flenczewski\IabTcf\Exception\OutOfRangeException;` and change
line 47 to `throw new OutOfRangeException('Attempted to read past the end of the bit buffer.');`.

In `src/Gvl/GvlFetcher.php` add `use Flenczewski\IabTcf\Exception\GvlException;` and change
line 63 to `throw new GvlException("Failed to fetch the Global Vendor List from {$url}.");`.

- [ ] **Step 5: Run the full suite**

Run: `composer test`
Expected: `OK (67 tests, ...)` — the 60 originals still pass (they assert SPL parent types, which still match) plus 7 new.

- [ ] **Step 6: Commit**

```bash
git add src/Exception tests/ExceptionHierarchyTest.php src/BitWriter.php src/BitReader.php src/Base64Url.php src/Alpha2Code.php src/Gvl/GvlFetcher.php
git commit -m "feat!: introduce IabTcfException hierarchy

Every throw site now implements Flenczewski\IabTcf\Exception\IabTcfException,
so consumers can catch this package's failures specifically.

BREAKING CHANGE: exceptions are now package types. Code matching on the exact
class \InvalidArgumentException::class must switch to instanceof or to the new
Flenczewski\IabTcf\Exception\* classes."
```

---

### Task 3: `Spec` constants and a correct `BitWriter`

Fixes F2 (silent id loss) and F7 (`writeUint(0, 0)`, unchecked `$numBits`).

**Files:**
- Create: `src/Spec.php`
- Modify: `src/BitWriter.php:15-50`, `src/RangeSection.php:18`
- Test: `tests/BitWriterReaderTest.php` (append)

**Interfaces:**
- Consumes: `Exception\InvalidArgumentException` (Task 2)
- Produces: `Spec::MAX_VENDOR_ID`, `Spec::MIN_VENDOR_ID`, `Spec::MAX_PURPOSE_ID`, `Spec::MAX_SPECIAL_FEATURE_ID`, `Spec::MAX_CMP_ID`, `Spec::MAX_CMP_VERSION`, `Spec::MAX_CONSENT_SCREEN`, `Spec::MAX_VENDOR_LIST_VERSION`, `Spec::MAX_TCF_POLICY_VERSION`, `Spec::MAX_RANGE_ENTRIES` — Tasks 4, 5, 6 all read these.
- Produces: `BitWriter::writeIdSet()` now throws instead of dropping.

- [ ] **Step 1: Write the failing tests**

Append to `tests/BitWriterReaderTest.php` (inside the class):

```php
    public function testWriteIdSetRejectsIdAboveWidth(): void
    {
        $this->expectException(\Flenczewski\IabTcf\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('id 25');

        (new BitWriter())->writeIdSet([1, 25], 24);
    }

    public function testWriteIdSetRejectsIdBelowOne(): void
    {
        $this->expectException(\Flenczewski\IabTcf\Exception\InvalidArgumentException::class);

        (new BitWriter())->writeIdSet([0, 5], 24);
    }

    public function testWriteUintWithZeroWidthWritesNothing(): void
    {
        self::assertSame('', (new BitWriter())->writeUint(0, 0)->toBitString());
    }

    public function testWriteUintRejectsNonZeroValueInZeroWidth(): void
    {
        $this->expectException(\Flenczewski\IabTcf\Exception\InvalidArgumentException::class);

        (new BitWriter())->writeUint(1, 0);
    }

    public function testWriteUintRejectsImpossibleWidth(): void
    {
        $this->expectException(\Flenczewski\IabTcf\Exception\InvalidArgumentException::class);

        (new BitWriter())->writeUint(1, 64);
    }
```

- [ ] **Step 2: Run and watch them fail**

Run: `composer test -- --filter BitWriterReaderTest`
Expected: FAIL — `writeIdSet` silently accepts 25 (no exception), `writeUint(0, 0)` returns `'0'`, `writeUint(1, 64)` succeeds.

- [ ] **Step 3: Create `src/Spec.php`**

```php
<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf;

/**
 * Field bounds defined by the IAB TCF v2 Consent String and Vendor List
 * Formats specification. Single source of truth — validation everywhere else
 * in this package refers to these constants rather than repeating literals.
 */
final class Spec
{
    /** Core String Version field value this package implements. */
    public const CORE_STRING_VERSION = 2;

    /** CmpId, CmpVersion, VendorListVersion are 12-bit fields. */
    public const MAX_CMP_ID = 4095;
    public const MAX_CMP_VERSION = 4095;
    public const MAX_VENDOR_LIST_VERSION = 4095;

    /** ConsentScreen and TcfPolicyVersion are 6-bit fields. */
    public const MAX_CONSENT_SCREEN = 63;
    public const MAX_TCF_POLICY_VERSION = 63;

    /** PurposesConsent / PurposesLITransparency are 24-bit bitfields, ids are 1-based. */
    public const MAX_PURPOSE_ID = 24;

    /** SpecialFeatureOptIns is a 12-bit bitfield, ids are 1-based. */
    public const MAX_SPECIAL_FEATURE_ID = 12;

    /** MaxVendorId is a 16-bit field and vendor ids are 1-based, so this also
     *  caps how many distinct ids any valid vendor section can contain. */
    public const MIN_VENDOR_ID = 1;
    public const MAX_VENDOR_ID = 65535;

    /** NumEntries in a range list is a 12-bit field. */
    public const MAX_RANGE_ENTRIES = 4095;
}
```

- [ ] **Step 4: Fix `BitWriter`**

Replace `writeUint()` and `writeIdSet()` in `src/BitWriter.php`:

```php
    public function writeUint(int $value, int $numBits): static
    {
        if ($numBits < 0 || $numBits > 63) {
            throw new InvalidArgumentException("Field width must be between 0 and 63 bits, got {$numBits}.");
        }
        if ($value < 0) {
            throw new InvalidArgumentException("Value must be >= 0, got {$value}.");
        }
        // A zero-width field can only carry the value 0, and str_pad() never
        // truncates — so this case must be handled before padding.
        if ($numBits === 0) {
            if ($value !== 0) {
                throw new InvalidArgumentException("Value {$value} does not fit in 0 bits.");
            }

            return $this;
        }
        if ($numBits < 63 && $value >= (1 << $numBits)) {
            throw new InvalidArgumentException("Value {$value} does not fit in {$numBits} bits.");
        }

        $this->bits .= str_pad(decbin($value), $numBits, '0', STR_PAD_LEFT);

        return $this;
    }
```

```php
    /**
     * Writes a fixed-width bitfield where each bit position (1-based id) is set
     * to 1 if present in $ids. Used for Purposes, Special Features, etc.
     *
     * Ids outside 1..$width cannot be represented and are rejected rather than
     * dropped — silently losing a purpose or vendor id would be a consent bug.
     *
     * @param int[] $ids
     */
    public function writeIdSet(array $ids, int $width): static
    {
        foreach ($ids as $id) {
            if ($id < 1 || $id > $width) {
                throw new InvalidArgumentException(
                    "Cannot write id {$id} into a {$width}-bit field: ids must be between 1 and {$width}."
                );
            }
        }

        $set = array_flip($ids);
        for ($id = 1; $id <= $width; $id++) {
            $this->bits .= isset($set[$id]) ? '1' : '0';
        }

        return $this;
    }
```

- [ ] **Step 5: Point `RangeSection` at `Spec`**

In `src/RangeSection.php` replace line 18's literal with:

```php
    /** NumEntries is a 12-bit field — a range list beyond this many entries cannot be encoded. */
    private const MAX_RANGE_ENTRIES = Spec::MAX_RANGE_ENTRIES;
```

- [ ] **Step 6: Run the full suite**

Run: `composer test`
Expected: PASS. `RangeSection::encodeBitfield()` only ever writes ids `<= max($ids)`, so the new `writeIdSet` guard cannot fire from there — if it does, a caller passed a vendor id `< 1` and the failure is correct.

- [ ] **Step 7: Commit**

```bash
git add src/Spec.php src/BitWriter.php src/RangeSection.php tests/BitWriterReaderTest.php
git commit -m "fix!: reject unrepresentable ids instead of dropping them silently

writeIdSet() ignored ids outside 1..width, so purposesConsent [1, 25] encoded
as [1] and vendorConsents [0, 5] as [5]. Also fixes writeUint(0, 0) emitting
one bit and adds an upper bound on field width.

BREAKING CHANGE: out-of-range purpose/feature/vendor ids now throw
InvalidArgumentException instead of being dropped."
```

---

### Task 4: Bound range-list decoding (the DoS fix)

Fixes F1 and F6. This is the highest-priority change in the plan.

**Files:**
- Modify: `src/RangeSection.php:86-100` (`decodeRangeList`), `:45-55` (`decode`), `:112-118` (`normalize`)
- Test: `tests/RangeSectionTest.php` (append)

**Interfaces:**
- Consumes: `Spec::MIN_VENDOR_ID`, `Spec::MAX_VENDOR_ID` (Task 3); `Exception\InvalidTcStringException` (Task 2)
- Produces: `RangeSection::decodeRangeList()` returns a sorted, de-duplicated `int[]` and throws `InvalidTcStringException` on hostile input.

The bound is not arbitrary: vendor ids are unique and 16-bit, so **no valid range list can expand to more than 65535 ids**. Checking a running total *before* expanding each entry bounds the work, not just the result — which is what actually stops the attack.

- [ ] **Step 1: Write the failing tests**

Append to `tests/RangeSectionTest.php` (inside the class):

```php
    private static function hostileRangeList(int $entries): string
    {
        // Each entry claims the full 1..65535 span, so a naive decoder expands
        // to entries * 65535 array elements from a payload of a few KB.
        $writer = new \Flenczewski\IabTcf\BitWriter();
        $writer->writeUint($entries, 12);
        for ($i = 0; $i < $entries; $i++) {
            $writer->writeBool(true)->writeUint(1, 16)->writeUint(65535, 16);
        }

        return $writer->toBitString();
    }

    public function testRejectsRangeListThatWouldExpandBeyondTheVendorIdSpace(): void
    {
        $this->expectException(\Flenczewski\IabTcf\Exception\InvalidTcStringException::class);
        $this->expectExceptionMessage('more than 65535');

        RangeSection::decodeRangeList(new BitReader(self::hostileRangeList(500)));
    }

    public function testHostileRangeListIsRejectedQuicklyAndCheaply(): void
    {
        $before = memory_get_usage();
        $start = microtime(true);

        try {
            RangeSection::decodeRangeList(new BitReader(self::hostileRangeList(500)));
            self::fail('Expected the hostile payload to be rejected.');
        } catch (\Flenczewski\IabTcf\Exception\InvalidTcStringException) {
            // expected
        }

        self::assertLessThan(0.1, microtime(true) - $start, 'Rejection must be fast.');
        self::assertLessThan(2_000_000, memory_get_usage() - $before, 'Rejection must not allocate.');
    }

    public function testRejectsRangeWithStartAboveEnd(): void
    {
        $writer = new \Flenczewski\IabTcf\BitWriter();
        $writer->writeUint(1, 12)->writeBool(true)->writeUint(9, 16)->writeUint(2, 16);

        $this->expectException(\Flenczewski\IabTcf\Exception\InvalidTcStringException::class);

        RangeSection::decodeRangeList(new BitReader($writer->toBitString()));
    }

    public function testRejectsVendorIdZero(): void
    {
        $writer = new \Flenczewski\IabTcf\BitWriter();
        $writer->writeUint(1, 12)->writeBool(false)->writeUint(0, 16);

        $this->expectException(\Flenczewski\IabTcf\Exception\InvalidTcStringException::class);

        RangeSection::decodeRangeList(new BitReader($writer->toBitString()));
    }

    public function testOverlappingRangesDecodeToASortedUniqueList(): void
    {
        $writer = new \Flenczewski\IabTcf\BitWriter();
        $writer->writeUint(2, 12);
        $writer->writeBool(true)->writeUint(1, 16)->writeUint(5, 16);
        $writer->writeBool(true)->writeUint(3, 16)->writeUint(7, 16);

        self::assertSame(
            [1, 2, 3, 4, 5, 6, 7],
            RangeSection::decodeRangeList(new BitReader($writer->toBitString()))
        );
    }
```

- [ ] **Step 2: Run and watch them fail**

Run: `composer test -- --filter RangeSectionTest`
Expected: FAIL. The hostile-payload test will allocate ~512 MB and pass no assertions; the overlap test returns `[1,2,3,4,5,3,4,5,6,7]`.

> If your machine cannot spare 512 MB, run only `testRejectsRangeWithStartAboveEnd` first and implement Step 3 before running the two hostile-payload tests.

- [ ] **Step 3: Implement bounded, validating decoding**

In `src/RangeSection.php` add `use Flenczewski\IabTcf\Exception\InvalidTcStringException;` and replace `decodeRangeList()`:

```php
    /**
     * Decodes NumEntries(12) + range entries into a sorted, de-duplicated id list.
     *
     * Entries are validated against the hard 16-bit vendor id space *before*
     * being expanded: a valid list holds distinct ids, so it can never exceed
     * Spec::MAX_VENDOR_ID of them. Without that check a few KB of attacker
     * input expands to hundreds of millions of array elements.
     *
     * @return int[]
     */
    public static function decodeRangeList(BitReader $reader): array
    {
        $numEntries = $reader->readUint(12);
        $entries = [];
        $total = 0;

        for ($i = 0; $i < $numEntries; $i++) {
            $isRange = $reader->readBool();
            $start = $reader->readUint(16);
            $end = $isRange ? $reader->readUint(16) : $start;

            if ($start < Spec::MIN_VENDOR_ID) {
                throw new InvalidTcStringException(
                    "Range entry {$i} starts at vendor id {$start}; ids start at " . Spec::MIN_VENDOR_ID . '.'
                );
            }
            if ($end < $start) {
                throw new InvalidTcStringException(
                    "Range entry {$i} ends at {$end}, before its start {$start}."
                );
            }
            if ($end > Spec::MAX_VENDOR_ID) {
                throw new InvalidTcStringException(
                    "Range entry {$i} ends at vendor id {$end}, above the maximum of " . Spec::MAX_VENDOR_ID . '.'
                );
            }

            $total += $end - $start + 1;
            if ($total > Spec::MAX_VENDOR_ID) {
                throw new InvalidTcStringException(
                    'Range list expands to more than ' . Spec::MAX_VENDOR_ID
                    . ' vendor ids, which no valid TC String can contain.'
                );
            }

            $entries[] = [$start, $end];
        }

        $ids = [];
        foreach ($entries as [$start, $end]) {
            for ($id = $start; $id <= $end; $id++) {
                $ids[] = $id;
            }
        }

        return self::normalize($ids);
    }
```

- [ ] **Step 4: Tighten `normalize()` to match its docblock**

Replace `normalize()` — the `int|string` cast turned `"abc"` into `0` (F13):

```php
    /**
     * @param int[] $ids
     * @return int[]
     */
    private static function normalize(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
```

- [ ] **Step 5: Run and verify**

Run: `composer test -- --filter RangeSectionTest`
Expected: PASS, and the hostile-payload test now completes in well under 0.1 s.

- [ ] **Step 6: Run the whole suite**

Run: `composer test`
Expected: PASS. `testFallsBackToBitfieldWhenRangeListWouldOverflowNumEntriesField` covers ids 1..9999 — well inside the new bounds — and must still pass.

- [ ] **Step 7: Commit**

```bash
git add src/RangeSection.php tests/RangeSectionTest.php
git commit -m "fix!: bound range-list decoding to stop a remote memory-exhaustion DoS

decodeRangeList() expanded every entry with no limit, so a 2.7 KB TC String
allocated 512 MB and a maximal one would exhaust memory. TC Strings come from
cookies and query strings, i.e. from untrusted input.

Entries are now validated (start >= 1, end >= start, end <= 65535) and a
running total is checked before expansion. Output is sorted and de-duplicated,
matching what the encoder produces.

BREAKING CHANGE: malformed range lists now throw InvalidTcStringException
instead of silently decoding to a partial or duplicate-laden list."
```

---

### Task 5: `TcModel` and `PublisherRestriction` valid by construction

Fixes F12. Errors currently surface only at `encode()` time as bit-level messages.

**Files:**
- Modify: `src/TcModel.php`, `src/PublisherRestriction.php`, `src/Alpha2Code.php` (add `isValid()`)
- Test: `tests/TcModelValidationTest.php` (create)

**Interfaces:**
- Consumes: `Spec::*` (Task 3), `Exception\InvalidArgumentException` (Task 2)
- Produces: `Alpha2Code::isValid(string $code): bool` — reused by `TcModel`.

- [ ] **Step 1: Write the failing test**

Create `tests/TcModelValidationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Tests;

use Flenczewski\IabTcf\Exception\InvalidArgumentException;
use Flenczewski\IabTcf\PublisherRestriction;
use Flenczewski\IabTcf\RestrictionType;
use Flenczewski\IabTcf\TcModel;
use PHPUnit\Framework\TestCase;

final class TcModelValidationTest extends TestCase
{
    /** @return iterable<string, array{\Closure, string}> */
    public static function invalidModels(): iterable
    {
        yield 'cmpId above 12 bits' => [
            static fn () => new TcModel(cmpId: 4096, cmpVersion: 1),
            'cmpId',
        ];
        yield 'negative cmpVersion' => [
            static fn () => new TcModel(cmpId: 1, cmpVersion: -1),
            'cmpVersion',
        ];
        yield 'consentScreen above 6 bits' => [
            static fn () => new TcModel(cmpId: 1, cmpVersion: 1, consentScreen: 64),
            'consentScreen',
        ];
        yield 'purpose id 25' => [
            static fn () => new TcModel(cmpId: 1, cmpVersion: 1, purposesConsent: [1, 25]),
            'purposesConsent',
        ];
        yield 'special feature id 13' => [
            static fn () => new TcModel(cmpId: 1, cmpVersion: 1, specialFeatureOptIns: [13]),
            'specialFeatureOptIns',
        ];
        yield 'vendor id 0' => [
            static fn () => new TcModel(cmpId: 1, cmpVersion: 1, vendorConsents: [0, 5]),
            'vendorConsents',
        ];
        yield 'vendor id above 16 bits' => [
            static fn () => new TcModel(cmpId: 1, cmpVersion: 1, vendorLegitimateInterests: [65536]),
            'vendorLegitimateInterests',
        ];
        yield 'three-letter language' => [
            static fn () => new TcModel(cmpId: 1, cmpVersion: 1, consentLanguage: 'ENG'),
            'consentLanguage',
        ];
        yield 'numeric publisher country' => [
            static fn () => new TcModel(cmpId: 1, cmpVersion: 1, publisherCC: 'D1'),
            'publisherCC',
        ];
        yield 'disclosed vendor id 0' => [
            static fn () => new TcModel(cmpId: 1, cmpVersion: 1, disclosedVendors: [0]),
            'disclosedVendors',
        ];
    }

    /**
     * @param \Closure(): TcModel $build
     * @dataProvider invalidModels
     */
    public function testRejectsOutOfSpecValues(\Closure $build, string $expectedFieldInMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedFieldInMessage);

        $build();
    }

    public function testAcceptsBoundaryValues(): void
    {
        $model = new TcModel(
            cmpId: 4095,
            cmpVersion: 4095,
            consentScreen: 63,
            consentLanguage: 'pl',
            vendorListVersion: 4095,
            tcfPolicyVersion: 63,
            publisherCC: 'PL',
            specialFeatureOptIns: [1, 12],
            purposesConsent: [1, 24],
            vendorConsents: [1, 65535],
        );

        self::assertSame(4095, $model->cmpId);
        self::assertSame([1, 65535], $model->vendorConsents);
    }

    public function testPublisherRestrictionRejectsPurposeIdZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('purposeId');

        new PublisherRestriction(0, RestrictionType::REQUIRE_CONSENT, [1]);
    }

    public function testPublisherRestrictionRejectsVendorIdZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('vendorIds');

        new PublisherRestriction(1, RestrictionType::REQUIRE_CONSENT, [0]);
    }
}
```

- [ ] **Step 2: Run and watch it fail**

Run: `composer test -- --filter TcModelValidationTest`
Expected: FAIL — every case constructs successfully today.

- [ ] **Step 3: Add `Alpha2Code::isValid()`**

In `src/Alpha2Code.php`, add above `encode()` and use it there:

```php
    public static function isValid(string $code): bool
    {
        return preg_match('/^[A-Za-z]{2}$/', $code) === 1;
    }
```

and change `encode()`'s guard to:

```php
        if (!self::isValid($code)) {
            throw new InvalidArgumentException("Expected a 2-letter alphabetic code, got \"{$code}\".");
        }
```

- [ ] **Step 4: Validate in `PublisherRestriction`**

Replace `src/PublisherRestriction.php` body:

```php
    /** @param int[] $vendorIds */
    public function __construct(
        public readonly int $purposeId,
        public readonly RestrictionType $type,
        public readonly array $vendorIds,
    ) {
        if ($purposeId < 1 || $purposeId > Spec::MAX_PURPOSE_ID) {
            throw new InvalidArgumentException(
                "purposeId must be between 1 and " . Spec::MAX_PURPOSE_ID . ", got {$purposeId}."
            );
        }

        foreach ($vendorIds as $vendorId) {
            if ($vendorId < Spec::MIN_VENDOR_ID || $vendorId > Spec::MAX_VENDOR_ID) {
                throw new InvalidArgumentException(
                    "vendorIds must be between " . Spec::MIN_VENDOR_ID . ' and ' . Spec::MAX_VENDOR_ID
                    . ", got {$vendorId}."
                );
            }
        }
    }
```

with `use Flenczewski\IabTcf\Exception\InvalidArgumentException;` — note `Spec` and `PublisherRestriction` share the namespace, so no import is needed for `Spec`.

- [ ] **Step 5: Validate in `TcModel`**

Add to `src/TcModel.php` a constructor body plus two private helpers:

```php
    ) {
        self::assertInRange('cmpId', $cmpId, 0, Spec::MAX_CMP_ID);
        self::assertInRange('cmpVersion', $cmpVersion, 0, Spec::MAX_CMP_VERSION);
        self::assertInRange('consentScreen', $consentScreen, 0, Spec::MAX_CONSENT_SCREEN);
        self::assertInRange('vendorListVersion', $vendorListVersion, 0, Spec::MAX_VENDOR_LIST_VERSION);
        self::assertInRange('tcfPolicyVersion', $tcfPolicyVersion, 0, Spec::MAX_TCF_POLICY_VERSION);

        foreach (['consentLanguage' => $consentLanguage, 'publisherCC' => $publisherCC] as $field => $code) {
            if (!Alpha2Code::isValid($code)) {
                throw new InvalidArgumentException(
                    "{$field} must be a 2-letter alphabetic code, got \"{$code}\"."
                );
            }
        }

        self::assertIdSet('specialFeatureOptIns', $specialFeatureOptIns, 1, Spec::MAX_SPECIAL_FEATURE_ID);
        self::assertIdSet('purposesConsent', $purposesConsent, 1, Spec::MAX_PURPOSE_ID);
        self::assertIdSet('purposesLITransparency', $purposesLITransparency, 1, Spec::MAX_PURPOSE_ID);
        self::assertIdSet('vendorConsents', $vendorConsents, Spec::MIN_VENDOR_ID, Spec::MAX_VENDOR_ID);
        self::assertIdSet(
            'vendorLegitimateInterests',
            $vendorLegitimateInterests,
            Spec::MIN_VENDOR_ID,
            Spec::MAX_VENDOR_ID
        );

        if ($disclosedVendors !== null) {
            self::assertIdSet('disclosedVendors', $disclosedVendors, Spec::MIN_VENDOR_ID, Spec::MAX_VENDOR_ID);
        }
        if ($allowedVendors !== null) {
            self::assertIdSet('allowedVendors', $allowedVendors, Spec::MIN_VENDOR_ID, Spec::MAX_VENDOR_ID);
        }

        foreach ($publisherRestrictions as $restriction) {
            if (!$restriction instanceof PublisherRestriction) {
                throw new InvalidArgumentException(
                    'publisherRestrictions must contain only PublisherRestriction instances.'
                );
            }
        }
    }

    private static function assertInRange(string $field, int $value, int $min, int $max): void
    {
        if ($value < $min || $value > $max) {
            throw new InvalidArgumentException("{$field} must be between {$min} and {$max}, got {$value}.");
        }
    }

    /** @param int[] $ids */
    private static function assertIdSet(string $field, array $ids, int $min, int $max): void
    {
        foreach ($ids as $id) {
            if (!is_int($id)) {
                throw new InvalidArgumentException("{$field} must contain only integers.");
            }
            if ($id < $min || $id > $max) {
                throw new InvalidArgumentException(
                    "{$field} contains id {$id}, which is outside the valid range {$min}..{$max}."
                );
            }
        }
    }
```

Add `use Flenczewski\IabTcf\Exception\InvalidArgumentException;` at the top.

- [ ] **Step 6: Run the full suite**

Run: `composer test`
Expected: PASS. If `TcStringRoundTripTest` or `CliTest` now fail, a fixture uses an out-of-spec value — fix the fixture, not the validation.

- [ ] **Step 7: Commit**

```bash
git add src/TcModel.php src/PublisherRestriction.php src/Alpha2Code.php tests/TcModelValidationTest.php
git commit -m "feat!: validate TcModel and PublisherRestriction at construction

Out-of-spec values previously surfaced only at encode() time as bit-level
messages, or not at all.

BREAKING CHANGE: TcModel and PublisherRestriction throw
InvalidArgumentException for values outside their TCF field bounds."
```

---

### Task 6: One catchable failure mode for decoding

Fixes F5 (unencodable models from `Alpha2Code::decode`), F8 (unhelpful publisher-restriction overflow) and F9 (no single catch).

**Files:**
- Modify: `src/Alpha2Code.php:27-33`, `src/PublisherRestrictionsCodec.php`, `src/RangeSection.php` (`encodeRangeList`), `src/TcStringDecoder.php`
- Test: `tests/TcStringRoundTripTest.php` (append)

**Interfaces:**
- Consumes: `Exception\InvalidTcStringException`, `Exception\IabTcfException` (Task 2), `Spec::CORE_STRING_VERSION` (Task 3)
- Produces: `TcStringDecoder::decode()` throws **only** `InvalidTcStringException`, always with `getPrevious()` set when it wraps a lower-level cause.

- [ ] **Step 1: Write the failing tests**

Append to `tests/TcStringRoundTripTest.php` (inside the class):

```php
    public function testEmptyStringIsRejectedAsAnInvalidTcString(): void
    {
        $this->expectException(\Flenczewski\IabTcf\Exception\InvalidTcStringException::class);

        TcStringDecoder::decode('');
    }

    public function testTruncatedStringIsRejectedAsAnInvalidTcString(): void
    {
        $full = TcStringEncoder::encode(new TcModel(cmpId: 1, cmpVersion: 1));

        $this->expectException(\Flenczewski\IabTcf\Exception\InvalidTcStringException::class);

        TcStringDecoder::decode(substr($full, 0, 5));
    }

    public function testNonBase64InputIsRejectedAsAnInvalidTcString(): void
    {
        $this->expectException(\Flenczewski\IabTcf\Exception\InvalidTcStringException::class);

        TcStringDecoder::decode('!!!!not-base64!!!!');
    }

    public function testWrappedFailuresKeepTheirOriginalCause(): void
    {
        try {
            TcStringDecoder::decode('');
            self::fail('Expected an InvalidTcStringException.');
        } catch (\Flenczewski\IabTcf\Exception\InvalidTcStringException $e) {
            self::assertInstanceOf(
                \Flenczewski\IabTcf\Exception\IabTcfException::class,
                $e->getPrevious(),
                'The underlying cause must be preserved for debugging.'
            );
        }
    }

    public function testAlpha2CodeRejectsNonLetterBitPatterns(): void
    {
        $this->expectException(\Flenczewski\IabTcf\Exception\InvalidTcStringException::class);

        \Flenczewski\IabTcf\Alpha2Code::decode(new \Flenczewski\IabTcf\BitReader('111111111111'));
    }
```

- [ ] **Step 2: Run and watch them fail**

Run: `composer test -- --filter TcStringRoundTripTest`
Expected: FAIL — `''` throws `OutOfRangeException`, garbage throws plain `InvalidArgumentException`, and `Alpha2Code::decode` returns `"\x80\x80"` without throwing.

- [ ] **Step 3: Validate in `Alpha2Code::decode()`**

```php
    public static function decode(BitReader $reader): string
    {
        $letters = '';
        foreach ([$reader->readUint(6), $reader->readUint(6)] as $position => $value) {
            if ($value > 25) {
                throw new InvalidTcStringException(
                    "Letter {$position} of a 2-letter code decoded to {$value}; only 0..25 (A..Z) are valid."
                );
            }
            $letters .= chr(ord('A') + $value);
        }

        return $letters;
    }
```

Add `use Flenczewski\IabTcf\Exception\InvalidTcStringException;`.

- [ ] **Step 4: Guard the publisher-restriction range list**

In `src/RangeSection.php` replace `encodeRangeList()` — unlike `encode()`, this format has no bitfield to fall back to, so the limit must produce a message that explains that:

```php
    public static function encodeRangeList(array $vendorIds): string
    {
        $entries = self::toRanges(self::normalize($vendorIds));
        if (count($entries) > self::MAX_RANGE_ENTRIES) {
            throw new InvalidArgumentException(sprintf(
                'This vendor id set needs %d range entries but NumEntries holds at most %d. '
                . 'Publisher restrictions are always range-encoded, so this set cannot be expressed; '
                . 'split it across restrictions or use fewer, more contiguous vendor ids.',
                count($entries),
                self::MAX_RANGE_ENTRIES,
            ));
        }

        return self::buildRangeListBits($entries);
    }
```

In `src/PublisherRestrictionsCodec.php` add at the top of `encode()`:

```php
        if (count($restrictions) > Spec::MAX_RANGE_ENTRIES) {
            throw new InvalidArgumentException(sprintf(
                'NumPubRestrictions holds at most %d restrictions, got %d.',
                Spec::MAX_RANGE_ENTRIES,
                count($restrictions),
            ));
        }
```

and in `decode()`, replace `RestrictionType::from(...)` with a wrapped version so a `\ValueError` never escapes:

```php
            $typeValue = $reader->readUint(2);
            $type = RestrictionType::tryFrom($typeValue)
                ?? throw new InvalidTcStringException("Unknown publisher restriction type {$typeValue}.");
```

- [ ] **Step 5: Funnel every decode failure**

In `src/TcStringDecoder.php`, rename the existing `decode()` to `private static function decodeSegments()`, replace its version literal with `Spec::CORE_STRING_VERSION`, and add the new public entry point:

```php
    /**
     * @throws InvalidTcStringException if the string is not a decodable TCF v2 TC String
     */
    public static function decode(string $tcString): TcModel
    {
        if ($tcString === '') {
            throw new InvalidTcStringException('TC String is empty.');
        }

        try {
            return self::decodeSegments($tcString);
        } catch (InvalidTcStringException $e) {
            throw $e;
        } catch (IabTcfException | \ValueError $e) {
            throw new InvalidTcStringException(
                "Could not decode TC String: {$e->getMessage()}",
                0,
                $e,
            );
        }
    }
```

with `use Flenczewski\IabTcf\Exception\IabTcfException;` and `use Flenczewski\IabTcf\Exception\InvalidTcStringException;`.

> Note the empty-string case still needs a `getPrevious()` for `testWrappedFailuresKeepTheirOriginalCause`. Make that test use `TcStringDecoder::decode('!!!!not-base64!!!!')` instead, and assert the empty-string case separately with a plain `expectException`. Adjust the test you wrote in Step 1 accordingly.

- [ ] **Step 6: Run the full suite**

Run: `composer test`
Expected: PASS. `testRejectsUnsupportedVersion` asserts `InvalidArgumentException`, and `InvalidTcStringException` extends it — so it still passes.

- [ ] **Step 7: Commit**

```bash
git add src/Alpha2Code.php src/RangeSection.php src/PublisherRestrictionsCodec.php src/TcStringDecoder.php tests/TcStringRoundTripTest.php
git commit -m "feat!: funnel all decode failures into InvalidTcStringException

Alpha2Code::decode() also now rejects 6-bit values above 25, which previously
produced TcModels containing non-letter country codes that encode() then
refused to re-encode.

BREAKING CHANGE: TcStringDecoder::decode() throws InvalidTcStringException for
every malformed input, instead of leaking OutOfRangeException or ValueError."
```

---

### Task 7: Validate the Global Vendor List

Fixes F3. Depends on Task 1's `failOnWarning` to be provable.

**Files:**
- Modify: `src/Gvl/Gvl.php:34-56,104`, `src/Gvl/Vendor.php:31-43`
- Test: `tests/GvlTest.php` (append)

**Interfaces:**
- Consumes: `Exception\GvlException` (Task 2)
- Produces: `Gvl::fromJson()` throws `GvlException` on malformed input; `Gvl::bundled()` is memoized.

- [ ] **Step 1: Write the failing tests**

Append to `tests/GvlTest.php` (inside the class):

```php
    /** @return iterable<string, array{string}> */
    public static function malformedPayloads(): iterable
    {
        yield 'null literal' => ['null'];
        yield 'empty array' => ['[]'];
        yield 'empty object' => ['{}'];
        yield 'scalar' => ['42'];
        yield 'not json at all' => ['<html>404</html>'];
        yield 'missing lastUpdated' => ['{"gvlSpecificationVersion":3,"vendorListVersion":1,"tcfPolicyVersion":4,"vendors":{}}'];
        yield 'empty lastUpdated' => ['{"gvlSpecificationVersion":3,"vendorListVersion":1,"tcfPolicyVersion":4,"lastUpdated":"","vendors":{}}'];
        yield 'unparseable lastUpdated' => ['{"gvlSpecificationVersion":3,"vendorListVersion":1,"tcfPolicyVersion":4,"lastUpdated":"not-a-date","vendors":{}}'];
        yield 'vendors not an object' => ['{"gvlSpecificationVersion":3,"vendorListVersion":1,"tcfPolicyVersion":4,"lastUpdated":"2026-01-01T00:00:00Z","vendors":7}'];
        yield 'vendor without a name' => ['{"gvlSpecificationVersion":3,"vendorListVersion":1,"tcfPolicyVersion":4,"lastUpdated":"2026-01-01T00:00:00Z","vendors":{"1":{"id":1}}}'];
    }

    /** @dataProvider malformedPayloads */
    public function testFromJsonRejectsMalformedPayloads(string $json): void
    {
        $this->expectException(\Flenczewski\IabTcf\Exception\GvlException::class);

        Gvl::fromJson($json);
    }

    public function testBundledIsMemoized(): void
    {
        self::assertSame(Gvl::bundled(), Gvl::bundled());
    }
```

- [ ] **Step 2: Run and watch them fail**

Run: `composer test -- --filter GvlTest`
Expected: FAIL — most payloads return a `Gvl` (with warnings, which `failOnWarning` now turns into failures), and `bundled()` returns two distinct instances.

- [ ] **Step 3: Validate in `Vendor::fromArray()`**

```php
    /** @param array<string,mixed> $data one entry from the GVL's "vendors" map */
    public static function fromArray(array $data): self
    {
        foreach (['id', 'name'] as $required) {
            if (!isset($data[$required])) {
                throw new GvlException("Vendor entry is missing the required \"{$required}\" field.");
            }
        }

        return new self(
            id: (int) $data['id'],
            name: (string) $data['name'],
            purposes: self::intList($data, 'purposes'),
            legIntPurposes: self::intList($data, 'legIntPurposes'),
            flexiblePurposes: self::intList($data, 'flexiblePurposes'),
            specialPurposes: self::intList($data, 'specialPurposes'),
            features: self::intList($data, 'features'),
            specialFeatures: self::intList($data, 'specialFeatures'),
        );
    }

    /**
     * @param array<string,mixed> $data
     * @return int[]
     */
    private static function intList(array $data, string $key): array
    {
        $value = $data[$key] ?? [];
        if (!is_array($value)) {
            throw new GvlException("Vendor field \"{$key}\" must be an array, got " . get_debug_type($value) . '.');
        }

        return array_values(array_map(intval(...), $value));
    }
```

Add `use Flenczewski\IabTcf\Exception\GvlException;`.

- [ ] **Step 4: Validate and memoize in `Gvl`**

```php
    private static ?self $bundled = null;

    public static function bundled(): self
    {
        if (self::$bundled instanceof self) {
            return self::$bundled;
        }

        $path = __DIR__ . '/../../resources/vendor-list.json';
        $json = @file_get_contents($path);
        if ($json === false) {
            throw new GvlException("Could not read the bundled Global Vendor List at {$path}.");
        }

        return self::$bundled = self::fromJson($json);
    }

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new GvlException("Global Vendor List is not valid JSON: {$e->getMessage()}", 0, $e);
        }

        if (!is_array($data) || array_is_list($data)) {
            throw new GvlException(
                'Global Vendor List must be a JSON object, got ' . get_debug_type($data) . '.'
            );
        }

        foreach (['gvlSpecificationVersion', 'vendorListVersion', 'tcfPolicyVersion', 'lastUpdated', 'vendors'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new GvlException("Global Vendor List is missing the required \"{$key}\" field.");
            }
        }

        if (!is_array($data['vendors'])) {
            throw new GvlException(
                'Global Vendor List "vendors" must be an object, got ' . get_debug_type($data['vendors']) . '.'
            );
        }

        $vendors = [];
        foreach ($data['vendors'] as $vendorData) {
            if (!is_array($vendorData)) {
                throw new GvlException('Every entry in "vendors" must be an object.');
            }
            $vendor = Vendor::fromArray($vendorData);
            $vendors[$vendor->id] = $vendor;
        }

        return new self(
            gvlSpecificationVersion: (int) $data['gvlSpecificationVersion'],
            vendorListVersion: (int) $data['vendorListVersion'],
            tcfPolicyVersion: (int) $data['tcfPolicyVersion'],
            lastUpdated: self::parseLastUpdated($data['lastUpdated']),
            vendors: $vendors,
        );
    }

    private static function parseLastUpdated(mixed $value): \DateTimeImmutable
    {
        // new DateTimeImmutable('') silently means "now", which would make a
        // corrupt list look freshly updated — reject empty input explicitly.
        if (!is_string($value) || trim($value) === '') {
            throw new GvlException('Global Vendor List "lastUpdated" must be a non-empty date string.');
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw new GvlException("Global Vendor List \"lastUpdated\" is not a valid date: \"{$value}\".", 0, $e);
        }
    }
```

Add `use Flenczewski\IabTcf\Exception\GvlException;`. Also give `narrowVendorsTo()` its missing docblock (F13):

```php
    /**
     * Returns a new Gvl containing only the given vendor ids.
     *
     * @param int[] $vendorIds
     */
    public function narrowVendorsTo(array $vendorIds): self
```

- [ ] **Step 5: Run the full suite**

Run: `composer test`
Expected: PASS, including the existing `testBundledParsesRealPackagedVendorList`.

- [ ] **Step 6: Commit**

```bash
git add src/Gvl/Gvl.php src/Gvl/Vendor.php tests/GvlTest.php
git commit -m "fix!: validate Global Vendor List payloads instead of accepting garbage

'null', '[]' and '{}' all produced a Gvl with lastUpdated silently defaulting
to now, so a corrupt list looked freshly updated. bundled() is now memoized and
reports an unreadable file properly.

BREAKING CHANGE: Gvl::fromJson() and Gvl::bundled() throw GvlException for
malformed input."
```

---

## Phase 2 — API and logic (audit 🟠🟡)

### Task 8: Bit-stable round-trip for pre-v2.3 strings

Implements decision D3, fixes F4.

**Files:**
- Modify: `src/TcStringDecoder.php` (`$disclosedVendors` initialiser), `src/TcModel.php` (docblock)
- Test: `tests/TcStringRoundTripTest.php` (append)

**Interfaces:**
- Consumes: Task 6's `decodeSegments()`
- Produces: `TcModel::$disclosedVendors === null` ⇔ the segment was absent.

- [ ] **Step 1: Write the failing test**

```php
    public function testDecodingAStringWithoutADisclosedVendorsSegmentYieldsNull(): void
    {
        $withoutSegment = TcStringEncoder::encode(
            new TcModel(cmpId: 1, cmpVersion: 1, disclosedVendors: null)
        );

        self::assertNull(TcStringDecoder::decode($withoutSegment)->disclosedVendors);
    }

    public function testPreV23StringRoundTripsUnchanged(): void
    {
        $preV23 = 'COvFyGBOvFyGBAbAAAENAPCAAOAAAAAAAAAAAEEUACCKAAA';

        self::assertSame($preV23, TcStringEncoder::encode(TcStringDecoder::decode($preV23)));
    }

    public function testEmptyDisclosedVendorsSegmentStillDecodesToAnEmptyArray(): void
    {
        $withSegment = TcStringEncoder::encode(
            new TcModel(cmpId: 1, cmpVersion: 1, disclosedVendors: [])
        );

        self::assertSame([], TcStringDecoder::decode($withSegment)->disclosedVendors);
    }
```

- [ ] **Step 2: Run and watch it fail**

Run: `composer test -- --filter TcStringRoundTripTest`
Expected: FAIL — `disclosedVendors` is `[]` not `null`, and the reference string round-trips to `...AAA.IAAA`.

- [ ] **Step 3: Change the initialiser**

In `src/TcStringDecoder::decodeSegments()` replace the block at the old lines 45-48 with:

```php
        // Absent Disclosed Vendors segment stays null so that decode()->encode()
        // reproduces the input byte-for-byte. TCF v2.3 made the segment
        // mandatory for *new* strings (TcModel defaults to []), but decoding
        // must stay backward compatible with v2.0-v2.2 strings.
        $disclosedVendors = null;
```

- [ ] **Step 4: Update the `TcModel` docblock**

In `src/TcModel.php`, amend the `@param int[]|null $disclosedVendors` line and the class docblock to state: defaults to `[]` for newly constructed models (segment emitted, v2.3-compliant); `TcStringDecoder` sets it to `null` when the decoded string carried no segment.

- [ ] **Step 5: Run the full suite**

Run: `composer test`
Expected: PASS. `testDisclosedVendorsSegmentCanBeExplicitlyOmittedForPreV23Compatibility` asserted `[]` after decoding — update it to assert `null` and rename it to say so.

- [ ] **Step 6: Commit**

```bash
git add src/TcStringDecoder.php src/TcModel.php tests/TcStringRoundTripTest.php
git commit -m "fix!: preserve the absence of a Disclosed Vendors segment

decode() normalized a missing segment to [], so re-encoding appended an empty
'.IAAA' segment and changed the string's meaning from 'unknown' to 'zero
vendors disclosed'. decode()->encode() is now bit-stable.

BREAKING CHANGE: TcModel::\$disclosedVendors is null (not []) after decoding a
TC String that has no Disclosed Vendors segment."
```

---

### Task 9: Timestamps truncate instead of rounding up

Fixes the `round()` half of F13.

**Files:**
- Modify: `src/EpochTime.php:14-23`, `src/TcStringEncoder.php`
- Test: `tests/EpochTimeTest.php` (append)

**Interfaces:**
- Consumes: `Exception\InvalidArgumentException`
- Produces: no signature change.

All five existing `EpochTime` tests use exact decisecond boundaries, so they are unaffected by round → floor. Verified against the current suite.

- [ ] **Step 1: Write the failing tests**

```php
    public function testTruncatesRatherThanRoundingIntoTheFuture(): void
    {
        // .19s past the second must become 1 decisecond, never 2.
        $dt = new \DateTimeImmutable('2024-03-15T10:30:00.190000+00:00');

        self::assertSame(
            $dt->getTimestamp() * 10 + 1,
            EpochTime::toDeciseconds($dt)
        );
    }

    public function testTruncationNeverProducesATimeAfterTheInput(): void
    {
        $dt = new \DateTimeImmutable('2024-03-15T10:30:00.990000+00:00');
        $restored = EpochTime::fromDeciseconds(EpochTime::toDeciseconds($dt));

        self::assertLessThanOrEqual($dt, $restored);
    }

    public function testTruncationIsTowardsNegativeInfinityBeforeTheEpoch(): void
    {
        $dt = new \DateTimeImmutable('1969-06-15T08:00:00.150000+00:00');
        $restored = EpochTime::fromDeciseconds(EpochTime::toDeciseconds($dt));

        self::assertLessThanOrEqual($dt, $restored);
    }
```

- [ ] **Step 2: Run and watch them fail**

Run: `composer test -- --filter EpochTimeTest`
Expected: FAIL — `round()` turns `.19` into 2 deciseconds and `.99` into a time after the input.

- [ ] **Step 3: Replace `round()` with floor division**

```php
    public static function toDeciseconds(\DateTimeImmutable $dateTime): int
    {
        // format('U.u') is unsafe here: 'u' (microseconds) is always a
        // non-negative offset *after* the 'U' second, but string-concatenating
        // it onto a negative 'U' and casting to float silently subtracts that
        // offset instead of adding it for any pre-1970 timestamp.
        $totalMicroseconds = $dateTime->getTimestamp() * 1_000_000 + (int) $dateTime->format('u');

        // Truncate toward negative infinity rather than rounding: a Created or
        // LastUpdated stamp must never land after the moment it describes.
        $deciseconds = intdiv($totalMicroseconds, 100_000);
        if ($totalMicroseconds < 0 && $totalMicroseconds % 100_000 !== 0) {
            $deciseconds--;
        }

        return $deciseconds;
    }
```

- [ ] **Step 4: Give pre-epoch dates a message about dates**

In `src/TcStringEncoder::encode()`, replace the two `writeUint(EpochTime::toDeciseconds(...), 36)` calls with a validated helper, and add it to the class:

```php
        $core->writeUint(self::decisecondsFor('created', $created), 36);
        $core->writeUint(self::decisecondsFor('lastUpdated', $lastUpdated), 36);
```

```php
    /** The Created/LastUpdated fields are unsigned, so pre-epoch dates cannot be represented. */
    private static function decisecondsFor(string $field, \DateTimeImmutable $dateTime): int
    {
        $deciseconds = EpochTime::toDeciseconds($dateTime);
        if ($deciseconds < 0) {
            throw new InvalidArgumentException(sprintf(
                '%s is %s, before the Unix epoch; the TC String Created/LastUpdated fields are unsigned '
                . 'and cannot represent dates before 1970-01-01T00:00:00Z.',
                $field,
                $dateTime->format(\DATE_ATOM),
            ));
        }

        return $deciseconds;
    }
```

with `use Flenczewski\IabTcf\Exception\InvalidArgumentException;`.

- [ ] **Step 5: Run the full suite**

Run: `composer test`
Expected: PASS, all five pre-existing `EpochTime` tests included.

- [ ] **Step 6: Commit**

```bash
git add src/EpochTime.php src/TcStringEncoder.php tests/EpochTimeTest.php
git commit -m "fix: truncate timestamps instead of rounding them into the future

Also replaces the bit-level 'Value must be >= 0' error for pre-1970 dates with
one that names the field and explains why."
```

---

### Task 10: `TcModel` is serialisable; the CLI stops owning that logic

Fixes the `tcModelToArray()` half of F13, and adds `--help`.

**Files:**
- Modify: `src/TcModel.php`, `bin/iab-tcf`
- Test: `tests/TcModelSerializationTest.php` (create), `tests/CliTest.php` (append)

**Interfaces:**
- Consumes: nothing new
- Produces: `TcModel implements \JsonSerializable`; `jsonSerialize(): array<string,mixed>` with exactly the keys the CLI used to build.

- [ ] **Step 1: Write the failing tests**

Create `tests/TcModelSerializationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Tests;

use Flenczewski\IabTcf\PublisherRestriction;
use Flenczewski\IabTcf\RestrictionType;
use Flenczewski\IabTcf\TcModel;
use PHPUnit\Framework\TestCase;

final class TcModelSerializationTest extends TestCase
{
    public function testSerializesEveryPublicField(): void
    {
        $model = new TcModel(
            cmpId: 42,
            cmpVersion: 3,
            purposesConsent: [1, 2],
            publisherRestrictions: [new PublisherRestriction(2, RestrictionType::REQUIRE_CONSENT, [7])],
            created: new \DateTimeImmutable('2024-01-02T03:04:05+00:00'),
            lastUpdated: new \DateTimeImmutable('2024-01-02T03:04:05+00:00'),
        );

        $data = $model->jsonSerialize();

        self::assertSame(2, $data['version']);
        self::assertSame(42, $data['cmpId']);
        self::assertSame('2024-01-02T03:04:05+00:00', $data['created']);
        self::assertSame([1, 2], $data['purposesConsent']);
        self::assertSame(
            [['purposeId' => 2, 'type' => 'REQUIRE_CONSENT', 'vendorIds' => [7]]],
            $data['publisherRestrictions'],
        );
        self::assertSame([], $data['disclosedVendors']);
        self::assertNull($data['allowedVendors']);
    }

    public function testEncodesDirectlyWithJsonEncode(): void
    {
        $json = json_encode(new TcModel(cmpId: 1, cmpVersion: 1), JSON_THROW_ON_ERROR);

        self::assertStringContainsString('"cmpId":1', $json);
    }
}
```

Append to `tests/CliTest.php`:

```php
    public function testHelpFlagPrintsUsageToStdoutAndSucceeds(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runCli(['--help']);

        self::assertSame(0, $exitCode, "stderr: {$stderr}");
        self::assertStringContainsString('iab-tcf decode', $stdout);
    }
```

- [ ] **Step 2: Run and watch them fail**

Run: `composer test -- --filter "TcModelSerializationTest|CliTest"`
Expected: FAIL — `jsonSerialize()` does not exist; `--help` exits 1 and writes to stderr.

- [ ] **Step 3: Implement `jsonSerialize()`**

Add `implements \JsonSerializable` to `TcModel` and this method — the body is `tcModelToArray()` from `bin/iab-tcf:38-70`, moved verbatim except that `$model->` becomes `$this->`:

```php
    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'version' => Spec::CORE_STRING_VERSION,
            'created' => $this->created?->format(\DATE_ATOM),
            'lastUpdated' => $this->lastUpdated?->format(\DATE_ATOM),
            'cmpId' => $this->cmpId,
            'cmpVersion' => $this->cmpVersion,
            'consentScreen' => $this->consentScreen,
            'consentLanguage' => $this->consentLanguage,
            'vendorListVersion' => $this->vendorListVersion,
            'tcfPolicyVersion' => $this->tcfPolicyVersion,
            'isServiceSpecific' => $this->isServiceSpecific,
            'useNonStandardStacks' => $this->useNonStandardStacks,
            'purposeOneTreatment' => $this->purposeOneTreatment,
            'publisherCC' => $this->publisherCC,
            'specialFeatureOptIns' => $this->specialFeatureOptIns,
            'purposesConsent' => $this->purposesConsent,
            'purposesLITransparency' => $this->purposesLITransparency,
            'vendorConsents' => $this->vendorConsents,
            'vendorLegitimateInterests' => $this->vendorLegitimateInterests,
            'publisherRestrictions' => array_map(
                static fn (PublisherRestriction $r): array => [
                    'purposeId' => $r->purposeId,
                    'type' => $r->type->name,
                    'vendorIds' => $r->vendorIds,
                ],
                $this->publisherRestrictions,
            ),
            'disclosedVendors' => $this->disclosedVendors,
            'allowedVendors' => $this->allowedVendors,
        ];
    }
```

- [ ] **Step 4: Slim down the CLI**

In `bin/iab-tcf`: delete `tcModelToArray()` entirely, change `runDecode()` to `echo json_encode($model, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";`, catch `IabTcfException` rather than `\Throwable`, and extend the command dispatch:

```php
exit(match ($command) {
    'decode' => runDecode($argv[2] ?? null),
    'update-gvl' => runUpdateGvl($argv[2] ?? null),
    'help', '--help', '-h' => (function (): int {
        fwrite(STDOUT, usage());

        return 0;
    })(),
    default => (function (): int {
        fwrite(STDERR, usage());

        return 1;
    })(),
});
```

Also guard the `update-gvl` summary line, which currently assumes the key exists:

```php
    $version = is_array($decoded) && isset($decoded['vendorListVersion']) ? $decoded['vendorListVersion'] : 'unknown';
    fwrite(STDOUT, 'Wrote ' . strlen($formatted) . " bytes to {$target} (vendorListVersion={$version}).\n");
```

- [ ] **Step 5: Run the full suite**

Run: `composer test`
Expected: PASS — the existing CLI tests assert the same JSON keys, which are unchanged.

- [ ] **Step 6: Commit**

```bash
git add src/TcModel.php bin/iab-tcf tests/TcModelSerializationTest.php tests/CliTest.php
git commit -m "feat: make TcModel JsonSerializable and add iab-tcf --help

The model→array mapping lived in the CLI script where library consumers could
not reach it."
```

---

### Task 11: Safe, injectable HTTP for the GVL fetcher

Implements decision D2, fixes F11.

**Files:**
- Create: `src/Http/HttpClient.php`, `src/Http/StreamHttpClient.php`, `src/Http/Psr18HttpClient.php`
- Modify: `src/Gvl/GvlFetcher.php`, `composer.json` (`require-dev`, `suggest`)
- Test: `tests/GvlFetcherTest.php` (rewrite injection), `tests/Http/StreamHttpClientTest.php` (create)

**Interfaces:**
- Consumes: `Exception\GvlException` (Task 2)
- Produces: `Flenczewski\IabTcf\Http\HttpClient::get(string $url): string`; `GvlFetcher::__construct(?HttpClient $client = null)`.

- [ ] **Step 1: Add the PSR dev dependencies**

```bash
composer require --dev --no-interaction psr/http-client psr/http-factory psr/http-message
```

Then add to `composer.json`:

```json
    "suggest": {
        "psr/http-client": "To fetch the Global Vendor List through your own PSR-18 client instead of the bundled stream client"
    }
```

- [ ] **Step 2: Write the failing tests**

Create `tests/Http/StreamHttpClientTest.php`:

```php
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
```

Rewrite the injection in `tests/GvlFetcherTest.php` — replace every `new GvlFetcher(fn (string $url) => ...)` with an `HttpClient` double:

```php
    private static function clientReturning(string $body, ?string &$capturedUrl = null): HttpClient
    {
        return new class ($body, $capturedUrl) implements HttpClient {
            public function __construct(private string $body, private ?string &$capturedUrl)
            {
            }

            public function get(string $url): string
            {
                $this->capturedUrl = $url;

                return $this->body;
            }
        };
    }

    private static function failingClient(): HttpClient
    {
        return new class implements HttpClient {
            public function get(string $url): string
            {
                throw new GvlException("Failed to fetch the Global Vendor List from {$url}.");
            }
        };
    }
```

with `use Flenczewski\IabTcf\Http\HttpClient;` and `use Flenczewski\IabTcf\Exception\GvlException;`. Keep every existing assertion; only the injection mechanism changes.

- [ ] **Step 3: Run and watch them fail**

Run: `composer test -- --filter "StreamHttpClientTest|GvlFetcherTest"`
Expected: FAIL — `Flenczewski\IabTcf\Http\HttpClient` does not exist.

- [ ] **Step 4: Create the HTTP seam**

`src/Http/HttpClient.php`:

```php
<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Http;

/**
 * Minimal GET-only HTTP seam. Deliberately not PSR-18: this package has no
 * runtime dependencies, and Psr18HttpClient adapts a PSR-18 client onto this
 * interface for consumers who already have one.
 */
interface HttpClient
{
    /**
     * @throws \Flenczewski\IabTcf\Exception\GvlException on transport failure or a non-2xx status
     */
    public function get(string $url): string;
}
```

`src/Http/StreamHttpClient.php`:

```php
<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Http;

use Flenczewski\IabTcf\Exception\GvlException;

/**
 * Zero-dependency default client built on PHP's HTTP stream wrapper.
 *
 * Unlike a bare file_get_contents() call it sets a timeout, does not follow
 * redirects, verifies TLS, checks the response status and converts warnings
 * into exceptions.
 */
final class StreamHttpClient implements HttpClient
{
    public function __construct(
        private readonly float $timeoutSeconds = 10.0,
        private readonly string $userAgent = 'php-iab-tcf (+https://github.com/flenczewski/php-iab-tcf)',
    ) {
    }

    public function get(string $url): string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->timeoutSeconds,
                'follow_location' => 0,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\nUser-Agent: {$this->userAgent}\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new GvlException(
                "HTTP request to {$url} failed: the host is unreachable, the request timed out, "
                . 'or allow_url_fopen is disabled.'
            );
        }

        /** @var list<string> $http_response_header set by the HTTP stream wrapper */
        $headers = $http_response_header ?? [];
        $status = self::statusFrom($headers);
        if ($headers !== [] && ($status < 200 || $status >= 300)) {
            throw new GvlException("HTTP request to {$url} returned status {$status}.");
        }

        return $body;
    }

    /** @param list<string> $headers */
    private static function statusFrom(array $headers): int
    {
        $status = 0;
        foreach ($headers as $header) {
            // Take the last status line so redirects/100-continue do not win.
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        return $status;
    }
}
```

`src/Http/Psr18HttpClient.php`:

```php
<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Http;

use Flenczewski\IabTcf\Exception\GvlException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/**
 * Adapts a PSR-18 client onto {@see HttpClient}.
 *
 * Requires psr/http-client and psr/http-factory, which this package only
 * suggests — instantiate this class solely if your project already has them.
 */
final class Psr18HttpClient implements HttpClient
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
    ) {
    }

    public function get(string $url): string
    {
        $request = $this->requestFactory->createRequest('GET', $url)
            ->withHeader('Accept', 'application/json');

        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new GvlException("HTTP request to {$url} failed: {$e->getMessage()}", 0, $e);
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new GvlException("HTTP request to {$url} returned status {$status}.");
        }

        return (string) $response->getBody();
    }
}
```

- [ ] **Step 5: Rewire `GvlFetcher`**

```php
    public function __construct(private readonly ?HttpClient $httpClient = null)
    {
    }

    private function get(string $url): string
    {
        return ($this->httpClient ?? new StreamHttpClient())->get($url);
    }
```

Delete the old `$httpGet` property, its `??=` initialiser and the `$result === false` branch — `HttpClient::get()` throws instead of returning `false`. Update the class docblock to mention the injectable client.

- [ ] **Step 6: Run the full suite**

Run: `composer test`
Expected: PASS. The `.invalid` DNS test must fail fast — if it hangs, `timeoutSeconds` is not being applied.

- [ ] **Step 7: Commit**

```bash
git add src/Http src/Gvl/GvlFetcher.php composer.json composer.lock tests/Http tests/GvlFetcherTest.php
git commit -m "feat!: harden GVL fetching with a timeout, status checks and an injectable client

file_get_contents() on a URL had no timeout, ignored HTTP status codes and
leaked warnings. GvlFetcher now takes an HttpClient; StreamHttpClient is the
zero-dependency default and Psr18HttpClient adapts an existing PSR-18 client.

BREAKING CHANGE: GvlFetcher::__construct() takes ?HttpClient instead of a
callable."
```

---

## Phase 3 — Prove conformance, not self-consistency

### Task 12: External reference vectors

Fixes the core of F14. Until this exists, nothing in the suite proves the codec matches the spec rather than itself.

**Files:**
- Create: `tests/ReferenceVectorTest.php`
- Modify: `tests/CliTest.php:35` (rename the misleading test)

**Interfaces:**
- Consumes: `TcStringDecoder`, `TcStringEncoder`
- Produces: nothing consumed by later tasks.

The vector below was produced by a third-party CMP, not by this library, and every expected value was read off an independent decode — do **not** regenerate the expectations with this package.

- [ ] **Step 1: Write the test**

Create `tests/ReferenceVectorTest.php`:

```php
<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Tests;

use Flenczewski\IabTcf\TcStringDecoder;
use Flenczewski\IabTcf\TcStringEncoder;
use PHPUnit\Framework\TestCase;

/**
 * Conformance tests against TC Strings produced by *other* implementations.
 *
 * Every other test in this suite is a round trip through this package, so it
 * would pass even if this package misread the spec. These vectors are the only
 * thing that catches that class of bug — never regenerate the expected values
 * with TcStringEncoder.
 */
final class ReferenceVectorTest extends TestCase
{
    /** A v2.0-era string from a production CMP: no Disclosed Vendors segment. */
    private const CMP_27_VECTOR = 'COvFyGBOvFyGBAbAAAENAPCAAOAAAAAAAAAAAEEUACCKAAA';

    public function testDecodesAProductionCmpString(): void
    {
        $model = TcStringDecoder::decode(self::CMP_27_VECTOR);

        self::assertSame(27, $model->cmpId);
        self::assertSame(0, $model->cmpVersion);
        self::assertSame(0, $model->consentScreen);
        self::assertSame('EN', $model->consentLanguage);
        self::assertSame(15, $model->vendorListVersion);
        self::assertSame(2, $model->tcfPolicyVersion);
        self::assertFalse($model->isServiceSpecific);
        self::assertFalse($model->useNonStandardStacks);
        self::assertFalse($model->purposeOneTreatment);
        self::assertSame('AA', $model->publisherCC);
        self::assertSame([], $model->specialFeatureOptIns);
        self::assertSame([1, 2, 3], $model->purposesConsent);
        self::assertSame([], $model->purposesLITransparency);
        self::assertSame([2, 6, 8], $model->vendorConsents);
        self::assertSame([2, 6, 8], $model->vendorLegitimateInterests);
        self::assertSame([], $model->publisherRestrictions);
        self::assertNull($model->disclosedVendors, 'This vector carries no Disclosed Vendors segment.');
        self::assertNull($model->allowedVendors);
    }

    public function testDecodesTheTimestampsToDecisecondPrecision(): void
    {
        $model = TcStringDecoder::decode(self::CMP_27_VECTOR);

        self::assertNotNull($model->created);
        self::assertNotNull($model->lastUpdated);
        self::assertSame(
            '2020-02-20T23:57:39.300000+00:00',
            $model->created->format('Y-m-d\TH:i:s.uP')
        );
        self::assertSame(
            '2020-02-20T23:57:39.300000+00:00',
            $model->lastUpdated->format('Y-m-d\TH:i:s.uP')
        );
    }

    public function testReEncodesTheVectorBitForBit(): void
    {
        self::assertSame(
            self::CMP_27_VECTOR,
            TcStringEncoder::encode(TcStringDecoder::decode(self::CMP_27_VECTOR)),
            'A decode/encode cycle must reproduce a third-party string exactly.'
        );
    }
}
```

- [ ] **Step 2: Run it**

Run: `composer test -- --filter ReferenceVectorTest`
Expected: PASS. All three assertions were verified against the audited code, so a failure here means an earlier task regressed the codec — bisect before continuing.

- [ ] **Step 3: Add more vectors from the reference implementation**

Pull additional strings and their expected fields from the JS reference implementation's fixtures:

```bash
git clone --depth 1 https://github.com/InteractiveAdvertisingBureau/iabtcf-es /tmp/iabtcf-es
grep -rn "CP\|CO" /tmp/iabtcf-es/modules/core/test --include=*.ts | head -40
```

For each string you add, take the expected field values **from that repository's own assertions**, not from running this package. Prefer vectors that exercise what the current one does not: a Disclosed Vendors segment, an Allowed Vendors segment, publisher restrictions, and a bitfield-encoded vendor section.

If a vector disagrees with this implementation, stop and treat it as a codec bug — that is exactly what this task exists to find.

- [ ] **Step 4: Rename the misleading CLI test**

`tests/CliTest.php:35` is called `testDecodeKnownTcString` but builds its input at line 36. Rename it to `testDecodeRoundTripsAnEncodedModel`, and add a CLI test that feeds it `ReferenceVectorTest::CMP_27_VECTOR` and asserts `cmpId === 27`.

- [ ] **Step 5: Run the full suite**

Run: `composer test`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add tests/ReferenceVectorTest.php tests/CliTest.php
git commit -m "test: assert conformance against third-party TC Strings

Every existing test round-tripped through this package, so a systematic
misreading of the spec would have passed the whole suite."
```

---

### Task 13: Hostile and boundary input

Completes F14.

**Files:**
- Create: `tests/MalformedInputTest.php`, `tests/SpecBoundaryTest.php`

**Interfaces:**
- Consumes: everything from Phases 1-2
- Produces: nothing.

- [ ] **Step 1: Write the malformed-input tests**

Create `tests/MalformedInputTest.php`:

```php
<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Tests;

use Flenczewski\IabTcf\Exception\InvalidTcStringException;
use Flenczewski\IabTcf\TcModel;
use Flenczewski\IabTcf\TcStringDecoder;
use Flenczewski\IabTcf\TcStringEncoder;
use PHPUnit\Framework\TestCase;

final class MalformedInputTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function malformedStrings(): iterable
    {
        yield 'empty' => [''];
        yield 'single dot' => ['.'];
        yield 'only separators' => ['...'];
        yield 'not base64' => ['!!!!'];
        yield 'whitespace' => ['   '];
        yield 'one character' => ['C'];
        yield 'version 1 core string' => ['BOEFEAyOEFEAyAHABDENAI4AAAB9vABAASA'];
    }

    /** @dataProvider malformedStrings */
    public function testMalformedStringsThrowInvalidTcStringException(string $input): void
    {
        $this->expectException(InvalidTcStringException::class);

        TcStringDecoder::decode($input);
    }

    public function testEveryTruncationOfAValidStringIsRejectedOrDecodes(): void
    {
        $valid = TcStringEncoder::encode(new TcModel(
            cmpId: 7,
            cmpVersion: 1,
            purposesConsent: [1, 2, 3],
            vendorConsents: [1, 2, 3],
        ));

        // No truncation may crash with anything other than our own exception.
        for ($length = 1; $length < strlen($valid); $length++) {
            try {
                TcStringDecoder::decode(substr($valid, 0, $length));
            } catch (InvalidTcStringException) {
                continue;
            }
        }

        $this->expectNotToPerformAssertions();
    }

    public function testUnknownSegmentTypesAreIgnoredNotFatal(): void
    {
        $valid = TcStringEncoder::encode(new TcModel(cmpId: 7, cmpVersion: 1));
        // Segment type 3 (Publisher TC) is documented as unsupported and skipped.
        $withPublisherTc = $valid . '.' . (new \Flenczewski\IabTcf\BitWriter())
            ->writeUint(3, 3)
            ->writeUint(0, 13)
            ->toBase64Url();

        self::assertSame(7, TcStringDecoder::decode($withPublisherTc)->cmpId);
    }
}
```

- [ ] **Step 2: Run and fix what it finds**

Run: `composer test -- --filter MalformedInputTest`
Expected: PASS. Any case that escapes as a `TypeError`, `ValueError` or `Error` is a gap in Task 6's funnel — widen the `catch` there rather than weakening the test.

- [ ] **Step 3: Write the boundary tests**

Create `tests/SpecBoundaryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Tests;

use Flenczewski\IabTcf\RangeSection;
use Flenczewski\IabTcf\BitReader;
use Flenczewski\IabTcf\Spec;
use Flenczewski\IabTcf\TcModel;
use Flenczewski\IabTcf\TcStringDecoder;
use Flenczewski\IabTcf\TcStringEncoder;
use PHPUnit\Framework\TestCase;

final class SpecBoundaryTest extends TestCase
{
    public function testHighestVendorIdRoundTrips(): void
    {
        $model = new TcModel(cmpId: 1, cmpVersion: 1, vendorConsents: [1, Spec::MAX_VENDOR_ID]);

        self::assertSame(
            [1, Spec::MAX_VENDOR_ID],
            TcStringDecoder::decode(TcStringEncoder::encode($model))->vendorConsents,
        );
    }

    public function testAllPurposesAndSpecialFeaturesRoundTrip(): void
    {
        $model = new TcModel(
            cmpId: Spec::MAX_CMP_ID,
            cmpVersion: Spec::MAX_CMP_VERSION,
            consentScreen: Spec::MAX_CONSENT_SCREEN,
            vendorListVersion: Spec::MAX_VENDOR_LIST_VERSION,
            tcfPolicyVersion: Spec::MAX_TCF_POLICY_VERSION,
            specialFeatureOptIns: range(1, Spec::MAX_SPECIAL_FEATURE_ID),
            purposesConsent: range(1, Spec::MAX_PURPOSE_ID),
            purposesLITransparency: range(1, Spec::MAX_PURPOSE_ID),
        );

        $decoded = TcStringDecoder::decode(TcStringEncoder::encode($model));

        self::assertSame(range(1, Spec::MAX_PURPOSE_ID), $decoded->purposesConsent);
        self::assertSame(range(1, Spec::MAX_SPECIAL_FEATURE_ID), $decoded->specialFeatureOptIns);
        self::assertSame(Spec::MAX_CMP_ID, $decoded->cmpId);
    }

    public function testMaximumRangeEntryCountEncodes(): void
    {
        // Spec::MAX_RANGE_ENTRIES disjoint single-id ranges is the largest
        // range list the 12-bit NumEntries field can describe.
        $ids = [];
        for ($i = 0; $i < Spec::MAX_RANGE_ENTRIES; $i++) {
            $ids[] = $i * 2 + 1;
        }

        $bits = RangeSection::encodeRangeList($ids);

        self::assertSame($ids, RangeSection::decodeRangeList(new BitReader($bits)));
    }

    public function testOneRangeEntryTooManyIsRejectedWithAnExplanation(): void
    {
        $ids = [];
        for ($i = 0; $i < Spec::MAX_RANGE_ENTRIES + 1; $i++) {
            $ids[] = $i * 2 + 1;
        }

        $this->expectException(\Flenczewski\IabTcf\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('NumEntries');

        RangeSection::encodeRangeList($ids);
    }
}
```

- [ ] **Step 4: Run the full suite**

Run: `composer test`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add tests/MalformedInputTest.php tests/SpecBoundaryTest.php
git commit -m "test: cover malformed input, truncation and spec boundary values"
```

---

## Phase 4 — Packaging, automation, documentation

### Task 14: Package metadata and distribution hygiene

Fixes the `composer.json` and missing-file halves of F15.

**Files:**
- Modify: `composer.json`
- Create: `.gitattributes`, `.editorconfig`

**Interfaces:** none.

- [ ] **Step 1: Write the failing test**

Create `tests/PackageTest.php`:

```php
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
                "{$path}",
                $gitattributes,
                "{$path} should be export-ignored so composer require does not ship it.",
            );
        }
    }
}
```

- [ ] **Step 2: Run and watch it fail**

Run: `composer test -- --filter PackageTest`
Expected: FAIL — `ext-json` missing, `.gitattributes` does not exist.

- [ ] **Step 3: Complete `composer.json`**

```json
    "require": {
        "php": ">=8.1",
        "ext-json": "*"
    },
    "authors": [
        {
            "name": "Fabian Lenczewski",
            "homepage": "https://github.com/flenczewski"
        }
    ],
    "support": {
        "issues": "https://github.com/flenczewski/php-iab-tcf/issues",
        "source": "https://github.com/flenczewski/php-iab-tcf"
    },
    "config": {
        "sort-packages": true
    },
```

Keep `minimum-stability: stable`. Add these scripts alongside `test`:

```json
        "analyse": "phpstan analyse",
        "cs": "php-cs-fixer fix --dry-run --diff",
        "cs-fix": "php-cs-fixer fix"
```

- [ ] **Step 4: Create `.gitattributes`**

```
# Keep composer dist archives to what a consumer actually needs.
/tests               export-ignore
/tools               export-ignore
/docs                export-ignore
/.github             export-ignore
/.gitattributes      export-ignore
/.gitignore          export-ignore
/.editorconfig       export-ignore
/.php-cs-fixer.dist.php export-ignore
/phpstan.neon.dist   export-ignore
/phpunit.xml.dist    export-ignore
/CONTRIBUTING.md     export-ignore

* text=auto eol=lf
*.json text eol=lf
```

- [ ] **Step 5: Create `.editorconfig`**

```
root = true

[*]
charset = utf-8
end_of_line = lf
indent_size = 4
indent_style = space
insert_final_newline = true
trim_trailing_whitespace = true

[*.md]
trim_trailing_whitespace = false

[*.{yml,yaml}]
indent_size = 2
```

- [ ] **Step 6: Verify**

Run: `composer validate --strict && composer test`
Expected: `./composer.json is valid`, then a green suite.

- [ ] **Step 7: Commit**

```bash
git add composer.json .gitattributes .editorconfig tests/PackageTest.php
git commit -m "build: declare ext-json, add package metadata and export-ignore rules

composer require previously shipped tests/ and .github/ to every consumer."
```

---

### Task 15: Static analysis and a CI matrix that matches the requirement

Fixes the CI half of F15.

**Files:**
- Create: `phpstan.neon.dist`, `.php-cs-fixer.dist.php`
- Modify: `.github/workflows/ci.yml`, `.github/workflows/update-gvl.yml`, `composer.json` (`require-dev`)

**Interfaces:** none.

- [ ] **Step 1: Install the tools**

```bash
composer require --dev --no-interaction phpstan/phpstan friendsofphp/php-cs-fixer
```

- [ ] **Step 2: Configure PHPStan at the maximum level**

Create `phpstan.neon.dist`:

```neon
parameters:
    level: max
    paths:
        - src
        - tests
        - bin
    treatPhpDocTypesAsCertain: false
```

- [ ] **Step 3: Run it and fix what it reports**

Run: `vendor/bin/phpstan analyse --no-progress`

Expect findings around: the `$http_response_header` magic variable in `StreamHttpClient` (the inline `@var` handles it), `array<int, Vendor>` shapes in `Gvl`, and `int[]` promises on `TcModel`'s promoted properties. Fix by tightening docblocks — **do not** add `@phpstan-ignore` lines or lower the level. If a genuine type hole appears, it is a real finding: fix the code.

- [ ] **Step 4: Configure the code style**

Create `.php-cs-fixer.dist.php`:

```php
<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
    ->append([__DIR__ . '/bin/iab-tcf']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'trailing_comma_in_multiline' => true,
        'single_quote' => true,
    ])
    ->setFinder($finder);
```

Run `vendor/bin/php-cs-fixer fix` and review the diff before committing.

- [ ] **Step 5: Rewrite the CI workflow**

Replace `.github/workflows/ci.yml`:

```yaml
name: CI

on:
  push:
    branches: [main]
  pull_request:

jobs:
  test:
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        php-version: ['8.1', '8.2', '8.3', '8.4', '8.5']
        dependencies: ['highest']
        include:
          - php-version: '8.1'
            dependencies: 'lowest'
    steps:
      - uses: actions/checkout@v4

      - name: Set up PHP ${{ matrix.php-version }}
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php-version }}
          extensions: dom, mbstring, xml, xmlwriter
          coverage: none

      - name: Validate composer.json
        run: composer validate --strict

      - name: Install dependencies
        uses: ramsey/composer-install@v3
        with:
          dependency-versions: ${{ matrix.dependencies }}

      - name: Run tests
        run: vendor/bin/phpunit --no-coverage

  static-analysis:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: dom, mbstring, xml, xmlwriter
          coverage: none

      - uses: ramsey/composer-install@v3

      - name: PHPStan
        run: vendor/bin/phpstan analyse --no-progress --error-format=github

      - name: Coding standards
        run: vendor/bin/php-cs-fixer fix --dry-run --diff
```

Note the CI job runs `vendor/bin/phpunit` directly, not `composer test` — the runner's Docker fallback is for local machines and would be wrong in CI.

- [ ] **Step 6: Make the GVL refresh verify itself**

In `.github/workflows/update-gvl.yml`, insert between "Refresh" and "Commit if changed":

```yaml
      - name: Verify the refreshed list still parses
        run: vendor/bin/phpunit --no-coverage --filter GvlTest
```

and add `extensions: dom, mbstring, xml, xmlwriter` to that workflow's `setup-php` step, which currently omits them.

- [ ] **Step 7: Verify everything locally**

Run: `composer validate --strict && vendor/bin/phpstan analyse --no-progress && vendor/bin/php-cs-fixer fix --dry-run --diff && composer test`
Expected: four clean runs. Paste the output — do not summarise it.

- [ ] **Step 8: Commit**

```bash
git add phpstan.neon.dist .php-cs-fixer.dist.php composer.json composer.lock .github/workflows src tests bin
git commit -m "ci: add PHPStan level max, coding standards, PHP 8.4/8.5 and lowest-deps runs

The matrix stopped at 8.3 while composer.json requires >=8.1, and the GVL
refresh workflow committed to main without verifying the list parses."
```

---

### Task 16: Documentation for 2.0.0

Fixes F16 and closes out the release.

**Files:**
- Create: `CHANGELOG.md`, `UPGRADE-2.0.md`, `SECURITY.md`, `CONTRIBUTING.md`
- Modify: `README.md`

**Interfaces:** none.

- [ ] **Step 1: Write `CHANGELOG.md`**

Use Keep a Changelog format with a single `## [2.0.0]` entry. Group the commits from Tasks 2-15 under `### Security` (the DoS fix), `### Added` (exception hierarchy, `Spec`, `HttpClient`, `JsonSerializable`, `--help`), `### Changed` / `### Fixed`, and a `### Removed` note for the callable `GvlFetcher` constructor. State plainly under Security that a maliciously crafted TC String could exhaust memory in 1.x and that upgrading is the fix.

Add a `### Known tradeoffs` note: `resources/vendor-list.json` is 908 KB and refreshed weekly, so the repository grows roughly 45 MB per year — deliberate, in exchange for offline `Gvl::bundled()`.

- [ ] **Step 2: Write `UPGRADE-2.0.md`**

One section per breaking change, each with before/after code:

1. Exceptions — catch `Flenczewski\IabTcf\Exception\IabTcfException`; `decode()` now always throws `InvalidTcStringException`.
2. Out-of-range ids now throw instead of being dropped — audit any code that passed unvalidated purpose or vendor ids.
3. `TcModel` / `PublisherRestriction` validate in the constructor.
4. `disclosedVendors` is `null` after decoding a string without the segment — `if ($model->disclosedVendors === [])` must become `=== null` where "absent" was meant.
5. `GvlFetcher::__construct()` takes `?HttpClient`, not a callable — show the anonymous-class replacement.
6. `Gvl::fromJson()` throws `GvlException` on malformed payloads.

- [ ] **Step 3: Write `SECURITY.md`**

State the supported versions (2.x), that 1.x is affected by the range-list memory-exhaustion issue and unsupported, how to report privately (GitHub Security Advisories on the repo), and a target response window. Do not include a working exploit payload — describe the class of issue and point at the fix.

- [ ] **Step 4: Write `CONTRIBUTING.md`**

Cover: `composer install`, `composer test` (and that it falls back to Docker when `ext-dom`/`ext-mbstring`/`ext-xmlwriter` are missing), `composer analyse`, `composer cs-fix`, the TDD expectation, Conventional Commits, and the rule that spec-conformance changes need a reference vector in `tests/ReferenceVectorTest.php`.

- [ ] **Step 5: Update `README.md`**

- Fix the stale claim at line 58: the 28 February 2026 deadline has passed. Rewrite in the past tense and add a source link to the IAB TCF v2.3 announcement. If you cannot find an authoritative link, soften the claim to what the spec document itself says rather than leaving an unsourced date.
- Add a **Requirements** line: PHP 8.1+, `ext-json`, no other runtime dependencies.
- Add an **Error handling** section listing `IabTcfException`, `InvalidTcStringException`, `InvalidArgumentException`, `OutOfRangeException`, `GvlException`, with a worked `try`/`catch` around `TcStringDecoder::decode()`.
- Add a **Security** note: TC Strings are untrusted input; `decode()` validates and bounds them; report issues per `SECURITY.md`.
- Document the `disclosedVendors` `null`-vs-`[]` distinction in the TCF v2.3 section (Task 8).
- Document injecting a PSR-18 client into `GvlFetcher` (Task 11).
- Replace the Docker block at lines 155-166 with one sentence naming the required extensions plus `composer test`, since the runner now handles the fallback.
- Add CI and Packagist badges at the top, and a **Versioning** section: SemVer; the public API is every non-`@internal` class under `Flenczewski\IabTcf\`; link `UPGRADE-2.0.md`.

- [ ] **Step 6: Verify every README code sample actually runs**

Extract each PHP block from the README into `/tmp/readme-check.php` and run it against the built package. A README example that throws is a documentation bug — fix the README, or the code if the example is right.

Run: `composer test && vendor/bin/phpstan analyse --no-progress`
Expected: both green.

- [ ] **Step 7: Commit and tag**

```bash
git add README.md CHANGELOG.md UPGRADE-2.0.md SECURITY.md CONTRIBUTING.md
git commit -m "docs: document 2.0.0, error handling, security policy and upgrade path"
```

Do **not** tag `v2.0.0` yourself — hand back for review first.

---

## Self-review

Checked against `docs/superpowers/specs/2026-09-07-v2-hardening-spec.md`:

| Spec item | Task |
|---|---|
| F1 DoS | 4 |
| F2 silent id loss | 3 |
| F3 GVL garbage | 7 |
| F4 round-trip | 8 |
| F5 Alpha2Code decode | 6 |
| F6 decoder normalisation | 4 |
| F7 `writeUint` | 3 |
| F8 publisher-restriction overflow | 6 |
| F9 exception interface | 2 |
| F10 static/DI (GvlFetcher seam only; codec interfaces out of scope) | 11 |
| F11 HTTP hardening | 11 |
| F12 `TcModel` validation | 5 |
| F13 `narrowVendorsTo` docblock 7 · `normalize()` cast 4 · `tcModelToArray` 10 · `round()` 9 · pre-epoch message 9 | 4, 7, 9, 10 |
| F14 reference vectors, malformed input, boundaries | 12, 13 |
| F15 packaging 14 · CI 15 | 14, 15 |
| F16 documentation | 16 |
| D1 2.0.0 BC break | commit footers throughout; 16 |
| D2 PSR-18 optional | 11 |
| D3 `disclosedVendors` null | 8 |
| D4 full scope | all |

No spec item is unassigned. Naming is consistent across tasks: `Spec::MAX_VENDOR_ID`, `Exception\InvalidTcStringException`, `Http\HttpClient::get()`, `Alpha2Code::isValid()`, `TcModel::jsonSerialize()` are each defined once and referenced with the same signature everywhere.

**Two things the executor must watch:**

1. Task 1 must land first. `failOnWarning` is what makes Task 7's malformed-GVL tests fail for the right reason — without it they pass on warnings alone.
2. Task 12's reference vector is the regression net for Tasks 2-11. If it starts failing partway through, a codec change broke conformance; bisect rather than adjusting the expected values.
