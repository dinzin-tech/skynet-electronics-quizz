<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Hot\Clock;
use App\Hot\HotRouter;
use PHPUnit\Framework\TestCase;
use Redis;

class HotRouterTest extends TestCase
{
    private HotRouter $router;
    private Redis $mockRedis;

    protected function setUp(): void
    {
        $this->router = new HotRouter();
        $this->mockRedis = $this->createMock(Redis::class);
    }

    public function test_dispatches_exact_route_and_params(): void
    {
        $matched = false;
        $capturedCode = null;

        $this->router->get('/api/quiz/{code}', function ($params) use (&$matched, &$capturedCode) {
            $matched = true;
            $capturedCode = $params['code'];
        });

        $this->router->dispatch('GET', '/api/quiz/ABCD2345', [], $this->mockRedis);

        $this->assertTrue($matched);
        $this->assertSame('ABCD2345', $capturedCode);
    }

    public function test_dispatches_post_and_put_routes(): void
    {
        $postCalled = false;
        $putCalled = false;

        $this->router->post('/api/quiz/{code}/start', function () use (&$postCalled) {
            $postCalled = true;
        });

        $this->router->put('/api/attempts/{aid}/answers', function () use (&$putCalled) {
            $putCalled = true;
        });

        $this->router->post('/api/attempts/{aid}/feedback', function ($params) use (&$feedbackCalled, &$capturedAid) {
            $feedbackCalled = true;
            $capturedAid = $params['aid'];
        });

        $this->router->dispatch('POST', '/api/quiz/XYZ/start', [], $this->mockRedis);
        $this->router->dispatch('PUT', '/api/attempts/01AN4Z07BY79KA1307SR9X4MV3/answers', [], $this->mockRedis);
        $this->router->dispatch('POST', '/api/attempts/01AN4Z07BY79KA1307SR9X4MV3/feedback', [], $this->mockRedis);

        $this->assertTrue($postCalled);
        $this->assertTrue($putCalled);
        $this->assertTrue($feedbackCalled);
        $this->assertSame('01AN4Z07BY79KA1307SR9X4MV3', $capturedAid);
    }

    public function test_unmatched_route_triggers_404_error(): void
    {
        ob_start();
        $this->router->dispatch('GET', '/api/nonexistent/path', [], $this->mockRedis);
        $output = ob_get_clean();

        $this->assertStringContainsString('not_found', $output);
        $this->assertStringContainsString('Hot endpoint not found', $output);
    }

    public function test_read_json_body_handles_size_limits(): void
    {
        $_SERVER['CONTENT_LENGTH'] = 100000; // > 65536
        $this->assertNull(HotRouter::readJsonBody(65536));
        unset($_SERVER['CONTENT_LENGTH']);
    }
}
