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
        if ($remainder > 0) {
            $base64 .= str_repeat('=', 4 - $remainder);
        }

        $bytes = base64_decode($base64, true);
        if ($bytes === false) {
            throw new InvalidArgumentException("Invalid base64url segment: \"{$base64Url}\".");
        }

        // A lookup table instead of str_pad(decbin()) per byte: about four
        // times faster, which matters because this is the one step whose cost
        // grows with the (untrusted) input length.
        return strtr($bytes, self::byteToBits());
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
