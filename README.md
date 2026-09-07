# php-iab-tcf

Encode and decode [IAB TCF v2](https://github.com/InteractiveAdvertisingBureau/GDPR-Transparency-and-Consent-Framework/blob/master/TCFv2/IAB%20Tech%20Lab%20-%20Consent%20string%20and%20vendor%20list%20formats%20v2.md) (GDPR Transparency & Consent Framework) TC Strings in PHP. No dependencies beyond PHP itself.

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
    vendorListVersion: 145,
    tcfPolicyVersion: 4,
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

## What's implemented

- Core String (segment 0): all fields per the TCF v2 Core Segment spec — version, timestamps, CMP metadata, consent language, special features, purposes consent/LI, publisher country code, vendor consents/LI (BitField or Range encoding, whichever is smaller), and Publisher Restrictions.
- Disclosed Vendors segment (segment type 1).
- Allowed Vendors segment (segment type 2).

## Known limitations

- **Publisher TC segment (segment type 3) is not implemented.** If present in a decoded TC String, it is silently skipped; `TcModel` has no fields for publisher-specific purposes/custom purposes. Contributions welcome.
- Only Core String **version 2** is supported (the only version defined by TCF v2.x). Decoding a v1 or other-version string throws `InvalidArgumentException`.

## Testing

Run the test suite with PHPUnit:

```bash
composer install
vendor/bin/phpunit
```

## License

MIT — see [LICENSE](LICENSE).
