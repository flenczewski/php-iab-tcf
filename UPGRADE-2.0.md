# Upgrading from 1.x to 2.0

2.0 is a security and correctness release. The headline reason to upgrade is a
remote memory-exhaustion DoS in TC String decoding that affects every 1.x
version — see [CHANGELOG.md](CHANGELOG.md).

Most applications need to change little or nothing. Work through the sections
below in order; each says how to tell whether it affects you.

## 1. Exceptions are now package types

**Affects you if** you catch exceptions by exact class name.

Every exception now implements `Flenczewski\IabTcf\Exception\IabTcfException`,
and the concrete classes still extend their closest SPL ancestor — so
`catch (\InvalidArgumentException $e)` keeps working. Only exact-class matching
breaks.

```php
// Before — still works, but catches unrelated failures too
try { $model = TcStringDecoder::decode($tcString); }
catch (\Throwable $e) { /* ... */ }

// After — catch exactly this package's failures
use Flenczewski\IabTcf\Exception\IabTcfException;
use Flenczewski\IabTcf\Exception\InvalidTcStringException;

try {
    $model = TcStringDecoder::decode($tcString);
} catch (InvalidTcStringException $e) {
    // Every malformed-input failure arrives here, with the underlying
    // cause available via $e->getPrevious().
}
```

`TcStringDecoder::decode()` used to leak `OutOfRangeException`, `ValueError`
and others. It now throws only `InvalidTcStringException`.

## 2. Out-of-range ids throw instead of being silently dropped

**Affects you if** you build a `TcModel` from unvalidated input.

```php
// Before: encoded as [1] — purpose 25 vanished with no warning
// After:  throws InvalidArgumentException naming the field
new TcModel(cmpId: 1, cmpVersion: 1, purposesConsent: [1, 25]);

// Before: encoded as [5] — vendor 0 vanished
// After:  throws
new TcModel(cmpId: 1, cmpVersion: 1, vendorConsents: [0, 5]);
```

Valid ranges: purpose ids `1..24`, special feature ids `1..12`, vendor ids
`1..65535`, `cmpId`/`cmpVersion`/`vendorListVersion` `0..4095`,
`consentScreen`/`tcfPolicyVersion` `0..63`. They are exposed as constants on
`Flenczewski\IabTcf\Spec`.

If you were relying on the old behaviour to filter ids for you, filter them
explicitly before constructing the model.

## 3. `TcModel` and `PublisherRestriction` validate on construction

**Affects you if** you construct models with values that are out of spec.

Failures that previously surfaced at `encode()` time (as bit-level messages
like `"Value 99999 does not fit in 12 bits"`) now happen at construction and
name the offending field.

## 4. A missing Disclosed Vendors segment decodes to `null`, not `[]`

**Affects you if** you inspect `$model->disclosedVendors` after decoding.

```php
$model = TcStringDecoder::decode($preV23String);

// Before: [] — indistinguishable from "the segment said zero vendors"
// After:  null — the segment was absent
$model->disclosedVendors;
```

`null` means *absent*; `[]` means *present but empty*. This is what stops
`decode()` → `encode()` from appending a segment a pre-v2.3 string never had
and changing its meaning from "unknown" to "zero vendors disclosed". (It does
not make the cycle byte-exact in general — see
[Round-tripping](README.md#round-tripping).) Newly constructed models still
default to `[]`, so they keep emitting the segment as TCF v2.3 requires.

```php
// Before
if ($model->disclosedVendors === []) { /* treated "absent" and "empty" alike */ }

// After — say which one you mean
if ($model->disclosedVendors === null) { /* no segment in the string */ }
if ($model->disclosedVendors === []) { /* segment present, no vendors */ }
```

## 5. Malformed segment layouts are rejected

**Affects you if** you decode strings from a source that emits duplicate or
excessive segments.

A TC String may now carry at most four segments (core, plus at most one each of
Disclosed Vendors, Allowed Vendors and Publisher TC), and may not repeat a
segment type. Previously a repeated segment silently overwrote the earlier one,
and an arbitrary number of them was accepted — which let a caller multiply the
decoder's work without bound.

Conformant strings are unaffected; the specification never permitted either
shape.

## 6. `GvlFetcher` takes an `HttpClient`, not a callable

**Affects you if** you injected a callable, most likely in tests.

```php
// Before
$fetcher = new GvlFetcher(static fn (string $url): string => $cannedJson);

// After
use Flenczewski\IabTcf\Http\HttpClient;

$fetcher = new GvlFetcher(new class ($cannedJson) implements HttpClient {
    public function __construct(private readonly string $body) {}

    public function get(string $url): string
    {
        return $this->body;
    }
});
```

In production you usually pass nothing: the default `StreamHttpClient` now sets
a timeout, verifies TLS, refuses to follow redirects and checks the HTTP status
instead of returning a 404 body as if it were a vendor list. If your project
already has a PSR-18 client, use the adapter:

```php
use Flenczewski\IabTcf\Http\Psr18HttpClient;

$fetcher = new GvlFetcher(new Psr18HttpClient($psr18Client, $psr17RequestFactory));
```

`psr/http-client` and `psr/http-factory` are *suggested*, not required — this
package still has no runtime dependencies beyond PHP and `ext-json`.

## 7. `Gvl::fromJson()` rejects malformed payloads

**Affects you if** you feed it anything that might not be a real vendor list.

`null`, `[]`, `{}`, a scalar, an HTML error page, a missing or unparseable
`lastUpdated`, or a vendor entry without `id`/`name` now throw `GvlException`.
Previously they produced a `Gvl` whose `lastUpdated` silently defaulted to
*now*, so a corrupt list looked freshly updated.

`Gvl::bundled()` is also memoized now, so repeated calls no longer re-parse the
bundled ~908 KB JSON.
