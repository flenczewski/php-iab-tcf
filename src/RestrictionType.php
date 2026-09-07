<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf;

/** How a publisher restricts a Purpose for a set of vendors (Publisher Restrictions section). */
enum RestrictionType: int
{
    case NOT_ALLOWED = 0;
    case REQUIRE_CONSENT = 1;
    case REQUIRE_LEGITIMATE_INTEREST = 2;
    case UNDEFINED = 3;
}
