<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Exception;

/**
 * Implemented by every exception this package throws.
 *
 * Catch this to handle "anything went wrong inside php-iab-tcf" without
 * catching unrelated SPL exceptions from your own code.
 */
interface IabTcfException extends \Throwable
{
}
