<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class View
{
    public function __construct(private readonly string $basePath) {}

    /** @param array<string,mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $file = rtrim($this->basePath, '/') . '/' . str_replace('.', '/', $template) . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("View {$template} not found.");
        }

        extract($data, EXTR_SKIP);
        ob_start();
        include $file;

        return (string) ob_get_clean();
    }
}
