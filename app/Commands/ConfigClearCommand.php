<?php

declare(strict_types=1);

namespace App\Commands;

/**
 * Remove cached config/hot.php.
 */
class ConfigClearCommand
{
    public function execute(array $args = []): void
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $targetFile = $basePath . '/config/hot.php';

        if (file_exists($targetFile)) {
            unlink($targetFile);
            echo "Hot configuration cache cleared: {$targetFile}\n";
        } else {
            echo "No cached hot configuration found.\n";
        }
    }
}
