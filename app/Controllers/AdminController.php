<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\DocumentTypeRepository;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\PasswordHasher;
use App\Services\LocalAiClient;
use App\Services\MailService;
use App\Services\NetworkShareService;
use App\Services\PromptService;
use App\Services\SettingsService;
use App\Services\DeviceService;

final class AdminController extends BaseController
{
    /** @var array<string,string> */
    private const SECTIONS = [
        'general' => 'Allgemein',
        'ai' => 'KI',
        'document-types' => 'Dokumenttypen',
        'import' => 'Import',
        'export' => 'Export',
        'smtp' => 'SMTP',
        'logging' => 'Logging',
        'users' => 'Benutzer',
        'roles' => 'Rollen',
        'devices' => 'Geräteverwaltung',
        'system' => 'Systemeinstellungen',
    ];

    public function __construct(
        View $view,
        private readonly PromptService $prompts,
        private readonly SettingsService $settings,
        private readonly DocumentTypeRepository $documentTypes,
        private readonly UserRepository $users,
        private readonly RoleRepository $roles,
        private readonly PasswordHasher $hasher,
        private readonly MailService $mail,
        private readonly NetworkShareService $networkShare,
        private readonly DeviceService $devices,
        private readonly CsrfTokenManager $csrf
    ) {
        parent::__construct($view);
    }

    public function section(string $section): Response
    {
        if (!isset(self::SECTIONS[$section])) {
            return new Response('Nicht gefunden', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $data = [
            'csrf' => $this->csrf->token(),
            'user' => $_SESSION['auth_user'] ?? [],
            'section' => $section,
            'sections' => self::SECTIONS,
            'sectionTitle' => self::SECTIONS[$section],
            'settings' => $this->settings,
            'flash' => $this->pullFlash(),
        ];

        if ($section === 'ai') {
            $data['activePrompts'] = [
                'vision' => $this->prompts->getActivePrompt('vision'),
                'analysis' => $this->prompts->getActivePrompt('analysis'),
            ];
        }
        if ($section === 'document-types') {
            $data['documentTypes'] = $this->documentTypes->all();
        }
        if ($section === 'users') {
            $data['users'] = $this->users->all();
            $data['roles'] = $this->roles->all();
        }
        if ($section === 'roles') {
            $data['roles'] = $this->roles->all();
        }
        if ($section === 'devices') {
            $data['devices'] = $this->devices->overview();
            $data['activeSessions'] = $this->devices->activeSessions();
            $data['assignmentLog'] = $this->devices->assignmentLog();
            $data['deviceHistory'] = $this->devices->historyLog();
        }

        return $this->render('admin.' . str_replace('-', '_', $section), $data);
    }

    public function saveSettings(Request $request): Response
    {
        $section = (string) $request->input('section');
        $allowed = $this->allowedKeys($section);
        if ($allowed === []) {
            return $this->json(['error' => 'Unbekannter Bereich'], 422);
        }

        $values = [];
        foreach ($allowed as $field => $key) {
            $value = $request->input($field);
            if ($value !== null) {
                $values[$key] = (string) $value;
            }
        }

        // Checkboxen: fehlende Werte als "0" speichern.
        foreach ($this->checkboxFields($section) as $field => $key) {
            $values[$key] = filter_var($request->input($field, false), FILTER_VALIDATE_BOOL) ? '1' : '0';
        }

        $this->settings->setMany($values);
        $this->flash('Einstellungen gespeichert.');

        return Response::redirect('/admin/' . $section);
    }

    public function sendTestMail(Request $request): Response
    {
        $to = trim((string) $request->input('to'));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->flash('Bitte eine gültige E-Mail-Adresse angeben.', 'error');
            return Response::redirect('/admin/smtp');
        }

        try {
            $this->mail->sendTestMail($to);
            $this->flash('Testmail wurde an ' . $to . ' gesendet.');
        } catch (\Throwable $e) {
            $this->flash('Testmail fehlgeschlagen: ' . $e->getMessage(), 'error');
        }

        return Response::redirect('/admin/smtp');
    }

    /** Verfügbare Modelle eines KI-Endpunkts laden (AJAX). */
    public function fetchAiModels(Request $request): Response
    {
        try {
            $models = $this->aiClientFromRequest($request)->listModels();

            return $this->json(['models' => $models]);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 502);
        }
    }

