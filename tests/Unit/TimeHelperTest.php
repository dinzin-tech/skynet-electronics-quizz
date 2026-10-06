<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TimeHelper;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TimeHelperTest extends TestCase
{
    private ?string $originalCompanyTz;

    protected function setUp(): void
    {
        $this->originalCompanyTz = $_ENV['COMPANY_TZ'] ?? null;
        $_ENV['COMPANY_TZ'] = 'Asia/Kolkata'; // UTC+05:30
    }

    protected function tearDown(): void
    {
        if ($this->originalCompanyTz !== null) {
            $_ENV['COMPANY_TZ'] = $this->originalCompanyTz;
        } else {
            unset($_ENV['COMPANY_TZ']);
        }
    }

    public function test_get_company_timezone_reads_env(): void
    {
        $_ENV['COMPANY_TZ'] = 'Asia/Kolkata';
        $tz = TimeHelper::getCompanyTimezone();
        $this->assertSame('Asia/Kolkata', $tz->getName());

        $_ENV['COMPANY_TZ'] = 'America/New_York';
        $tzNy = TimeHelper::getCompanyTimezone();
        $this->assertSame('America/New_York', $tzNy->getName());
    }

    public function test_to_company_tz_converts_utc_to_12_hour_am_pm_format(): void
    {
        $_ENV['COMPANY_TZ'] = 'Asia/Kolkata'; // UTC+05:30

        // 2026-10-07 00:00:00 UTC = 2026-10-07 05:30:00 AM IST
        $formatted = TimeHelper::toCompanyTz('2026-10-07 00:00:00');
        $this->assertSame('07 Oct 2026, 05:30 AM', $formatted);

        // 2026-10-07 10:45:15 UTC = 2026-10-07 04:15:15 PM IST
        $formattedPm = TimeHelper::toCompanyTz('2026-10-07 10:45:15', 'd M Y, h:i:s A');
        $this->assertSame('07 Oct 2026, 04:15:15 PM', $formattedPm);
    }

    public function test_to_company_tz_handles_empty_or_null_gracefully(): void
    {
        $this->assertSame('—', TimeHelper::toCompanyTz(null));
        $this->assertSame('—', TimeHelper::toCompanyTz(''));
        $this->assertSame('', TimeHelper::toCompanyTz(null, 'd M Y, h:i A', ''));
    }

    public function test_to_utc_converts_company_tz_to_utc_storage_format(): void
    {
        $_ENV['COMPANY_TZ'] = 'Asia/Kolkata'; // UTC+05:30

        // Input entered by admin in IST: 2026-10-07 15:30:00
        // Expected UTC: 2026-10-07 10:00:00
        $utc = TimeHelper::toUtc('2026-10-07 15:30:00');
        $this->assertSame('2026-10-07 10:00:00', $utc);

        // HTML datetime-local format: 2026-10-07T15:30
        $utcFromInput = TimeHelper::toUtc('2026-10-07T15:30');
        $this->assertSame('2026-10-07 10:00:00', $utcFromInput);
    }

    public function test_format_input_date_time_converts_to_datetime_local_format(): void
    {
        $_ENV['COMPANY_TZ'] = 'Asia/Kolkata';

        // UTC: 2026-10-07 10:00:00
        // IST: 2026-10-07 15:30:00 -> '2026-10-07T15:30'
        $htmlInputVal = TimeHelper::formatInputDateTime('2026-10-07 10:00:00');
        $this->assertSame('2026-10-07T15:30', $htmlInputVal);
    }

    public function test_to_utc_throws_on_invalid_datetime(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TimeHelper::toUtc('invalid-date-string', 'Start Window');
    }
}
