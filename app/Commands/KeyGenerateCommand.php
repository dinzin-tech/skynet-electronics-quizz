<?php

namespace App\Commands;

class KeyGenerateCommand
{
    public function execute(array $arguments)
    {
        $key = bin2hex(random_bytes(32));
        $envFile = BASE_PATH_IN_COMMANDS . '/.env';

        if (!file_exists($envFile)) {
            echo ".env file not found. Please create it first.\n";
            return;
        }

        $content = file_get_contents($envFile);

        if (strpos($content, 'APP_SECRET=') !== false) {
            $content = preg_replace('/APP_SECRET=.*/', "APP_SECRET=$key", $content);
        } else {
            $content .= "\nAPP_SECRET=$key\n";
        }

        file_put_contents($envFile, $content);

        echo "Application key [$key] set successfully.\n";
    }
}
