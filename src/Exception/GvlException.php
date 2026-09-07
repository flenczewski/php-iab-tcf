<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Exception;

/** The Global Vendor List could not be fetched or parsed. */
class GvlException extends \RuntimeException implements IabTcfException
{
}
