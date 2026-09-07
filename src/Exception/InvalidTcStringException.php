<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Exception;

/**
 * A TC String could not be decoded. TcStringDecoder::decode() funnels every
 * internal failure into this type, so a single catch covers all of them; the
 * original cause is always available via getPrevious().
 */
class InvalidTcStringException extends InvalidArgumentException
{
}
