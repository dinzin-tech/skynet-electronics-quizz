<?php

namespace App\Commands;

use Core\Router;

class Composer
{
    public static function postCreateProject()
    {
        echo "Setting up your project...\n";

        // Create .env file if it doesn't exist
        if (!file_exists('.env')) {
            $key = bin2hex(random_bytes(32));
            $defaultEnv = "APP_ENV=local\nDEBUG_MODE=true\nAPP_SECRET={$key}\n"
                . "DB_HOST=localhost\nDB_NAME=myapp\nDB_USER=root\nDB_PASSWORD=\n";
            file_put_contents('.env', $defaultEnv);
            echo ".env file created with a fresh APP_SECRET.\n";
        } else {
            echo ".env file already exists.\n";
        }

        // Create storage directory if it doesn't exist
        if (!is_dir('storage')) {
            mkdir('storage', 0755, true);
            echo "Storage directory created.\n";
        }

        // Display instructions
        echo "Project setup complete!\n";
        echo "Run `php serve:dev` to start the development server.\n";
    }

    public static function postInstall()
    {
        echo "Running post-install tasks...\n";

        // Create storage directory structure if it doesn't exist
        if (!is_dir('storage')) {
            mkdir('storage', 0755, true);
        }
        foreach (['logs', 'cache', 'bundles'] as $sub) {
            if (!is_dir("storage/{$sub}")) {
                mkdir("storage/{$sub}", 0755, true);
            }
        }

        // Set file permissions
        chmod('storage', 0755);
        echo "Storage directory permissions set.\n";

        // Run database migrations
        // echo "Running database migrations...\n";
        // You can call a migration script here
    }

    public static function postUpdate()
    {
        echo "Running post-update tasks...\n";

        // Clear cache
        self::clearCache('storage/cache');

        // Run database migrations
        // echo "Running database migrations...\n";
        // You can call a migration script here
    }

    public static function clearCache($dir)
    {
        echo "Clearing cache...\n";

        // Delete the Twig cache directory
        self::deleteDirectory($dir);

        echo "Cache cleared.\n";
    }

    private static function deleteDirectory($dir)
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                self::deleteDirectory($path);
            } else {
                unlink($path);
            }
        }

        // rmdir($dir);
    }
}
