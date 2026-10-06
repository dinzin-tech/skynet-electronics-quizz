<?php

declare(strict_types=1);

namespace App\Services\Auth;

interface AuthProviderInterface
{
    /**
     * Authenticate an employee with given identifier.
     * Supports login by employee_code, email, or username.
     *
     * @param string $identifier employee_code, email, or username
     * @return array<string, mixed>|null User array on success, null on failure
     */
    public function authenticate(string $identifier): ?array;
}
