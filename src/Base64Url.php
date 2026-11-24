<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf;

use Flenczewski\IabTcf\Exception\InvalidArgumentException;

/**
 * Converts a raw '0'/'1' bit string to/from the URL-safe, unpadded base64
 * encoding used for each dot-separated segment of a TC String.
 */
final class Base64Url
{
    /** How much of an invalid segment an exception message quotes. */
    private const MAX_QUOTED_LENGTH = 32;

    /**
     * PHP turns the keys chr(48)..chr(57) into the integers 0..9; strtr()
     * compares keys as strings, so they still match the bytes "0".."9".
     *
     * @var array<string,string>|null byte => its 8-character '0'/'1' spelling
     */
    private static ?array $byteToBits = null;

    /** @var array<string,string>|null the inverse of $byteToBits */
    private static ?array $bitsToByte = null;

    public static function encodeBits(string $bits): string
    {
        $padLength = (8 - (strlen($bits) % 8)) % 8;
        $bits .= str_repeat('0', $padLength);

        // strtr() with same-length keys consumes the input in consecutive
        // 8-character steps, so this is one C-level pass instead of a PHP loop
        // calling bindec()/chr() per byte.
        $bytes = strtr($bits, self::bitsToByte());

        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function decodeToBits(string $base64Url): string
    {
        $base64 = strtr($base64Url, '-_', '+/');
        $remainder = strlen($base64) % 4;
        // One leftover character carries only 6 bits, less than a byte, so no
        // encoder produces it. base64_decode() silently drops it instead of
        // failing, which let trailing junk through.
        if ($remainder === 1) {
            throw new InvalidArgumentException(
                'Invalid base64url segment ' . self::describe($base64Url)
                . ': its length leaves a single dangling character.'
            );
        }
        if ($remainder > 0) {
            $base64 .= str_repeat('=', 4 - $remainder);
        }

        $bytes = base64_decode($base64, true);
        if ($bytes === false) {
            throw new InvalidArgumentException('Invalid base64url segment ' . self::describe($base64Url) . '.');
        }

        // A lookup table instead of str_pad(decbin()) per byte: about four
        // times faster, which matters because this is the one step whose cost
        // grows with the (untrusted) input length.
        return strtr($bytes, self::byteToBits());
    }

    /**
     * Quotes untrusted input for an exception message: cut to a short prefix
     * and escaped, so a hostile segment can neither flood a log with up to
     * MAX_TC_STRING_LENGTH bytes nor forge log lines with embedded newlines.
     */
    private static function describe(string $segment): string
    {
        $prefix = substr($segment, 0, self::MAX_QUOTED_LENGTH);
        $quoted = '"' . addcslashes($prefix, "\0..\37\"\\\177..\377") . '"';

        return strlen($segment) > self::MAX_QUOTED_LENGTH
            ? sprintf('%s... (%d characters)', $quoted, strlen($segment))
            : $quoted;
    }

    /** @return array<string,string> */
    private static function byteToBits(): array
    {
        if (self::$byteToBits === null) {
            self::$byteToBits = [];
            for ($byte = 0; $byte < 256; $byte++) {
                self::$byteToBits[chr($byte)] = str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
            }
        }

        return self::$byteToBits;
    }

    /** @return array<string,string> */
    private static function bitsToByte(): array
    {
        return self::$bitsToByte ??= array_flip(self::byteToBits());
    }
}
