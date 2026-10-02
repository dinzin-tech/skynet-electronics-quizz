<?php

namespace App\Commands;

class HelloCommand
{
    /**
     * Execute the command.
     *
     * @param array $arguments
     */
    public function execute(array $arguments)
    {
        $name = $arguments[0] ?? 'World';
        echo "Hello, {$name}!";
    }
}
