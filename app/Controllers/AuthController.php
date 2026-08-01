<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Security\CsrfTokenManager;
use App\Services\AuthService;

final class AuthController extends BaseController
{
    public function __construct(
        \App\Core\View $view,
        private readonly AuthService $auth,
        private readonly CsrfTokenManager $csrf
    ) {
        parent::__construct($view);
    }

    public function showLogin(): Response
    {
        return $this->render('auth.login', ['csrf' => $this->csrf->token()]);
    }

    public function login(Request $request): Response
    {
        $success = $this->auth->attempt((string) $request->input('username'), (string) $request->input('password'));
        if (!$success) {
            return $this->render('auth.login', ['csrf' => $this->csrf->token(), 'error' => 'Ungültige Anmeldedaten'], 401);
        }

        return Response::redirect('/documents');
    }

    public function logout(): Response
    {
        $this->auth->logout();
        return Response::redirect('/login');
    }
}
