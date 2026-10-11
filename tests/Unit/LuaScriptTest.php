<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Hot\Lua;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class LuaScriptTest extends TestCase
{
    public function test_all_lua_scripts_exist_and_load_cleanly(): void
    {
        $scripts = ['start', 'save', 'submit', 'flip_absent'];

        foreach ($scripts as $name) {
            $code = Lua::loadScriptCode($name);
            $this->assertNotEmpty($code, "Script {$name}.lua should not be empty");
            $this->assertStringContainsString('redis.call', $code);
        }
    }

    public function test_start_script_implements_overtime_cap_rule(): void
    {
        $code = Lua::loadScriptCode('start');
        $this->assertStringContainsString('deadlineMs = nowMs + durationMs', $code);
        $this->assertStringContainsString('maxAllowedMs = endMs + overtimeGraceMs', $code);
        $this->assertStringContainsString('if deadlineMs > maxAllowedMs then', $code);
        $this->assertStringContainsString('deadlineMs = maxAllowedMs', $code);
        $this->assertStringContainsString('deadlines', $code);
        $this->assertStringContainsString('dirtyAttKey', $code);
    }

    public function test_save_script_implements_monotonic_seq_last_write_wins(): void
    {
        $code = Lua::loadScriptCode('save');
        $this->assertStringContainsString('deadline_passed', $code);
        $this->assertStringContainsString('not_in_progress', $code);
        $this->assertStringContainsString('seq <= storedSeq', $code);
        $this->assertStringContainsString('max_seq', $code);
        $this->assertStringContainsString('dirtyKey', $code);
    }

    public function test_submit_script_implements_deduplication(): void
    {
        $code = Lua::loadScriptCode('submit');
        $this->assertStringContainsString('already_completed', $code);
        $this->assertStringContainsString('deadlinesKey', $code);
        $this->assertStringContainsString('dirtyAttKey', $code);
        $this->assertStringContainsString('fqKey', $code);
    }

    public function test_throws_exception_for_non_existent_script(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Lua script not found');
        Lua::loadScriptCode('non_existent_script_xyz');
    }
}
