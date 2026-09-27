# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Planned as 2.2.0. As with 2.1.0, several inputs that were accepted are now
rejected, and the GVL queries return fewer vendors by default — see
**Changed** — so this is a minor, not a patch, release.

### Fixed

- **The encoder could produce publisher restrictions its own decoder rejects.**
  `PublisherRestrictionsCodec::decode()` caps the vendor ids the whole section
  may expand to, but `encode()` never checked the same budget — two
  restrictions over `range(1, 40000)` made a 67-character string that
  `decode()` refused. `encode()` now throws `InvalidArgumentException` instead.
- **Deleted vendors were treated as live.** The Global Vendor List keeps a
  deleted vendor's entry, with a `deletedDate`, which `Vendor` ignored: 189 of
  the 1211 vendors in the bundled list are deleted, yet
  `getVendorsWithConsentPurpose(1)` returned 136 of them and
  `validateConsents()` said nothing about consent given to one. See **Changed**
  and **Added**.
- **Two `TypeError`s escaped the `IabTcfException` contract.**
  `PublisherRestriction` did not check that `vendorIds` are integers (a numeric
  string or float failed at encode time, in `BitWriter`), and
  `EpochTime::fromDeciseconds()` overflowed into a float past
  `PHP_INT_MAX / 100000`. Both now throw `InvalidArgumentException`.
- **A GVL date could be relative or impossible.** `lastUpdated` accepted "now"
  and "tomorrow" — the very thing the empty-string check exists to prevent —
  and "2026-02-31" rolled over into March. GVL dates must now be absolute
  ISO 8601 and valid.
- **`TcModel` did not equal its own round trip.** Ids were stored as given and
  codes in the caller's case, while the wire format carries only a sorted,
  de-duplicated set and upper-case letters. `TcModel` and
  `PublisherRestriction` now store that normalised form.
- **The "too many segments" error reported a wrong count** ("has 5 segments"
  for 51), and the core segment was decoded before the count was checked.
- **`update-gvl` wrote its file non-atomically**, so an interrupted run left a
  truncated list behind, and when the package was installed as a dependency it
  defaulted to writing inside `vendor/`.
- **`StreamHttpClient` discarded why an open failed**, reporting a generic
  guess instead of PHP's message.

### Changed

- `TcStringDecoder::decode()` rejects input longer than the new
  `Spec::MAX_TC_STRING_LENGTH` (256 KiB) before decoding anything. The cap sits
  above the longest string this package can encode (~229 000 characters,
  covered by a test).
- Segment types 0 and 4–7 are rejected with `InvalidTcStringException`; they
  used to be skipped silently. Only 1–3 are defined.
- `Gvl::getVendorsWith*()` leave out vendors deleted as of the list's
  `lastUpdated`. Pass `includeDeleted: true` for the previous result.
  `Gvl::$vendors` still holds every entry.
- `Gvl::validateConsents()` reports consent or legitimate interest given to a
  deleted vendor.
- `TcModel`'s id lists are sorted and de-duplicated and its `consentLanguage` /
  `publisherCC` upper-cased; `PublisherRestriction::$vendorIds` likewise.
  Constructor signatures are unchanged.
- `update-gvl` requires an output path when run from an installed dependency.
- The `update-gvl` workflow runs the whole suite, not only `GvlTest`, before
  committing: a push made with `GITHUB_TOKEN` does not trigger CI.
- `composer.json` requires `php: ^8.1` rather than the open-ended `>=8.1`.

### Added

- `TcStringDecoder::decode()` takes an optional `$maxLength` to tighten the
  length cap (recommended for cookie input).
- `TcStringEncoder::encode()` takes an optional `$now`, stamped into
  Created/LastUpdated when the model leaves them null, for reproducible output.
- `TcModel::policyViolations()` reports what the TCF policy forbids but the
  wire format can express: legitimate interest for purpose 1 or 3–6 (purpose 1
  only under policy versions before TCF v2.2), the
  reserved `UNDEFINED` restriction type, conflicting restrictions for one
  vendor and purpose, and Created after LastUpdated.
- `Vendor::$deletedDate`, `Vendor::isDeletedAt()` and `Gvl::isDeleted()`.
- `StreamHttpClient`'s `totalTimeoutSeconds` (default 60) bounds the whole
  request; `timeoutSeconds` only ever bounded each read.
