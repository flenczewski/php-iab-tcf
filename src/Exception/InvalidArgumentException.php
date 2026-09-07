<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Exception;

/** A value handed to this package is outside the range the TCF spec allows. */
class InvalidArgumentException extends \InvalidArgumentException implements IabTcfException
{
}
