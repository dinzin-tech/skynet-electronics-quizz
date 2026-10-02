<?php

declare(strict_types=1);

namespace App\Hot;

use InvalidArgumentException;

/**
 * Compact HMAC-SHA256 Token implementation for the hot path.
 * Format: <base64url(json_payload)>.<base64url(hmac_signature)>
 * Zero external dependencies. Uses constant-time hash_equals.
 */
class Token
{
    /**
     * Issue a new signed token.
     *
     * @param int|string $uid Employee or Administrator ID
     * @param string $role 'employee' or 'admin'
     * @param int $ttlSeconds Validity duration in seconds
     * @param string $secret HMAC shared secret
     * @param string $kid Key identifier for secret rotation
     * @return string
     */
    public static function issue(
        int|string $uid,
        string $role,
        int $ttlSeconds,
        string $secret,
        string $kid = 'v1'
    ): string {
        if ($secret === '') {
            throw new InvalidArgumentException('Secret cannot be empty');
        }

        $now = Clock::nowMs();
        $exp = (int) ($now / 1000) + $ttlSeconds;

        $payload = [
            'uid' => $uid,
            'role' => $role,
            'iat' => (int) ($now / 1000),
            'exp' => $exp,
            'kid' => $kid,
        ];

        $payloadEncoded = self::base64UrlEncode((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signature = hash_hmac('sha256', $payloadEncoded, $secret, true);
        $signatureEncoded = self::base64UrlEncode($signature);

        return "{$payloadEncoded}.{$signatureEncoded}";
    }

    /**
     * Verify and decode a token.
     *
     * @param string $token The token string
     * @param string $secret Expected HMAC secret
     * @param string|null $expectedKid Optional expected key identifier
     * @param int|null $nowTimestamp Optional current timestamp in seconds
     * @return array<string, mixed>|null Returns decoded payload on success, null on failure
     */
    public static function verify(
        string $token,
        string $secret,
        ?string $expectedKid = null,
        ?int $nowTimestamp = null
    ): ?array {
        if ($token === '' || $secret === '') {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }

        [$payloadEncoded, $signatureEncoded] = $parts;

        $expectedSig = hash_hmac('sha256', $payloadEncoded, $secret, true);
        $expectedSigEncoded = self::base64UrlEncode($expectedSig);

        if (!hash_equals($expectedSigEncoded, $signatureEncoded)) {
            return null; // Tampered or invalid signature
        }

        $jsonStr = self::base64UrlDecode($payloadEncoded);
        if ($jsonStr === null) {
            return null;
        }

        $payload = json_decode($jsonStr, true);
        if (!is_array($payload) || !isset($payload['uid'], $payload['role'], $payload['exp'])) {
            return null; // Malformed payload
        }

        if ($expectedKid !== null && (!isset($payload['kid']) || $payload['kid'] !== $expectedKid)) {
            return null; // Wrong key id
        }

        $now = $nowTimestamp ?? (int) (Clock::nowMs() / 1000);
        if ($payload['exp'] < $now) {
            return null; // Expired token
        }

        return $payload;
    }

    /**
     * Base64Url encode string without padding.
     */
    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64Url decode string.
     */
    public static function base64UrlDecode(string $data): ?string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        return $decoded !== false ? $decoded : null;
    }
}
