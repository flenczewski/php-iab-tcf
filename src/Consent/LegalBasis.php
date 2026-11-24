<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Consent;

/** The legal basis under which a vendor processes personal data for a Purpose. */
enum LegalBasis
{
    case CONSENT;
    case LEGITIMATE_INTEREST;
}
