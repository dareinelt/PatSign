<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Security\CsrfTokenManager;
use App\Services\PromptService;

final class AdminController extends BaseController
{
    public function __construct(
        \App\Core\View $view,
        private readonly PromptService $prompts,
        private readonly Config $config,
        private readonly CsrfTokenManager $csrf
    ) {
        parent::__construct($view);
    }

    public function dashboard(): Response
    {
        return $this->render('admin.dashboard', [
            'csrf' => $this->csrf->token(),
            'visionModel' => $this->config->get('ai.vision.model'),
            'analysisModel' => $this->config->get('ai.analysis.model'),
        ]);
    }

    public function updatePrompt(Request $request): Response
    {
        $id = $this->prompts->createVersion(
            (string) $request->input('type'),
            (string) $request->input('content'),
            (string) (($_SESSION['auth_user']['username'] ?? 'system'))
        );

        if (filter_var($request->input('activate', true), FILTER_VALIDATE_BOOL)) {
            $this->prompts->activateVersion($id);
        }

        return $this->json(['message' => 'Prompt gespeichert', 'id' => $id]);
    }
}
