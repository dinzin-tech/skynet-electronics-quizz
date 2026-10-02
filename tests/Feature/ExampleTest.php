<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /** @test */
    public function it_loads_the_homepage()
    {
        $response = $this->get('/');

        $this->assertEquals(200, $response->getStatusCode());
    }

    /** @test */
    public function it_returns_404_for_non_existent_route()
    {
        $response = $this->get('/non-existent-route');

        $this->assertEquals(404, $response->getStatusCode());
    }
}
