# PHP IAB TCF Consent String Decoder & Encoder

[![CI](https://github.com/flenczewski/php-iab-tcf/actions/workflows/ci.yml/badge.svg)](https://github.com/flenczewski/php-iab-tcf/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/flenczewski/php-iab-tcf.svg)](https://packagist.org/packages/flenczewski/php-iab-tcf)
[![License](https://img.shields.io/packagist/l/flenczewski/php-iab-tcf.svg)](LICENSE)

Encode and decode [IAB TCF v2](https://github.com/InteractiveAdvertisingBureau/GDPR-Transparency-and-Consent-Framework/blob/master/TCFv2/IAB%20Tech%20Lab%20-%20Consent%20string%20and%20vendor%20list%20formats%20v2.md) (GDPR Transparency & Consent Framework) TC Strings in PHP, including v2.3's mandatory Disclosed Vendors segment, plus tools for the Global Vendor List (GVL) and a CLI decoder. No runtime dependencies beyond PHP and `ext-json`.

## Install

```bash
composer require flenczewski/php-iab-tcf
```

**Requires** PHP 8.1+ and `ext-json`. No other runtime dependencies.

> Upgrading from 1.x? See [UPGRADE-2.0.md](UPGRADE-2.0.md). 2.0 fixes a
> memory-exhaustion DoS that affects every 1.x release — see [SECURITY.md](SECURITY.md).

## Usage

### Encode

```php
use Flenczewski\IabTcf\TcModel;
use Flenczewski\IabTcf\TcStringEncoder;
use Flenczewski\IabTcf\PublisherRestriction;
use Flenczewski\IabTcf\RestrictionType;

$model = new TcModel(
    cmpId: 300,
    cmpVersion: 1,
    consentScreen: 1,
    consentLanguage: 'EN',
    vendorListVersion: 175,
    tcfPolicyVersion: 5,
    isServiceSpecific: true,
    purposesConsent: [1, 2, 3, 4],
    purposesLITransparency: [2, 7],
    vendorConsents: [1, 2, 3, 500],
    vendorLegitimateInterests: [3, 4],
    publisherRestrictions: [
        new PublisherRestriction(2, RestrictionType::REQUIRE_CONSENT, [1, 2, 3]),
    ],
    disclosedVendors: [1, 2, 3, 500],
);

$tcString = TcStringEncoder::encode($model);
// "CPX...AA.IA..." — dot-separated, base64url-encoded segments
```

### Decode

```php
use Flenczewski\IabTcf\TcStringDecoder;

$model = TcStringDecoder::decode($tcString);

$model->cmpId;                // 300
$model->purposesConsent;      // [1, 2, 3, 4]
$model->vendorConsents;       // [1, 2, 3, 500]
$model->publisherRestrictions; // PublisherRestriction[]
```

## TCF v2.3

TCF v2.3 made the **Disclosed Vendors segment (segment type 1) mandatory** — it was optional in v2.0-v2.2 — to remove ambiguity around Legitimate Interest signalling. See the [IAB Tech Lab consent string specification](https://github.com/InteractiveAdvertisingBureau/GDPR-Transparency-and-Consent-Framework) for the normative text and the compliance dates that apply to your deployment; this README deliberately does not restate them, because they change and a stale date here is worse than none.

This library follows suit: `TcModel::$disclosedVendors` defaults to `[]` (an empty, but present, disclosed-vendor set), so `TcStringEncoder::encode()` **always emits the segment by default**. If you deliberately need pre-v2.3 wire compatibility (omitting the segment entirely), pass `disclosedVendors: null` explicitly.

Decoding stays backward compatible, and distinguishes the two cases:

| `$model->disclosedVendors` | Meaning |
|---|---|
| `null` | The decoded string carried **no** Disclosed Vendors segment (pre-v2.3) |
| `[]` | The segment was **present** and disclosed no vendors |

Keeping them distinct is what makes a decode/encode cycle reproduce a pre-v2.3
string byte for byte, rather than silently appending an empty segment and
changing its meaning from "unknown" to "zero vendors disclosed".

The bit layout of the Core segment itself is unchanged between v2.0 and v2.3 — only the mandatoriness of this one segment changed. This library does **not** hardcode a single "correct" `tcfPolicyVersion` for you: that value should be sourced from the Global Vendor List you're operating against (`Gvl::$tcfPolicyVersion`, see below), since it can change between GVL releases.

## Global Vendor List (GVL)

Parse, query, and cross-check consents against the [Global Vendor List](https://iabeurope.eu/vendor-list-tcf/).

### Bundled vs. live fetch

| | `Gvl::bundled()` | `GvlFetcher::fetchLatest()` |
|---|---|---|
| Network required at runtime | No | Yes |
| Speed | Instant | One HTTP request |
| Freshness | Refreshed weekly by CI (see below) — may lag by up to ~7 days | Always current |

Use `Gvl::bundled()` for most cases. Reach for `GvlFetcher` only if you specifically need the freshest possible list and can tolerate a network dependency at runtime (or want a specific archived version).

```php
use Flenczewski\IabTcf\Gvl\Gvl;
use Flenczewski\IabTcf\Gvl\GvlFetcher;

// Fast, offline, uses the copy bundled with this package (resources/vendor-list.json).
$gvl = Gvl::bundled();

// Or parse your own JSON payload (e.g. one you cached yourself):
$gvl = Gvl::fromJson(file_get_contents('/path/to/vendor-list.json'));

// Or fetch over the network:
$gvl = (new GvlFetcher())->fetchLatest();
$gvl = (new GvlFetcher())->fetchVersion(138); // a specific archived version
```

The default transport is `StreamHttpClient`: it sets a timeout, verifies TLS,
does not follow redirects, and checks the HTTP status rather than handing you a
404 page as if it were a vendor list. If your project already has a PSR-18
client, pass it in instead — `psr/http-client` is *suggested*, never required:

```php
use Flenczewski\IabTcf\Http\Psr18HttpClient;

$fetcher = new GvlFetcher(new Psr18HttpClient($psr18Client, $psr17RequestFactory));
```

### Querying

```php
$gvl->vendorListVersion;                 // int
$gvl->tcfPolicyVersion;                  // int — source of truth for TcModel::$tcfPolicyVersion
$gvl->vendors[755]->name;                // string, or check with isset()

$gvl->getVendorsWithConsentPurpose(1);   // Vendor[] — vendors that process purpose 1 under consent
$gvl->getVendorsWithLegIntPurpose(2);    // Vendor[]
$gvl->getVendorsWithFeature(1);          // Vendor[]
$gvl->getVendorsWithSpecialFeature(1);   // Vendor[]
$gvl->getVendorsWithSpecialPurpose(1);   // Vendor[]

$narrowed = $gvl->narrowVendorsTo([1, 2, 755]); // new Gvl containing only these vendor ids
```

### Cross-checking a TcModel

```php
$problems = $gvl->validateConsents($model);
// e.g. ["Vendor 65535 has consent in the TcModel but does not exist in this GVL."]
```

This is a lightweight sanity check (unknown vendor ids, vendors with consent/LI but no matching declared purpose in the GVL) — not a formal, exhaustive TCF validator.

### Keeping the bundled GVL fresh

`resources/vendor-list.json` is the full, real Global Vendor List, refreshed automatically once a week by [`.github/workflows/update-gvl.yml`](.github/workflows/update-gvl.yml), which runs `composer update-gvl` (== `bin/iab-tcf update-gvl`) and commits the result if it changed. You can run the same refresh yourself:

```bash
composer update-gvl
# or: bin/iab-tcf update-gvl [path/to/output.json]
```

## CLI

```bash
php bin/iab-tcf decode "CPX...AA.IA..."
```

Prints the decoded model as pretty-printed JSON to stdout; exits non-zero with a message on stderr for a missing/invalid argument. When this package is installed as a dependency, the command is available at `vendor/bin/iab-tcf` (Composer does not link a package's own `bin` entry into `vendor/bin` for its own repo, so inside this repo's checkout, invoke it as `php bin/iab-tcf` instead).

```bash
php bin/iab-tcf update-gvl [path]   # fetch the latest GVL and write it to `path` (default: resources/vendor-list.json)
php bin/iab-tcf --help              # usage, printed to stdout, exit 0
```

## Error handling

Every exception this package throws implements
`Flenczewski\IabTcf\Exception\IabTcfException`, so one catch covers all of
them. Concrete classes still extend their closest SPL ancestor, so existing
`catch (\InvalidArgumentException $e)` code keeps working.

| Exception | Thrown when |
|---|---|
| `InvalidTcStringException` | A TC String cannot be decoded — **the only type `TcStringDecoder::decode()` throws** |
| `InvalidArgumentException` | A value handed to the encoder or a model is outside its TCF field bounds |
| `OutOfRangeException` | A read ran past the end of a bit buffer |
| `GvlException` | The Global Vendor List could not be fetched or parsed |
| `IabTcfException` | Marker interface implemented by all of the above |

```php
use Flenczewski\IabTcf\Exception\InvalidTcStringException;

try {
    $model = TcStringDecoder::decode($_COOKIE['euconsent-v2'] ?? '');
} catch (InvalidTcStringException $e) {
    // Malformed, truncated, non-base64, wrong version, hostile — all arrive here.
    // The underlying cause is preserved: $e->getPrevious()
    $model = null;
}
```

## Security

TC Strings normally arrive from cookies and query parameters, so **treat them as
untrusted input**. `TcStringDecoder::decode()` validates structure and bounds
what it will allocate: a range list is checked against the 16-bit vendor id
space *before* it is expanded, so a small hostile string cannot inflate into a
huge array.

Version 1.x did not do this and is vulnerable to memory exhaustion. See
[SECURITY.md](SECURITY.md) for the supported versions and how to report a
vulnerability.

## Versioning

This project follows [Semantic Versioning](https://semver.org/). The public API
is every class under `Flenczewski\IabTcf\` that is not marked `@internal`.
Breaking changes are listed in [CHANGELOG.md](CHANGELOG.md), with migration
steps in [UPGRADE-2.0.md](UPGRADE-2.0.md).

## What's implemented

- Core String (segment 0): all fields per the TCF v2 Core Segment spec — version, timestamps, CMP metadata, consent language, special features, purposes consent/LI, publisher country code, vendor consents/LI (BitField or Range encoding, whichever is smaller), and Publisher Restrictions.
- Disclosed Vendors segment (segment type 1) — mandatory by default per TCF v2.3, see above.
- Allowed Vendors segment (segment type 2).
- Global Vendor List parsing, querying, and TcModel cross-checking (`Flenczewski\IabTcf\Gvl\*`).
- CLI decoder and GVL-refresh command (`bin/iab-tcf`).

## Known limitations

- **Publisher TC segment (segment type 3) is not implemented.** If present in a decoded TC String, it is silently skipped; `TcModel` has no fields for publisher-specific purposes/custom purposes. Contributions welcome.
- Only Core String **version 2** is supported (the only version defined by TCF v2.x). Decoding a v1 or other-version string throws `InvalidTcStringException`.
- `Vendor`/`Gvl` model only the fields relevant to consent validation (ids, names, purpose/feature associations) — not the full GVL schema (illustrations, `dataDeclaration`, `dataRetention`, `standardTexts`, etc). Use `GvlFetcher::fetchLatestRaw()`/`fetchVersionRaw()` if you need the untouched raw JSON.

## Testing

```bash
composer test
```

PHPUnit needs the `dom`, `mbstring`, `xmlwriter` and `tokenizer` extensions. If
your PHP lacks them, `composer test` transparently re-runs the suite in the
official `composer:2` Docker image instead, so the command works either way.
Arguments are forwarded:

```bash
composer test -- --filter ReferenceVectorTest
composer analyse   # PHPStan, level max
composer cs        # coding standards (composer cs-fix applies them)
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for the full workflow.

## License

MIT — see [LICENSE](LICENSE).
