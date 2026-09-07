# php-iab-tcf 2.0.0 — Hardening Spec

**Status:** approved 2026-09-07
**Audit basis:** full review of `src/` (14 files), `bin/iab-tcf`, `tests/` (60 tests), CI, README.
All findings below were reproduced empirically against the code at commit `d8c3b6d`.

## Baseline: what is already correct

Do not "fix" these — they are verified working and must keep working:

- Core segment bit layout is spec-conformant. Real-world string
  `COvFyGBOvFyGBAbAAAENAPCAAOAAAAAAAAAAAEEUACCKAAA` decodes to cmpId 27,
  vendorListVersion 15, tcfPolicyVersion 2, created `2020-02-20T23:57:39.300000+00:00`,
  purposesConsent `[1,2,3]`, vendorConsents `[2,6,8]`, vendorLegitimateInterests `[2,6,8]`
  — and re-encodes to a **bit-identical** core segment.
- BitField-vs-Range size selection in `RangeSection::encode()`, including the
  `MAX_RANGE_ENTRIES` fallback.
- `EpochTime` pre-1970 handling (the floor-division comment in `EpochTime.php:29-36` is correct).
- Segment ordering (core, then type 1, then type 2) matches the spec.
- Performance is adequate: 1000 decodes = 0.025 s, 1000 encodes = 0.161 s,
  `Gvl::bundled()` = 17 ms / +1.3 MB / 1208 vendors.

## Decisions

| # | Decision | Rationale |
|---|---|---|
| D1 | Release as **2.0.0**, clean BC break | v1.0.0 is tagged; strict validation and the exception hierarchy break API. Two code paths (opt-in strict) would double the test surface. |
| D2 | `GvlFetcher` takes an **optional PSR-18 client**, with a zero-dependency `StreamHttpClient` default | Keeps the README's "no dependencies beyond PHP itself" promise true while giving PSR-18 users a supported seam. `psr/http-client` goes in `suggest` + `require-dev`, never `require`. |
| D3 | Decoder returns **`null`** for an absent Disclosed Vendors segment | Makes `decode()`→`encode()` bit-stable. `TcModel` keeps its `[]` default, so newly built models still emit the segment per v2.3. |
| D4 | Full scope: security + logic + API + tests + CI + docs | — |

## Findings to fix

### F1 — DoS in `RangeSection::decodeRangeList()` (CRITICAL)
`src/RangeSection.php:86-100` materializes every id with no bound.
Reproduced: a 2 752-char base64 payload produced 32 767 500 array elements,
0.98 s, **+512 MB**. At the 12-bit `NumEntries` maximum this is ~268 M elements / OOM.
TC Strings arrive from cookies and query strings, i.e. from attackers.

**Fix:** a valid list can contain at most 65535 distinct vendor ids, so track a
running total across entries and reject before expanding when it would exceed
`Spec::MAX_VENDOR_ID`. Also reject `start < 1`, `end < start`, `end > 65535`.
This bounds *work*, not just output, and needs no arbitrary cap.

**Deliberate leniency:** do NOT enforce `end <= maxVendorId` from the preamble.
Some real CMPs get that field wrong and rejecting would break interop; the
65535 hard format bound already caps memory.

### F2 — silent data loss in `BitWriter::writeIdSet()` (CRITICAL)
`src/BitWriter.php:42-50` ignores ids outside `1..$width`. Reproduced:
`purposesConsent: [1, 25]` round-trips to `[1]`; `vendorConsents: [0, 5]` to `[5]`.
Inconsistent with `cmpId: 99999` / vendor `70000`, which correctly throw.
A GDPR consent library must not silently drop a purpose id.

### F3 — `Gvl::fromJson()` accepts garbage (CRITICAL)
Reproduced: `"null"`, `"[]"`, `"{}"` all return a `Gvl` (PHP warnings only).
`new DateTimeImmutable("")` = *now*, so a corrupt list looks freshly updated.
`Gvl::bundled()` (`Gvl.php:36`) also ignores a `file_get_contents()` failure → `TypeError`.

### F4 — `decode()`→`encode()` not idempotent
Reproduced: the reference string round-trips to `...AAA.IAAA`. Resolved by D3.

### F5 — `Alpha2Code::decode()` yields unencodable models
`src/Alpha2Code.php:27-33` does not validate `0..25`. Reproduced: bits `111111111111`
decode to `"\x80\x80"`, which `Alpha2Code::encode()` then rejects.

