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
    public static function encodeBits(string $bits): string
    {
        $padLength = (8 - (strlen($bits) % 8)) % 8;
        $bits .= str_repeat('0', $padLength);

        $bytes = '';
        for ($i = 0; $i < strlen($bits); $i += 8) {
            $bytes .= chr((int) bindec(substr($bits, $i, 8)));
        }

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

        $bits = '';
        for ($i = 0; $i < strlen($bytes); $i++) {
            $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
        }

        return $bits;
    }
}