- `Psr18HttpClient`'s `maxResponseBytes` (default 64 MB) — it used to buffer
  whatever the endpoint sent.

### Performance

- Base64url↔bit conversion uses lookup tables: decoding a typical TC String
  drops from ~250 µs to ~30 µs.

### Documentation

- The README claimed the bundled GVL lags the live one by at most a week.
  Composer installs tagged releases, so it is the list as of the release you
  installed; the README and `Gvl::bundled()` now say so.
- New README sections cover caching a parsed `Gvl`, deleted vendors, the length
  cap, the encoder's clock, policy checks, model normalisation and the HTTP
  timeouts. `StreamHttpClient` documents that it fetches any stream-wrapper URL
  (`file://` included), so its URL must never come from untrusted input.
- Removed the internal planning notes under `docs/superpowers/`.

## [2.1.0] - 2026-09-12

A validation and documentation pass. No API was removed, but inputs that 2.0.0
accepted are now rejected — see **Changed**. That is a behaviour break for
callers relying on the old leniency, so this is a minor, not a patch, release.

### Fixed

- **Two-letter codes with a trailing newline were accepted and silently
  truncated.** `Alpha2Code::isValid()` anchored on `$`, which PCRE also matches
  immediately *before* a trailing newline, so `consentLanguage: "EN\n"` passed
  `TcModel`'s constructor and then encoded as `EN`. Consent metadata must never
  be repaired behind the caller's back. The same `$`-vs-`\z` bug let the GVL
  parser accept `"3\n"` as the integer `3` in every numeric field.
- **`BitReader::readUint()` returned a wrong value instead of failing for
  widths above 63 bits.** Past 63 bits `bindec()` returns a float and the cast
  yielded nonsense — 64 set bits read back as `0`. `BitWriter::writeUint()` has
  always rejected these widths; the reader now mirrors it with an
  `OutOfRangeException`. Unreachable through `TcStringDecoder` (no field is
  wider than 36 bits), but `BitReader` is public API.
- **A malformed `PurposeId` in the Publisher Restrictions section threw
  `InvalidArgumentException` instead of `InvalidTcStringException`.** Every
  other malformed field in that section already threw the latter; the value
  reached `PublisherRestriction`'s constructor unchecked.
- **A vendor entry with `"name": null` was reported as a missing field.**
  `Vendor::fromArray()` used `isset()`, which cannot tell absent from null; it
  now uses `array_key_exists()` and reports the wrong type.
- **A Global Vendor List declaring the same vendor id twice silently dropped
  one of them.** The vendor map is keyed by each entry's own id, so the second
  entry overwrote the first. It is now rejected.
- `Created`/`LastUpdated` past the 36-bit field's ceiling (~2187-10-06) failed
  with `BitWriter`'s "does not fit in 36 bits" rather than a message naming the
  field and the limit, unlike the existing pre-epoch check.
- `TcStringEncoder` duplicated the Core String version literal instead of
  referring to `Spec::CORE_STRING_VERSION`, contradicting `Spec`'s own
  single-source-of-truth docblock.
- **A timestamp large enough to overflow `EpochTime`'s microsecond arithmetic
  escaped as a raw `TypeError`.** `getTimestamp() * 1_000_000` turns into a
  float past ~9.2e12 seconds and `intdiv()` then rejects it, so a microsecond
  epoch passed where seconds were meant bypassed the "too far in the future"
  guard entirely and crashed code catching `IabTcfException`.
- **`StreamHttpClient` reserved the whole limit up front on PHP 8.1 and 8.2.**
  The cap was passed to `file_get_contents()` as `$maxlen`, which PHP allocates
  before reading anything prior to 8.3 — so a 1 MB vendor list claimed the full
  64 MB, and `maxResponseBytes: PHP_INT_MAX` died with "Out of memory". The body
  is now read in chunks, so the footprint follows what the endpoint actually
  sent and the cap is enforced as the body arrives.
- **A truncated HTTP response was accepted as a complete one.** A peer that
  hangs up mid-body ends the read exactly like a finished one, so a
  half-delivered vendor list came back looking whole. The buffered length is now
  checked against the declared `Content-Length`.
