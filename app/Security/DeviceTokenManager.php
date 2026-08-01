<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Erzeugt und prüft gerätegebundene Kennungen und Tokens.
 * Tokens werden nur als SHA-256-Hash gespeichert (Replay-/Diebstahlschutz).
 */
final class DeviceTokenManager
{
    public function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // Version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // Variante RFC 4122

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    public function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function verify(string $token, string $hash): bool
    {
        return $token !== '' && hash_equals($hash, $this->hash($token));
    }

    public function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }
}
