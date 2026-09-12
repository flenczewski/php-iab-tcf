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

    /**
     * Created and LastUpdated are unsigned 36-bit deciseconds-since-epoch
     * fields, so the representable window is 1970-01-01 to roughly 2187-10-30.
     */
    public const MAX_TIMESTAMP_DECISECONDS = (1 << 36) - 1;

    /** NumEntries in a range list is a 12-bit field. */
    public const MAX_RANGE_ENTRIES = 4095;

    /**
     * Ceiling on the total vendor ids the Publisher Restrictions section may
     * expand to across ALL of its restrictions combined.
     *
     * Each individual range list is already capped at MAX_VENDOR_ID, but
     * NumPubRestrictions is a 12-bit field, so without a cumulative bound an
     * attacker can multiply that cap by 4095 and exhaust memory — the same
     * amplification the per-list bound exists to prevent.
     *
     * One whole vendor space is already far beyond any real signal: the largest
     * third-party string in our conformance suite expands to 70 ids in total,
     * the entire Global Vendor List is on the order of 1200 vendors, and even
     * restricting every one of them under all 24 purposes would need ~29 000.
     *
     * The bound is deliberately tight rather than merely finite. Rejection
     * costs whatever was expanded before the budget ran out, so a generous
     * ceiling hands an attacker a cheap way to burn CPU on every request:
     * at four vendor spaces a 220-byte string cost ~100 ms to reject, versus
     * ~25 ms here and ~16 us for a legitimate decode.
     */
    public const MAX_PUBLISHER_RESTRICTION_VENDOR_IDS = self::MAX_VENDOR_ID;

    /**
     * A TC String has a Core segment plus at most one each of Disclosed
     * Vendors (1), Allowed Vendors (2) and Publisher TC (3).
     *
     * The count is bounded, and each type may appear only once, because the
     * decoder does real work per segment: without both rules a string can
     * repeat a vendor segment thousands of times and multiply the per-segment
     * decode cost without limit.
     */
    public const MAX_SEGMENTS = 4;
}