- **A GVL integer written as a string past `PHP_INT_MAX` saturated silently.**
  `"id": "99999999999999999999999"` cast to `PHP_INT_MAX` and keyed the vendor
  map there, so every consent check for that vendor answered "not on the list"
  with no error. The same value spelled as a JSON number was already rejected.
  Integer strings must now round-trip through `(int)`, which also rejects
  non-canonical spellings such as `"007"`.
- **A vendor entry with id `0` or a negative id was accepted.** Vendor ids are
  1-based. The upper end is deliberately left uncapped: an id beyond the 16-bit
  TC String field is unusable for consent checks, but not a reason to refuse the
  whole list.

### Changed

- **A vendor section's declared `MaxVendorId` is now enforced.** The spec
  defines it as the largest vendor id represented in the section, but the
  decoder read it and then ignored it, so a section declaring `MaxVendorId: 10`
  could carry a range entry for vendor 60000. Such a string is non-conformant
  and other implementations reject it; accepting it also meant re-encoding
  silently emitted a *different* `MaxVendorId` than the input carried. These
  strings now raise `InvalidTcStringException`. Publisher restriction range
  lists have no `MaxVendorId` field and are unaffected. Conformant strings —
  including every vector in the conformance suite and the full 1 208-vendor
  bundled GVL — are unaffected.
- **`GvlFetcher::urlForVersion()` rejects versions below 1** with
  `InvalidArgumentException`. A negative version previously built
  `.../vendor-list-v-1.json` and surfaced only as a remote 404.
- **`StreamHttpClient` bounds the response body it will buffer** to 64 MB by
  default, configurable via the new `maxResponseBytes` constructor argument.
  Every other path in this package caps what untrusted input can make it
  allocate; an unbounded `file_get_contents()` was the remaining gap.

### Added

- `Gvl::resetBundledCache()` (`@internal`), which drops the `Gvl::bundled()`
  memo so test cases can be isolated from one another.
- `Spec::MAX_TIMESTAMP_DECISECONDS`.
- `StreamHttpClient::DEFAULT_MAX_RESPONSE_BYTES`.

### Documentation

