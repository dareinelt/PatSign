<?php

declare(strict_types=1);

namespace App\Core;

use App\Exceptions\HttpException;
use Throwable;

final class Application
{
    /** @param array<int,callable(Request,callable(Request):Response):Response> $middleware */
    public function __construct(
        private readonly Router $router,
        private readonly array $middleware = []
    ) {}

    public function run(): void
    {
        try {
            $request = Request::capture();
            $this->router->dispatch($request, $this->middleware)->send();
        } catch (HttpException $exception) {
            (new Response($exception->getMessage(), $exception->statusCode(), ['Content-Type' => 'text/plain; charset=utf-8']))->send();
        } catch (Throwable $exception) {
            (new Response('Interner Serverfehler', 500, ['Content-Type' => 'text/plain; charset=utf-8']))->send();
        }
    }
}
