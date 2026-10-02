<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Commands\ConfigCacheCommand;
use App\Commands\ConfigClearCommand;
use PHPUnit\Framework\TestCase;

class ConfigCacheTest extends TestCase
{
    private string $targetFile;

    protected function setUp(): void
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $this->targetFile = $basePath . '/config/hot.php';
    }

    public function test_caches_and_clears_hot_configuration(): void
    {
        $_ENV['APP_SECRET'] = 'custom_secret_1234567890abcdef1234567890';
        $_ENV['TOKEN_KID'] = 'v2';
        $_ENV['GRACE_MS'] = '4500';

        ob_start();
        $cacheCmd = new ConfigCacheCommand();
        $cacheCmd->execute();
        ob_end_clean();

        $this->assertFileExists($this->targetFile);

        $config = require $this->targetFile;

        $this->assertIsArray($config);
        $this->assertSame('custom_secret_1234567890abcdef1234567890', $config['token_secret']);
        $this->assertSame('v2', $config['kid']);
        $this->assertSame(4500, $config['grace_ms']);
        $this->assertArrayHasKey('limits', $config);
        $this->assertSame(20, $config['limits']['answers_rate_max']);

        ob_start();
        $clearCmd = new ConfigClearCommand();
        $clearCmd->execute();
        ob_end_clean();

        $this->assertFileDoesNotExist($this->targetFile);

        // Re-cache for standard environment
        ob_start();
        $cacheCmd->execute();
        ob_end_clean();
    }
}
