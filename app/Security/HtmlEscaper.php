<?php

declare(strict_types=1);

namespace App\Security;

final class HtmlEscaper
{
    public function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
