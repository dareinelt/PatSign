<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\View;

abstract class BaseController
{
    public function __construct(protected readonly View $view) {}

    /** @param array<string,mixed> $data */
    protected function render(string $template, array $data = [], int $status = 200): Response
    {
        return new Response($this->view->render($template, $data), $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    protected function json(array $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }
}
