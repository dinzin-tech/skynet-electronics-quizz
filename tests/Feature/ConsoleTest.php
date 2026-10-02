<?php

namespace Tests\Feature;

use Tests\TestCase;

class ConsoleTest extends TestCase
{
    /** @test */
    public function it_can_run_the_help_command()
    {
        $output = $this->console('help');

        $this->assertStringContainsString('Below is a list of available commands:', $output);
    }

    /** @test */
    public function it_can_run_the_hello_command()
    {
        $output = $this->console('hello Antigravity');

        $this->assertStringContainsString('Hello, Antigravity!', $output);
    }

    /** @test */
    public function it_can_run_the_hello_command_with_default_argument()
    {
        $output = $this->console('hello');

        $this->assertStringContainsString('Hello, World!', $output);
    }
}
