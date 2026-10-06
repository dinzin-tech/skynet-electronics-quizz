<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Time and Timezone Helper for corporate quiz application.
 * All datetimes are stored in UTC in DB and Redis.
 * All displays convert from UTC to COMPANY_TZ (from .env) in 12-hour format with AM/PM.
 */
class TimeHelper
{
    /** @var array<string, DateTimeZone> */
    private static array $tzCache = [];
    private static ?DateTimeZone $utcTz = null;

    public static function getCompanyTimezone(): DateTimeZone
    {
        $tzName = $_ENV['COMPANY_TZ'] ?? 'Asia/Kolkata';
        if (!isset(self::$tzCache[$tzName])) {
            try {
                self::$tzCache[$tzName] = new DateTimeZone($tzName);
            } catch (\Throwable $e) {
                self::$tzCache[$tzName] = new DateTimeZone('Asia/Kolkata');
            }
        }
        return self::$tzCache[$tzName];
    }

    public static function getUtcTimezone(): DateTimeZone
    {
        if (self::$utcTz === null) {
            self::$utcTz = new DateTimeZone('UTC');
        }
        return self::$utcTz;
    }

    /**
     * Convert any stored UTC datetime (string, timestamp, or DateTime) to COMPANY_TZ.
     * Formats in 12-hour format with AM/PM (default: 'd M Y, h:i A').
     *
     * @param mixed $datetime Stored UTC datetime
     * @param string $format Target format (default 'd M Y, h:i A')
     * @param string $default Fallback if datetime is empty (default '—')
     * @return string
     */
    public static function toCompanyTz(mixed $datetime, string $format = 'd M Y, h:i A', string $default = '—'): string
    {
        if (empty($datetime)) {
            return $default;
        }

        $companyTz = self::getCompanyTimezone();
        $utcTz = self::getUtcTimezone();

        try {
            if ($datetime instanceof DateTimeInterface) {
                $dt = DateTimeImmutable::createFromInterface($datetime);
            } elseif (is_numeric($datetime)) {
                $ts = (int) $datetime;
                if ($ts > 100000000000) {
                    $ts = (int) round($ts / 1000);
                }
                $dt = (new DateTimeImmutable("@{$ts}"))->setTimezone($utcTz);
            } else {
                $datetimeStr = trim((string) $datetime);
                if ($datetimeStr === '' || $datetimeStr === '0000-00-00 00:00:00') {
                    return '—';
                }
                // Assume UTC if bare datetime string from database
                $dt = new DateTimeImmutable($datetimeStr, $utcTz);
            }

            return $dt->setTimezone($companyTz)->format($format);
        } catch (\Throwable $e) {
            return (string) $datetime;
        }
    }

    /**
     * Format a stored UTC datetime for HTML <input type="datetime-local"> in COMPANY_TZ.
     * Value must be 'Y-m-d\TH:i' without timezone offset.
     */
    public static function formatInputDateTime(mixed $datetime): string
    {
        if (empty($datetime)) {
            return '';
        }
        return self::toCompanyTz($datetime, 'Y-m-d\TH:i');
    }

    /**
     * Parse input datetime entered by user in COMPANY_TZ and convert to UTC 'Y-m-d H:i:s'.
     */
    public static function toUtc(string $datetime, string $fieldName = 'Date'): string
    {
        $datetime = trim($datetime);
        if ($datetime === '') {
            throw new InvalidArgumentException("{$fieldName} is required.");
        }

        $companyTz = self::getCompanyTimezone();
        $utcTz = self::getUtcTimezone();

        $formats = [
            'Y-m-d\TH:i:s',
            'Y-m-d\TH:i',
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'd/m/Y H:i:s',
            'd/m/Y H:i',
            'd-m-Y H:i:s',
            'd-m-Y H:i',
            'Y-m-d h:i:s A',
            'Y-m-d h:i A',
            'd M Y h:i A',
            'd M Y, h:i A',
        ];

        foreach ($formats as $fmt) {
            $dt = DateTimeImmutable::createFromFormat($fmt, $datetime, $companyTz);
            if ($dt !== false) {
                return $dt->setTimezone($utcTz)->format('Y-m-d H:i:s');
            }
        }

        try {
            $dt = new DateTimeImmutable($datetime, $companyTz);
            return $dt->setTimezone($utcTz)->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            throw new InvalidArgumentException(
                "Invalid format for {$fieldName}: '{$datetime}'."
            );
        }
    }
}
