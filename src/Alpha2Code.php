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
    /** ASCII code point of 'A'; letter value 0 maps to it, 25 maps to 'Z'. */
    private const ASCII_A = 65;

    public static function isValid(string $code): bool
    {
        // \z, not $: PCRE's $ also matches immediately before a trailing
        // newline, so "EN\n" would validate here and then be silently
        // truncated to "EN" by the two ord() reads in encode().
        return preg_match('/^[A-Za-z]{2}\z/', $code) === 1;
    }

    public static function encode(string $code): string
    {
        if (!self::isValid($code)) {
            throw new InvalidArgumentException("Expected a 2-letter alphabetic code, got \"{$code}\".");
        }

        $code = strtoupper($code);
        $writer = new BitWriter();
        $writer->writeUint(ord($code[0]) - self::ASCII_A, 6);
        $writer->writeUint(ord($code[1]) - self::ASCII_A, 6);

        return $writer->toBitString();
    }

    public static function decode(BitReader $reader): string
    {
        $letters = '';
        foreach ([$reader->readUint(6), $reader->readUint(6)] as $position => $value) {
            // readUint(6) cannot be negative, so only the upper bound is checked.
            if ($value > 25) {
                throw new InvalidTcStringException(
                    "Letter {$position} of a 2-letter code decoded to {$value}; only 0..25 (A..Z) are valid."
                );
            }
            $letters .= chr(self::ASCII_A + $value);
        }

        return $letters;
    }
}
