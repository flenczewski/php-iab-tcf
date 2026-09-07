<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Exception;

/** A read ran past the end of a bit buffer — the input is truncated or malformed. */
class OutOfRangeException extends \OutOfRangeException implements IabTcfException
{
}
