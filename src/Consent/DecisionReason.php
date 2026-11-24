<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Consent;

/** Why {@see ConsentEvaluator} allowed or denied processing. */
enum DecisionReason
{
    /** Every signal the legal basis needs is present. */
    case ALLOWED;

    /** The vendor id has no entry in the Global Vendor List. */
    case VENDOR_NOT_IN_GVL;

    /** The vendor was deleted from the Global Vendor List. */
    case VENDOR_DELETED;

    /** The vendor declares the Purpose under neither consent nor legitimate interest. */
    case PURPOSE_NOT_DECLARED;

    /** A publisher restriction disallows the Purpose for this vendor. */
    case PUBLISHER_NOT_ALLOWED;

    /**
     * A publisher restriction requires a legal basis the vendor cannot use for
     * this Purpose (it is not flexible), or two restrictions contradict each other.
     */
    case PUBLISHER_RESTRICTION_INCOMPATIBLE;

    /** The user did not consent to the Purpose. */
    case NO_PURPOSE_CONSENT;

    /** The user did not consent to the vendor. */
    case NO_VENDOR_CONSENT;

    /** The CMP did not establish transparency for legitimate interest on the Purpose, or the user objected. */
    case NO_PURPOSE_LEGITIMATE_INTEREST;

    /** The CMP did not establish legitimate interest for the vendor, or the user objected. */
    case NO_VENDOR_LEGITIMATE_INTEREST;

    /**
     * The TC String carries a Disclosed Vendors segment that does not list the
     * vendor, so the user was never shown it (TCF v2.3).
     */
    case VENDOR_NOT_DISCLOSED;

    /** The TCF policy the string declares does not allow legitimate interest for this Purpose. */
    case LEGITIMATE_INTEREST_NOT_PERMITTED;

    /** The user did not opt in to the Special Feature, or the vendor does not declare it. */
    case NO_SPECIAL_FEATURE_OPT_IN;
}
