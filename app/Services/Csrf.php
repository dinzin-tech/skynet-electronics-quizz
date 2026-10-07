<?php

declare(strict_types=1);

namespace App\Services;

use Core\Session;

/**
 * Minimal per-session CSRF token helper for the Data Reset feature.
 *
 * Token is generated once per session and stored under the session key
 * 'csrf_data_reset'. It is intentionally scoped to this feature only;
 * retrofitting the rest of the admin panel is a follow-up (see DATA_RESET.md).
 */
class Csrf
{
    private const SESSION_KEY = 'csrf_data_reset';

    /**
     * Return the current token, generating one if none exists.
     */
    public static function token(): string
    {
        $token = Session::get(self::SESSION_KEY);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            Session::set(self::SESSION_KEY, $token);
        }
        return $token;
    }

    /**
     * Verify a submitted token using a timing-safe comparison.
     */
    public static function verify(string $submitted): bool
    {
        $stored = Session::get(self::SESSION_KEY);
        if (!is_string($stored) || $stored === '' || $submitted === '') {
            return false;
        }
        return hash_equals($stored, $submitted);
    }

    /**
     * Regenerate the token (call after a successful purge).
     */
    public static function regenerate(): void
    {
        Session::set(self::SESSION_KEY, bin2hex(random_bytes(32)));
    }
}
