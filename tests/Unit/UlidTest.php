<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Hot\Ulid;
use PHPUnit\Framework\TestCase;

class UlidTest extends TestCase
{
    public function test_generates_26_character_valid_ulid(): void
    {
        $ulid = Ulid::generate();

        $this->assertSame(26, strlen($ulid));
        $this->assertTrue(Ulid::isValid($ulid));
    }

    public function test_extracts_correct_timestamp_from_ulid(): void
    {
        $timeMs = 1710000000123;
        $ulid = Ulid::generate($timeMs);

        $extractedTimeMs = Ulid::getTimestampMs($ulid);

        $this->assertSame($timeMs, $extractedTimeMs);
    }

    public function test_rejects_invalid_ulids(): void
    {
        $this->assertFalse(Ulid::isValid(''));
        $this->assertFalse(Ulid::isValid('SHORT'));
        $this->assertFalse(Ulid::isValid('01AN4Z07BY79KA1307SR9X4MV3EXTRA')); // 27 chars
        $this->assertFalse(Ulid::isValid('01AN4Z07BY79KA1307SR9X4MV!')); // invalid char '!'
    }

    public function test_monotonic_sorting_order(): void
    {
        $ulid1 = Ulid::generate(1000000);
        $ulid2 = Ulid::generate(2000000);
        $ulid3 = Ulid::generate(3000000);

        $this->assertLessThan(0, strcmp($ulid1, $ulid2));
        $this->assertLessThan(0, strcmp($ulid2, $ulid3));
    }
}