### F6 — decoder does not normalize, encoder does
Reproduced: overlapping ranges 1-5 and 3-7 decode to `[1,2,3,4,5,3,4,5,6,7]`;
`start > end` (9→2) silently yields `[]`. `encode()` goes through `normalize()`, `decode()` does not.

### F7 — `writeUint(0, 0)` writes one bit
`str_pad(decbin(0), 0, ...)` returns `'0'` because `str_pad` never truncates.
Also `$numBits >= 63` skips the range check at `BitWriter.php:20` entirely.

### F8 — `PublisherRestrictionsCodec` has no `MAX_RANGE_ENTRIES` guard
`RangeSection::encode()` falls back to a bitfield; `encodeRangeList()` cannot,
and surfaces `"Value 4096 does not fit in 12 bits"` — meaningless to a caller.

### F9 — no package exception interface
Thrown today: `InvalidArgumentException`, `OutOfRangeException`, `ValueError`
(`PublisherRestrictionsCodec.php:37`), `JsonException`, `TypeError`. Consumers cannot
catch "errors from this library" — `bin/iab-tcf:82` resorts to `catch (\Throwable)`.

### F10 — static/DI inconsistency
`TcStringEncoder`/`Decoder`/`RangeSection`/`Base64Url` are static-only `final` classes;
`GvlFetcher` is instance-based with injection; `Gvl::bundled()` hides filesystem I/O.
Scope for 2.0.0: fix the `GvlFetcher` seam (D2) and memoize `bundled()`. Introducing
interfaces for the codec is explicitly **out of scope** — revisit if a real need appears (YAGNI).

### F11 — `GvlFetcher` HTTP is unsafe
`file_get_contents()` on a URL: depends on `allow_url_fopen`, no timeout (hangs up to
`default_socket_timeout`), no HTTP status check, no User-Agent, warnings leak to output.

### F12 — `TcModel` validates nothing
Errors surface only at `encode()` time as bit-level messages. A value object should be
valid by construction.

### F13 — minor
- `Gvl::narrowVendorsTo()` (`Gvl.php:104`) — only public method missing `@param int[]`.
- `RangeSection::normalize()` (`:114`) accepts `int|string` and casts; `"abc"` becomes `0`.
- `tcModelToArray()` lives in `bin/iab-tcf:38`; consumers cannot reuse it.
- `EpochTime::toDeciseconds` uses `round()` — can emit a future timestamp; spec implies truncation.
- Pre-1970 dates fail with `"Value must be >= 0, got -315360000"`, which never mentions dates.

### F14 — tests prove nothing about the spec
All 60 tests are `decode(encode(x)) == x` against this implementation.
A systematic misreading of the spec would pass the whole suite.
`CliTest::testDecodeKnownTcString` (`tests/CliTest.php:35`) does not use a known string —
it generates one at line 36. Missing: external reference vectors, malformed-input tests,
boundary tests (`maxVendorId` 65535, `NumEntries` 4095, purpose 24 vs 25).

### F15 — packaging and CI
- `composer.json`: missing `ext-json` in `require` (json_* is used), no `authors`, no `support`, no `config.sort-packages`.
- Missing: `CHANGELOG.md`, `CONTRIBUTING.md`, `SECURITY.md`, `.editorconfig`, `.gitattributes` (`composer require` currently ships `tests/` and `.github/`).
- CI: no static analysis, no code style, no `composer validate --strict`, no PHP 8.4/8.5 in the matrix (requirement is `>=8.1`), no `--prefer-lowest`.
- `update-gvl.yml` pushes to `main` without running the suite after refreshing the list.
- `phpunit.xml.dist`: no `failOnWarning`/`failOnRisky`/`failOnDeprecation` (so F3's warnings pass CI), no `cacheDirectory`.
- `resources/vendor-list.json` (908 KB) committed weekly ≈ 45 MB of git history per year.

### F16 — documentation
- README line 58 states TC Strings after **28 February 2026** without the segment are invalid — that date has passed (today is 2026-09-07) and the sentence is still in the future tense, with no source link.
- No error-handling section (which exceptions `decode()` throws).
- No untrusted-input warning (F1), no SemVer / public-API policy.
- README lines 153-166 document the author's local Docker workaround; the real requirement is just `ext-dom`, `ext-mbstring`, `ext-xmlwriter`.
- No CI/Packagist badges, minimum PHP version not stated in README.

## Out of scope

- Publisher TC segment (type 3) — remains an documented limitation.
- Codec interfaces / de-staticification (see F10).
- CLI stdin input, an `encode` CLI command.
- Compressing or unbundling `resources/vendor-list.json` — noted in the changelog as a known tradeoff only.
