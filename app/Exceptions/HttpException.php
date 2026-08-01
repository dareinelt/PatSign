<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class HttpException extends RuntimeException
{
    public function __construct(string $message, private readonly int $statusCode = 500)
    {
        parent::__construct($message, $statusCode);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