    /** Verbindung zu einem KI-Endpunkt testen (AJAX). */
    public function testAiEndpoint(Request $request): Response
    {
        try {
            return $this->json($this->aiClientFromRequest($request)->testConnection());
        } catch (\Throwable $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], 502);
        }
    }

    /** Zugriff auf ein Netzwerkverzeichnis (Import/Export) testen (AJAX). */
    public function testNetworkShare(Request $request): Response
    {
        try {
            return $this->json($this->networkShare->testConnection(
                trim((string) $request->input('path')),
                trim((string) $request->input('domain', '')),
                trim((string) $request->input('username', '')),
                (string) $request->input('password', '')
            ));
        } catch (\Throwable $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], 502);
        }
    }

    private function aiClientFromRequest(Request $request): LocalAiClient
    {
        return new LocalAiClient([
            'host' => trim((string) $request->input('host')),
            'port' => (int) $request->input('port'),
            'api_key' => (string) $request->input('api_key', ''),
            'model' => (string) $request->input('model', ''),
            'timeout' => min(30, max(1, (int) $request->input('timeout', 10))),
        ]);
    }

    public function saveDocumentType(Request $request): Response
    {
        $action = (string) $request->input('action', 'create');
        $id = (int) $request->input('id');
        $name = trim((string) $request->input('name'));

        try {
            if ($action === 'delete' && $id > 0) {
                $this->documentTypes->delete($id);
                $this->flash('Dokumenttyp gelöscht.');
            } elseif ($action === 'update' && $id > 0 && $name !== '') {
                $this->documentTypes->update($id, $name);
                $this->flash('Dokumenttyp aktualisiert.');
            } elseif ($name !== '') {
                $this->documentTypes->create($name);
                $this->flash('Dokumenttyp angelegt.');
            } else {
                $this->flash('Bitte einen Namen angeben.', 'error');
            }
        } catch (\Throwable) {
            $this->flash('Aktion fehlgeschlagen. Existiert der Name bereits?', 'error');
        }

        return Response::redirect('/admin/document-types');
    }

    public function saveUser(Request $request): Response
    {
        $action = (string) $request->input('action', 'create');
        $id = (int) $request->input('id');
        $username = trim((string) $request->input('username'));
        $password = (string) $request->input('password', '');
        $roleId = (int) $request->input('role_id');
        $isActive = filter_var($request->input('is_active', false), FILTER_VALIDATE_BOOL);
        $currentUserId = (int) ($_SESSION['auth_user']['id'] ?? 0);

        try {
            if ($action === 'delete' && $id > 0) {
                if ($id === $currentUserId) {
                    $this->flash('Sie können Ihr eigenes Konto nicht löschen.', 'error');
                } else {
                    $this->users->delete($id);
                    $this->flash('Benutzer gelöscht.');
                }
            } elseif ($action === 'update' && $id > 0 && $username !== '' && $roleId > 0) {
                $hash = $password !== '' ? $this->hasher->hash($password) : null;
                $this->users->update($id, $username, $roleId, $isActive, $hash);
                $this->flash('Benutzer aktualisiert.');
            } elseif ($username !== '' && $password !== '' && $roleId > 0) {
                $this->users->create($username, $this->hasher->hash($password), $roleId, $isActive);
                $this->flash('Benutzer angelegt.');
            } else {
                $this->flash('Bitte alle Pflichtfelder ausfüllen.', 'error');
            }
        } catch (\Throwable) {
            $this->flash('Aktion fehlgeschlagen. Existiert der Benutzername bereits?', 'error');
        }

        return Response::redirect('/admin/users');
    }

    public function saveRole(Request $request): Response
    {
        $action = (string) $request->input('action', 'create');
        $id = (int) $request->input('id');
        $name = trim((string) $request->input('name'));

        try {
            if ($action === 'delete' && $id > 0) {
                if ($this->roles->delete($id)) {
                    $this->flash('Rolle gelöscht.');
                } else {
                    $this->flash('Rolle wird noch von Benutzern verwendet.', 'error');
                }
            } elseif ($action === 'update' && $id > 0 && $name !== '') {
                $this->roles->update($id, $name);
                $this->flash('Rolle aktualisiert.');
            } elseif ($name !== '') {
                $this->roles->create($name);
                $this->flash('Rolle angelegt.');
            } else {
                $this->flash('Bitte einen Namen angeben.', 'error');
            }
        } catch (\Throwable) {
            $this->flash('Aktion fehlgeschlagen. Existiert der Name bereits?', 'error');
        }

        return Response::redirect('/admin/roles');
    }

    /** Verwaltungsaktionen für registrierte Signaturgeräte. */
    public function deviceAction(Request $request): Response
    {
        $action = (string) $request->input('action', '');
        $id = (int) $request->input('id');
        $userId = isset($_SESSION['auth_user']['id']) ? (int) $_SESSION['auth_user']['id'] : null;

        if ($id <= 0) {
            $this->flash('Ungültiges Gerät.', 'error');

            return Response::redirect('/admin/devices');
        }

        try {
            match ($action) {
                'rename' => $this->devices->rename($id, (string) $request->input('name', ''), $userId),
                'lock' => $this->devices->setStatus($id, 'locked', $userId),
                'unlock', 'activate' => $this->devices->setStatus($id, 'active', $userId),
                'retire' => $this->devices->setStatus($id, 'retired', $userId),
                'reset' => $this->devices->reset($id, $userId),
                'delete' => $this->devices->delete($id, $userId),
                'end_session' => $this->devices->endSession($id, $userId),
                default => throw new \InvalidArgumentException('Unbekannte Aktion.'),
            };
            $this->flash('Aktion ausgeführt.');
        } catch (\InvalidArgumentException $e) {
            $this->flash($e->getMessage(), 'error');
        } catch (\Throwable) {
            $this->flash('Aktion fehlgeschlagen.', 'error');
        }

        return Response::redirect('/admin/devices');
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

        $this->flash('Prompt gespeichert (Version aktiviert).');

        return Response::redirect('/admin/ai');
    }

    /** @return array<string,string> Feldname => Settings-Schlüssel */
    private function allowedKeys(string $section): array
    {
        return match ($section) {
            'general' => [
                'clinic_name' => 'general.clinic_name',
                'logo_text' => 'general.logo_text',
                'primary_color' => 'general.primary_color',
                'accent_color' => 'general.accent_color',
            ],
            'ai' => [
                'vision_host' => 'ai.vision.host',
                'vision_port' => 'ai.vision.port',
                'vision_api_key' => 'ai.vision.api_key',
                'vision_model' => 'ai.vision.model',
                'vision_timeout' => 'ai.vision.timeout',
                'analysis_host' => 'ai.analysis.host',
                'analysis_port' => 'ai.analysis.port',
                'analysis_api_key' => 'ai.analysis.api_key',
                'analysis_model' => 'ai.analysis.model',
                'analysis_timeout' => 'ai.analysis.timeout',
            ],
            'import' => [
                'import_path' => 'app.import_watch_path',
                'import_domain' => 'import.share_domain',
                'import_username' => 'import.share_username',
                'import_password' => 'import.share_password',
                'polling_interval' => 'import.polling_interval',
            ],
            'export' => [
                'network_share' => 'app.network_share_path',
                'export_domain' => 'export.share_domain',
                'export_username' => 'export.share_username',
                'export_password' => 'export.share_password',
                'file_naming' => 'export.file_naming',
            ],
            'smtp' => [
                'host' => 'mail.host',
                'port' => 'mail.port',
                'username' => 'mail.username',
                'password' => 'mail.password',
                'encryption' => 'mail.encryption',
                'from' => 'mail.from',
                'from_name' => 'mail.from_name',
            ],
            'logging' => [
                'log_level' => 'logging.level',
                'retention_days' => 'logging.retention_days',
                'log_path' => 'logging.path',
            ],
            'system' => [
                'language' => 'system.language',
                'timezone' => 'system.timezone',
            ],
            default => [],
        };
    }

    /** @return array<string,string> */
    private function checkboxFields(string $section): array
    {
        return match ($section) {
            'import' => ['auto_import' => 'import.auto_import'],
            'export' => ['pdfa_enabled' => 'export.pdfa_enabled'],
            'system' => ['maintenance_mode' => 'system.maintenance_mode', 'debug_mode' => 'system.debug_mode'],
            default => [],
        };
    }

    private function flash(string $message, string $type = 'success'): void
    {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
    }

    /** @return array{message:string,type:string}|null */
    private function pullFlash(): ?array
    {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        return is_array($flash) ? $flash : null;
    }
}
