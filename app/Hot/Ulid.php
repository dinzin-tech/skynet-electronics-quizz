<?php

declare(strict_types=1);

namespace App\Hot;

use InvalidArgumentException;

/**
 * Universally Unique Lexicographically Sortable Identifier (ULID).
 * 26 character Crockford Base32 string (48-bit timestamp + 80-bit randomness).
 */
class Ulid
{
    private const ENCODING_CHARS = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    private const ENCODING_LENGTH = 32;

    /**
     * Generate a new 26-character ULID.
     *
     * @param int|null $timeMs Optional timestamp in milliseconds
     * @return string 26-character Crockford Base32 ULID
     */
    public static function generate(?int $timeMs = null): string
    {
        $time = $timeMs ?? Clock::nowMs();

        // 10 characters for 48-bit timestamp
        $timeChars = '';
        for ($i = 9; $i >= 0; $i--) {
            $mod = $time % self::ENCODING_LENGTH;
            $timeChars = self::ENCODING_CHARS[$mod] . $timeChars;
            $time = (int) ($time / self::ENCODING_LENGTH);
        }

        // 16 characters for 80-bit randomness
        $randomBytes = random_bytes(10);
        $randomChars = '';
        for ($i = 0; $i < 10; $i++) {
            $val = ord($randomBytes[$i]);
            $randomChars .= self::ENCODING_CHARS[$val >> 3];
            $randomChars .= self::ENCODING_CHARS[($val & 7) << 2 | ($i < 9 ? ord($randomBytes[$i + 1]) >> 6 : 0)];
        }
        $randomChars = substr($randomChars, 0, 16);

        return $timeChars . $randomChars;
    }

    /**
     * Validate whether a string is a valid 26-character Crockford Base32 ULID.
     */
    public static function isValid(string $ulid): bool
    {
        if (strlen($ulid) !== 26) {
            return false;
        }

        return preg_match('/^[0123456789ABCDEFGHJKMNPQRSTVWXYZ]{26}$/i', $ulid) === 1;
    }

    /**
     * Extract timestamp in milliseconds from a ULID.
     */
    public static function getTimestampMs(string $ulid): int
    {
        if (!self::isValid($ulid)) {
            throw new InvalidArgumentException("Invalid ULID: {$ulid}");
        }

        $timePart = substr(strtoupper($ulid), 0, 10);
        $time = 0;

        for ($i = 0; $i < 10; $i++) {
            $char = $timePart[$i];
            $pos = strpos(self::ENCODING_CHARS, $char);
            if ($pos === false) {
                throw new InvalidArgumentException("Invalid ULID character: {$char}");
            }
            $time = ($time * self::ENCODING_LENGTH) + $pos;
        }

        return $time;
    }
}
