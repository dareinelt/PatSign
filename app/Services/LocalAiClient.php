<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class LocalAiClient
{
    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config) {}

    /** @param array<string,mixed> $payload */
    public function chat(array $payload): array
    {
        $url = sprintf('%s:%d/v1/chat/completions', rtrim((string) $this->config['host'], '/'), (int) $this->config['port']);
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . (string) $this->config['api_key'],
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) $this->config['timeout'],
        ]);

        $result = curl_exec($ch);
        if ($result === false) {
            throw new RuntimeException('Lokaler KI-Dienst nicht erreichbar: ' . curl_error($ch));
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status >= 400) {
            throw new RuntimeException('Lokaler KI-Dienst-Fehler: HTTP ' . $status);
        }

        /** @var array<string,mixed> */
        return json_decode((string) $result, true, 512, JSON_THROW_ON_ERROR);
    }
}
