<?php

namespace Tests\Unit;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /** @test */
    public function it_can_assert_true()
    {
        $this->assertTrue(true);
    }

    /** @test */
    public function framework_is_booted()
    {
        $this->assertNotNull($this->kernel);
    }
}
