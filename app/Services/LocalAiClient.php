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
        return $this->request('POST', '/v1/chat/completions', $payload);
    }

    /**
     * Lädt die am Endpunkt verfügbaren Modelle (OpenAI-kompatibel: GET /v1/models).
     *
     * @return list<string>
     */
    public function listModels(): array
    {
        $response = $this->request('GET', '/v1/models');
        $models = [];
        foreach ((array) ($response['data'] ?? []) as $entry) {
            if (is_array($entry) && isset($entry['id'])) {
                $models[] = (string) $entry['id'];
            }
        }
        sort($models);

        return $models;
    }

    /**
     * Prüft die Erreichbarkeit des Endpunkts und optional das konfigurierte Modell.
     *
     * @return array{success:bool,message:string,models:list<string>}
     */
    public function testConnection(): array
    {
        $models = $this->listModels();
        $model = trim((string) ($this->config['model'] ?? ''));

        if ($model !== '' && $models !== [] && !in_array($model, $models, true)) {
            return [
                'success' => false,
                'message' => sprintf('Endpunkt erreichbar, aber Modell "%s" wurde nicht gefunden.', $model),
                'models' => $models,
            ];
        }

        return [
            'success' => true,
            'message' => $models === []
                ? 'Endpunkt erreichbar (keine Modell-Liste verfügbar).'
                : sprintf('Endpunkt erreichbar, %d Modell(e) gefunden.', count($models)),
            'models' => $models,
        ];
    }

    /**
     * Basis-URL aus Host und Port bilden. Toleriert Hosts mit bereits
     * enthaltenem Port oder "/v1"-Suffix (z. B. "http://192.168.1.10:1234/v1").
     */
    private function baseUrl(): string
    {
        $host = rtrim(trim((string) $this->config['host']), '/');
        if ($host !== '' && !preg_match('~^https?://~i', $host)) {
            $host = 'http://' . $host;
        }
        if (preg_match('~/v1$~i', $host)) {
            $host = substr($host, 0, -3);
            $host = rtrim($host, '/');
        }

        $port = (int) ($this->config['port'] ?? 0);
        $hasPort = parse_url($host, PHP_URL_PORT) !== null;
        if ($port > 0 && !$hasPort) {
            $parts = parse_url($host);
            $path = $parts['path'] ?? '';
            $base = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? '');
            $host = $base . ':' . $port . $path;
        }

        return rtrim($host, '/');
    }

    /** @param array<string,mixed>|null $payload */
    private function request(string $method, string $path, ?array $payload = null): array
    {
        $ch = curl_init($this->baseUrl() . $path);

        $headers = ['Content-Type: application/json'];
        $apiKey = trim((string) ($this->config['api_key'] ?? ''));
        if ($apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            // Verbindungsaufbau schnell abbrechen, aber der Inferenz genug Zeit lassen:
            // lokale Vision-Modelle brauchen bei mehrseitigen Dokumenten mehrere Minuten.
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => max(1, (int) ($this->config['timeout'] ?? 300)),
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload ?? [], JSON_THROW_ON_ERROR));
        }

        $result = curl_exec($ch);
        if ($result === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Lokaler KI-Dienst nicht erreichbar: ' . $error);
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status >= 400) {
            $detail = $this->extractErrorDetail((string) $result);
            throw new RuntimeException(
                'Lokaler KI-Dienst-Fehler: HTTP ' . $status . ($detail !== '' ? ' – ' . $detail : ''),
                $status
            );
        }

        /** @var array<string,mixed> */
        return json_decode((string) $result, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Extrahiert eine aussagekräftige Fehlermeldung aus dem Antwort-Body
     * (OpenAI-kompatibel: {"error": {"message": "..."}} oder {"error": "..."}).
     */
    private function extractErrorDetail(string $body): string
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $error = $decoded['error'] ?? null;
            if (is_array($error) && isset($error['message'])) {
                return trim((string) $error['message']);
            }
            if (is_string($error)) {
                return trim($error);
            }
        }

        $body = trim($body);

        return mb_strlen($body) > 300 ? mb_substr($body, 0, 300) . '…' : $body;
    }
}
