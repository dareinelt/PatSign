<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Zugriffstest für Netzwerkfreigaben (UNC-Pfade wie \\Server\Freigabe)
 * mit optionalen Anmeldedaten (Domäne, Benutzer, Passwort).
 *
 * - Windows: Verbindung wird per "net use" mit den Anmeldedaten aufgebaut,
 *   anschließend wird der Verzeichniszugriff geprüft.
 * - Linux/Docker: Der Test erfolgt per "smbclient" (falls installiert),
 *   ansonsten per direktem Verzeichniszugriff (z. B. gemounteter Pfad).
 */
final class NetworkShareService
{
    /**
     * @return array{success:bool,message:string}
     */
    public function testConnection(string $path, string $domain = '', string $username = '', string $password = ''): array
    {
        $path = trim($path);
        if ($path === '') {
            return ['success' => false, 'message' => 'Bitte einen Pfad angeben.'];
        }

        if ($this->isUncPath($path)) {
            return $this->isWindows()
                ? $this->testWindows($path, $domain, $username, $password)
                : $this->testSmbClient($path, $domain, $username, $password);
        }

        // Lokaler Pfad (z. B. gemountetes Verzeichnis)
        if (is_dir($path)) {
            $writable = is_writable($path) ? ' (beschreibbar)' : ' (nur lesbar)';
            return ['success' => true, 'message' => 'Verzeichnis erreichbar' . $writable . '.'];
        }

        return ['success' => false, 'message' => 'Verzeichnis nicht erreichbar: ' . $path];
    }

    /**
     * Stellt unter Windows eine authentifizierte Verbindung zur Freigabe her,
     * bevor Dateioperationen auf dem UNC-Pfad ausgeführt werden.
     */
    public function ensureConnection(string $path, string $domain = '', string $username = '', string $password = ''): void
    {
        if ($username === '' || !$this->isUncPath($path) || !$this->isWindows()) {
            return;
        }

        $share = $this->shareRoot($path);
        if (is_dir($share)) {
            return; // Verbindung besteht bereits
        }

        $account = $domain !== '' ? $domain . '\\' . $username : $username;
        $this->exec(['net', 'use', $share, $password, '/user:' . $account, '/persistent:no']);
    }

    public function isUncPath(string $path): bool
    {
        return (bool) preg_match('#^\\\\\\\\[^\\\\/]+\\\\[^\\\\/]+#', $path);
    }

    private function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    /**
     * @return array{success:bool,message:string}
     */
    private function testWindows(string $path, string $domain, string $username, string $password): array
    {
        $share = $this->shareRoot($path);

        if ($username !== '') {
            $account = $domain !== '' ? $domain . '\\' . $username : $username;
            // Bestehende Verbindung ggf. trennen, damit die neuen Anmeldedaten greifen.
            $this->exec(['net', 'use', $share, '/delete', '/y']);
            $result = $this->exec(['net', 'use', $share, $password, '/user:' . $account, '/persistent:no']);
            if ($result['code'] !== 0) {
                return ['success' => false, 'message' => 'Anmeldung an ' . $share . ' fehlgeschlagen: ' . $this->firstLine($result['output'])];
            }
        }

        if (!is_dir($path)) {
            return ['success' => false, 'message' => 'Freigabe verbunden, aber Verzeichnis nicht gefunden: ' . $path];
        }

        $writable = $this->probeWrite($path);

        return ['success' => true, 'message' => 'Verbindung erfolgreich' . ($writable ? ' (Schreibzugriff OK)' : ' (nur Lesezugriff)') . '.'];
    }

    /**
     * @return array{success:bool,message:string}
     */
    private function testSmbClient(string $path, string $domain, string $username, string $password): array
    {
        $check = $this->exec(['which', 'smbclient']);
        if ($check['code'] !== 0) {
            // Fallback: direkter Zugriff (z. B. per CIFS gemountet)
            if (is_dir($path)) {
                return ['success' => true, 'message' => 'Verzeichnis erreichbar (lokal gemountet).'];
            }

            return ['success' => false, 'message' => 'smbclient ist nicht installiert und der Pfad ist nicht direkt erreichbar. Bitte smbclient installieren oder die Freigabe mounten.'];
        }

        // \\server\share\unter\ordner -> //server/share + Unterverzeichnis
        $parts = preg_split('#[\\\\/]+#', trim($path, '\\/')) ?: [];
        if (count($parts) < 2) {
            return ['success' => false, 'message' => 'Ungültiger UNC-Pfad: ' . $path];
        }
        $service = '//' . $parts[0] . '/' . $parts[1];
        $subDir = implode('/', array_slice($parts, 2));

        $cmd = ['smbclient', $service, '-c', $subDir !== '' ? 'cd "' . $subDir . '"; ls' : 'ls'];
        if ($username !== '') {
            $cmd[] = '-U';
            $cmd[] = $username . '%' . $password;
            if ($domain !== '') {
                $cmd[] = '-W';
                $cmd[] = $domain;
            }
        } else {
            $cmd[] = '-N';
        }

        $result = $this->exec($cmd);
        if ($result['code'] !== 0) {
            return ['success' => false, 'message' => 'Zugriff fehlgeschlagen: ' . $this->firstLine($result['output'])];
        }

        return ['success' => true, 'message' => 'Verbindung zu ' . $service . ' erfolgreich.'];
    }

    /** Freigabe-Wurzel (\\Server\Freigabe) aus einem UNC-Pfad extrahieren. */
    private function shareRoot(string $path): string
    {
        preg_match('#^(\\\\\\\\[^\\\\/]+\\\\[^\\\\/]+)#', $path, $m);

        return $m[1] ?? $path;
    }

    private function probeWrite(string $path): bool
    {
        $probe = rtrim($path, '\\/') . DIRECTORY_SEPARATOR . '.patsign_write_test_' . bin2hex(random_bytes(4));
        $handle = @fopen($probe, 'w');
        if ($handle === false) {
            return false;
        }
        fclose($handle);
        @unlink($probe);

        return true;
    }

    /**
     * @param array<int,string> $cmd
     * @return array{code:int,output:string}
     */
    private function exec(array $cmd): array
    {
        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return ['code' => 1, 'output' => 'Prozess konnte nicht gestartet werden.'];
        }

        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return ['code' => $code, 'output' => trim($output)];
    }

    private function firstLine(string $output): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $output))));

        return $lines[0] ?? 'Unbekannter Fehler';
    }
}
