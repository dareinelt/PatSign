<?php

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\DocumentController;
use App\Core\Request;
use App\Core\Router;

return static function (Router $router, AuthController $auth, DocumentController $documents, AdminController $admin): void {
    $router->get('/', static fn () => \App\Core\Response::redirect('/login'));
    $router->get('/login', static fn () => $auth->showLogin());
    $router->post('/login', static fn (Request $request) => $auth->login($request));
    $router->post('/logout', static fn () => $auth->logout());

    $router->get('/documents', static fn () => $documents->index());
    $router->post('/documents/upload', static fn (Request $request) => $documents->upload($request));
    $router->get('/documents/watch', static fn () => $documents->scanWatchFolder());
    $router->post('/documents/analyze', static fn (Request $request) => $documents->analyze($request));
    $router->post('/documents/sign', static fn (Request $request) => $documents->sign($request));

    $router->get('/admin', static fn () => $admin->dashboard());
    $router->post('/admin/prompts', static fn (Request $request) => $admin->updatePrompt($request));
};
