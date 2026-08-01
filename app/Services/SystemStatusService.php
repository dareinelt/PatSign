<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Prüft die Erreichbarkeit aller Systemkomponenten für die Statusanzeige im Dashboard.
 * Status: ok (grün), warn (gelb), error (rot).
 */
final class SystemStatusService
{
    public function __construct(
        private readonly ?PDO $pdo,
        private readonly SettingsService $settings
    ) {}

    /** @return array<int,array{key:string,label:string,status:string,detail:string}> */
    public function checkAll(): array
    {
        $checks = [];

        $checks[] = $this->checkDatabase();
        $checks[] = $this->checkAiEndpoint('vision', 'Vision-KI');
        $checks[] = $this->checkAiEndpoint('analysis', 'Analyse-KI');
        $checks[] = $this->checkPath('storage', 'Speicherpfad', dirname(__DIR__, 2) . '/storage');
        $checks[] = $this->checkPath('import', 'Importpfad', $this->settings->getString('app.import_watch_path'));
        $checks[] = $this->checkPath('export', 'Exportpfad', $this->settings->getString('app.network_share_path'));
        $checks[] = $this->checkSmtp();

        return $checks;
    }

    /** @return array{key:string,label:string,status:string,detail:string} */
    private function checkDatabase(): array
    {
        try {
            if ($this->pdo === null) {
                return ['key' => 'database', 'label' => 'Datenbank', 'status' => 'error', 'detail' => 'Keine Verbindung'];
            }
            $this->pdo->query('SELECT 1');

            return ['key' => 'database', 'label' => 'Datenbank', 'status' => 'ok', 'detail' => 'Verbunden'];
        } catch (\Throwable $e) {
            return ['key' => 'database', 'label' => 'Datenbank', 'status' => 'error', 'detail' => 'Fehler bei der Verbindung'];
        }
    }

    /** @return array{key:string,label:string,status:string,detail:string} */
    private function checkAiEndpoint(string $type, string $label): array
    {
        $host = $this->settings->getString('ai.' . $type . '.host');
        $port = $this->settings->getInt('ai.' . $type . '.port');

        if ($host === '' || $port <= 0) {
            return ['key' => $type, 'label' => $label, 'status' => 'warn', 'detail' => 'Nicht konfiguriert'];
        }

        $hostname = (string) (parse_url($host, PHP_URL_HOST) ?: $host);

        return $this->probeTcp($type, $label, $hostname, $port);
    }

    /** @return array{key:string,label:string,status:string,detail:string} */
    private function checkSmtp(): array
    {
        $host = $this->settings->getString('mail.host');
        $port = $this->settings->getInt('mail.port', 25);

        if ($host === '') {
            return ['key' => 'smtp', 'label' => 'SMTP', 'status' => 'warn', 'detail' => 'Nicht konfiguriert'];
        }

        return $this->probeTcp('smtp', 'SMTP', $host, $port);
    }

    /** @return array{key:string,label:string,status:string,detail:string} */
    private function probeTcp(string $key, string $label, string $host, int $port): array
    {
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($host, $port, $errno, $errstr, 2.0);

        if ($socket === false) {
            return ['key' => $key, 'label' => $label, 'status' => 'error', 'detail' => sprintf('%s:%d nicht erreichbar', $host, $port)];
        }

        fclose($socket);

        return ['key' => $key, 'label' => $label, 'status' => 'ok', 'detail' => sprintf('%s:%d erreichbar', $host, $port)];
    }

    /** @return array{key:string,label:string,status:string,detail:string} */
    private function checkPath(string $key, string $label, string $path): array
    {
        if ($path === '') {
            return ['key' => $key, 'label' => $label, 'status' => 'warn', 'detail' => 'Nicht konfiguriert'];
        }

        if (!is_dir($path)) {
            return ['key' => $key, 'label' => $label, 'status' => 'error', 'detail' => 'Verzeichnis fehlt'];
        }

        if (!is_writable($path)) {
            return ['key' => $key, 'label' => $label, 'status' => 'warn', 'detail' => 'Nicht beschreibbar'];
        }

        return ['key' => $key, 'label' => $label, 'status' => 'ok', 'detail' => 'Verfügbar'];
    }
}
