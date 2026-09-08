<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf;

use Flenczewski\IabTcf\Exception\InvalidArgumentException;
use Flenczewski\IabTcf\Exception\InvalidTcStringException;

/**
 * Encodes/decodes a 2-letter code (ISO 639-1 language or ISO 3166-1 country)
 * as used for ConsentLanguage and PublisherCC: 6 bits per letter, A=0..Z=25.
 */
final class Alpha2Code
{
    public static function isValid(string $code): bool
    {
        return preg_match('/^[A-Za-z]{2}$/', $code) === 1;
    }

    public static function encode(string $code): string
    {
        if (!self::isValid($code)) {
            throw new InvalidArgumentException("Expected a 2-letter alphabetic code, got \"{$code}\".");
        }

        $code = strtoupper($code);
        $writer = new BitWriter();
        $writer->writeUint(ord($code[0]) - ord('A'), 6);
        $writer->writeUint(ord($code[1]) - ord('A'), 6);

        return $writer->toBitString();
    }

    public static function decode(BitReader $reader): string
    {
        $letters = '';
        foreach ([$reader->readUint(6), $reader->readUint(6)] as $position => $value) {
            if ($value > 25) {
                throw new InvalidTcStringException(
                    "Letter {$position} of a 2-letter code decoded to {$value}; only 0..25 (A..Z) are valid."
                );
            }
            $letters .= chr(ord('A') + $value);
        }

        return $letters;
    }
}
