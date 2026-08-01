<?php

declare(strict_types=1);

namespace App\Core;

use App\Exceptions\HttpException;

final class Router
{
    /** @var array<string,array<string,callable(Request):Response>> */
    private array $routes = [];

    /** @param array<int,callable(Request,callable(Request):Response):Response> $middleware */
    public function dispatch(Request $request, array $middleware = []): Response
    {
        $method = $request->method();
        $path = rtrim($request->path(), '/') ?: '/';

        $handler = $this->routes[$method][$path] ?? null;
        if ($handler === null) {
            throw new HttpException('Route not found', 404);
        }

        $core = function (Request $request) use ($handler): Response {
            return $handler($request);
        };

        $pipeline = array_reduce(
            array_reverse($middleware),
            static fn (callable $next, callable $mw): callable => static fn (Request $request): Response => $mw($request, $next),
            $core
        );

        return $pipeline($request);
    }

    public function get(string $path, callable $handler): void
    {
        $this->routes['GET'][rtrim($path, '/') ?: '/'] = $handler;
    }

    public function post(string $path, callable $handler): void
    {
        $this->routes['POST'][rtrim($path, '/') ?: '/'] = $handler;
    }
}
