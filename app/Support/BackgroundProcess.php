<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Startet PHP-CLI-Skripte als losgelöste Hintergrundprozesse,
 * damit HTTP-Requests nicht durch lange Verarbeitung blockiert werden.
 */
final class BackgroundProcess
{
    /** @param list<string> $args */
    public static function runPhpScript(string $script, array $args = []): void
    {
        // Unter mod_php zeigt PHP_BINARY auf den Webserver – dann CLI-php aus dem PATH nutzen.
        $php = str_starts_with(basename(PHP_BINARY), 'php') ? PHP_BINARY : 'php';
        $command = escapeshellarg($php) . ' ' . escapeshellarg($script);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            pclose(popen('start /B "" ' . $command, 'r'));
            return;
        }

        exec($command . ' > /dev/null 2>&1 &');
    }
}