- **Corrected an over-strong round-trip guarantee.** The README, UPGRADE-2.0.md
  and two docblocks claimed a decode/encode cycle reproduces its input "byte for
  byte". That holds only for *canonically encoded* strings whose segments this
  package models. A new
  [Round-tripping](README.md#round-tripping) section documents the five cases
  where re-encoding legitimately differs from its input — a dropped Publisher
  TC segment, reordered segments, a re-chosen BitField/Range encoding,
  normalised range entries, and stripped over-padding — and warns against using
  a re-encoded string as a cache key or equality test for a third-party string.
  A regression test pins each case.
- "Known limitations" now links the unimplemented Publisher TC segment to its
  round-tripping consequence, and the Security section documents the two new
  bounds.

## [2.0.0] - 2026-09-08

Upgrading from 1.x? See [UPGRADE-2.0.md](UPGRADE-2.0.md).

### Security

- **Fixed a remote memory-exhaustion denial of service in TC String decoding.**
  `RangeSection::decodeRangeList()` expanded every range entry into a PHP array
  with no upper bound. A 2 752-character TC String allocated 512 MB and took
  0.98 s; a maximal one would exhaust memory outright. TC Strings arrive from
  cookies and query parameters, so this was reachable from untrusted input.
  Range entries are now validated (`start >= 1`, `end >= start`, `end <= 65535`)
  and a running total is checked *before* expansion, which bounds the work
  rather than only the result. The same payload is now rejected in 0.0004 s
  with a 0.10 MB delta.

  The budget is also **shared across the whole Publisher Restrictions
  section**. Each range list is capped individually, but `NumPubRestrictions`
  is a 12-bit field, so a per-list cap alone could be multiplied by up to 4095:
  1 769 characters expanded to 13 107 000 ids in 2.73 s and 201 MB. With the
  shared budget that payload is rejected in 0.05 s with a 0.05 MB delta.

  The same amplification existed a third way: the decoder looped over however
  many dot-separated segments the input carried, giving each a fresh budget.
  1 000 repeated Disclosed Vendors segments — 13 044 characters — burned 16.57 s
  of CPU at flat memory. A TC String now carries at most four segments and may
  not repeat a segment type, which is what the specification allows anyway.

  **All 1.x versions are affected; upgrading is the fix.**

### Performance

- Range-list decoding skips its sort/de-duplication pass when the decoded
  entries are already ascending and non-overlapping, which every conformant
  TC String is. Expanding a full 65 535-id range costs ~3.5 ms; normalising it
  cost a further ~35 ms. A single restriction spanning the whole vendor space
  now decodes in ~11 ms instead of ~58 ms, and a rejected hostile payload costs
  ~4 ms instead of ~39 ms. Out-of-order or overlapping input is still
  normalised, so the returned list is unchanged.

### Added

- `Flenczewski\IabTcf\Exception\IabTcfException`, a marker interface implemented
  by every exception this package throws, plus `InvalidArgumentException`,
  `OutOfRangeException`, `InvalidTcStringException` and `GvlException`.
- `Flenczewski\IabTcf\Spec`, a single source of truth for every TCF v2 field bound.
- `Flenczewski\IabTcf\Http\HttpClient` with a zero-dependency `StreamHttpClient`
  (timeout, TLS verification, HTTP status checks, no redirect following) and an
  optional `Psr18HttpClient` adapter for projects that already have a PSR-18 client.
- `TcModel` implements `JsonSerializable`, so the model-to-array mapping that
  previously lived inside the CLI script is now reusable by consumers.
- `iab-tcf help` / `--help` / `-h`, which print usage to stdout and exit 0.
- Conformance tests against TC Strings produced by other implementations,
  including the IAB's own `iabtcf-es`, plus malformed-input and boundary suites.
- `ext-json` is now declared in `require`; `.gitattributes` keeps `tests/`,
  `tools/` and `.github/` out of `composer require` installs.

### Changed

- **`TcStringDecoder::decode()` now throws only `InvalidTcStringException`** for
  malformed input, preserving the original cause via `getPrevious()`. It
  previously leaked `OutOfRangeException`, `ValueError` and others.
- **`TcModel` and `PublisherRestriction` validate on construction.** Out-of-spec
  values previously surfaced only at `encode()` time as bit-level messages.
- **`GvlFetcher::__construct()` takes `?HttpClient`** instead of a callable.
- `decodeRangeList()` returns a sorted, de-duplicated list, matching what the
  encoder produces.
- A repeated segment type is now rejected instead of silently overwriting the
  earlier one.
- Timestamps truncate toward the past instead of rounding, so a `Created` stamp
  can never land after the moment it describes.
- CI now covers PHP 8.1 through 8.5, runs PHPStan at level `max`, enforces
  coding standards, and verifies the refreshed GVL parses before committing it.

### Fixed

- **Silent consent-data loss.** `BitWriter::writeIdSet()` ignored ids outside
  `1..width`, so `purposesConsent: [1, 25]` round-tripped to `[1]` and
  `vendorConsents: [0, 5]` to `[5]`. Such ids now throw.
- **`Gvl::fromJson()` accepted garbage.** `null`, `[]` and `{}` all produced a
  `Gvl` whose `lastUpdated` silently defaulted to *now*, making a corrupt list
  look freshly updated. Malformed payloads now throw `GvlException`.
- **`decode()` → `encode()` was not idempotent.** A missing Disclosed Vendors
  segment was normalized to `[]`, so re-encoding appended an empty segment and
  changed the string's meaning from "unknown" to "zero vendors disclosed".
- `Alpha2Code::decode()` accepted 6-bit values above 25, producing models with
  non-letter country codes that `encode()` then refused to re-encode.
- `writeUint(0, 0)` emitted one bit instead of none, and field widths at or
  above 63 skipped the range check entirely.
- Publisher restrictions that overflow the 12-bit `NumEntries` field now report
  what went wrong instead of `"Value 4096 does not fit in 12 bits"`.
- `GvlFetcher` no longer hangs without a timeout, ignores HTTP status codes, or
  leaks PHP warnings.

### Removed

- The callable constructor argument on `GvlFetcher` (replaced by `HttpClient`).

### Known tradeoffs

- `resources/vendor-list.json` is ~908 KB and refreshed weekly by CI, so the
  repository grows roughly 45 MB per year. This is deliberate: it is what makes
  `Gvl::bundled()` work offline with no runtime network dependency.
- The Publisher TC segment (type 3) remains unimplemented and is skipped when
  decoding.

## [1.0.0]

- Initial release: TCF v2 core encode/decode, Global Vendor List tools, and CLI.
