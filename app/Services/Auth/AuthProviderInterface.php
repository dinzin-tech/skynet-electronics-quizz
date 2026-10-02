<?php

declare(strict_types=1);

namespace App\Services\Auth;

interface AuthProviderInterface
{
    /**
     * Authenticate an employee with given credentials.
     * Supports login by employee_code, email, or username.
     *
     * @param string $identifier employee_code, email, or username
     * @param string $password Clear-text password
     * @return array<string, mixed>|null User array on success, null on failure
     */
    public function authenticate(string $identifier, string $password): ?array;
}
