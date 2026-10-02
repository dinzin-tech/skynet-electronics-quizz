<?php

namespace Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Core\Kernel;
use Core\Http\Request;

abstract class TestCase extends BaseTestCase
{
    protected Kernel $kernel;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock environment variables for testing if needed
        $_ENV['APP_ENV'] = 'testing';
        $_ENV['DEBUG_MODE'] = 'false';

        $this->kernel = new Kernel();
        $this->kernel->boot();
    }

    /**
     * Helper to simulate a GET request to the application.
     */
    protected function get(string $uri)
    {
        $request = new Request();
        $request->setTestUri($uri);

        return $this->kernel->handle($request);
    }

    /**
     * Helper to simulate a console command execution.
     */
    protected function console(string $commandLine)
    {
        $argv = explode(' ', "bin/console " . $commandLine);

        $commandManager = new \Core\Console\CommandManager();
        $commandManager->init();

        ob_start();
        $commandManager->run($argv);
        return ob_get_clean();
    }
}
