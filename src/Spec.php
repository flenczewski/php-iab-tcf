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
