# php-iab-tcf

Encode and decode [IAB TCF v2](https://github.com/InteractiveAdvertisingBureau/GDPR-Transparency-and-Consent-Framework/blob/master/TCFv2/IAB%20Tech%20Lab%20-%20Consent%20string%20and%20vendor%20list%20formats%20v2.md) (GDPR Transparency & Consent Framework) TC Strings in PHP, including v2.3's mandatory Disclosed Vendors segment, plus tools for the Global Vendor List (GVL) and a CLI decoder. No dependencies beyond PHP itself.

## Install

```bash
composer require flenczewski/php-iab-tcf
```

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

TCF v2.3 (released June 2025) made the **Disclosed Vendors segment (segment type 1) mandatory** — previously optional in v2.0-v2.2 — to remove ambiguity around Legitimate Interest signalling. TC Strings created after 28 February 2026 without this segment are considered invalid.

This library follows suit: `TcModel::$disclosedVendors` defaults to `[]` (an empty, but present, disclosed-vendor set), so `TcStringEncoder::encode()` **always emits the segment by default**. If you deliberately need pre-v2.3 wire compatibility (omitting the segment entirely), pass `disclosedVendors: null` explicitly. `TcStringDecoder::decode()` stays backward-compatible either way: a TC String without the segment decodes with `disclosedVendors === []`, not an error.

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
// e.g. ["Vendor 99999 has consent in the TcModel but does not exist in this GVL."]
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
```

## What's implemented

- Core String (segment 0): all fields per the TCF v2 Core Segment spec — version, timestamps, CMP metadata, consent language, special features, purposes consent/LI, publisher country code, vendor consents/LI (BitField or Range encoding, whichever is smaller), and Publisher Restrictions.
- Disclosed Vendors segment (segment type 1) — mandatory by default per TCF v2.3, see above.
- Allowed Vendors segment (segment type 2).
- Global Vendor List parsing, querying, and TcModel cross-checking (`Flenczewski\IabTcf\Gvl\*`).
- CLI decoder and GVL-refresh command (`bin/iab-tcf`).

## Known limitations

- **Publisher TC segment (segment type 3) is not implemented.** If present in a decoded TC String, it is silently skipped; `TcModel` has no fields for publisher-specific purposes/custom purposes. Contributions welcome.
- Only Core String **version 2** is supported (the only version defined by TCF v2.x). Decoding a v1 or other-version string throws `InvalidArgumentException`.
- `Vendor`/`Gvl` model only the fields relevant to consent validation (ids, names, purpose/feature associations) — not the full GVL schema (illustrations, `dataDeclaration`, `dataRetention`, `standardTexts`, etc). Use `GvlFetcher::fetchLatestRaw()`/`fetchVersionRaw()` if you need the untouched raw JSON.

## Testing

This library has no runtime dependencies, but PHPUnit needs the `dom`, `mbstring`, and `xmlwriter` extensions, which may not be present on every system. If `composer install`/`vendor/bin/phpunit` fails with a missing-extension error, run the suite in a container instead:

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli bash -c "
  apt-get update -qq && apt-get install -y -qq libxml2-dev libonig-dev unzip git &&
  docker-php-ext-install dom mbstring xml xmlwriter &&
  curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer &&
  git config --global --add safe.directory /app &&
  composer install --no-interaction &&
  vendor/bin/phpunit --no-coverage
"
```

Otherwise, on a system with those extensions already available:

```bash
composer install
vendor/bin/phpunit
```

## License

MIT — see [LICENSE](LICENSE).
