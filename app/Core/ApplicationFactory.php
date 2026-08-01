<?php

declare(strict_types=1);

namespace App\Core;

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\DeviceController;
use App\Controllers\DocumentController;
use App\Controllers\KioskController;
use App\Controllers\PatientController;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RouteGuardMiddleware;
use App\Middleware\SecurityHeadersMiddleware;
use App\Repositories\AuditLogRepository;
use App\Repositories\DeviceAssignmentRepository;
use App\Repositories\DeviceHistoryRepository;
use App\Repositories\DeviceRepository;
use App\Repositories\DeviceSessionRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\DocumentTypeRepository;
use App\Repositories\PromptRepository;
use App\Repositories\RoleRepository;
use App\Repositories\SignatureRepository;
use App\Repositories\SystemSettingRepository;
use App\Repositories\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\DeviceTokenManager;
use App\Security\PasswordHasher;
use App\Services\AuthService;
use App\Services\CaseNumberExtractor;
use App\Services\DeviceService;
use App\Services\DocumentAnalysisService;
use App\Services\LocalAiClient;
use App\Services\MailService;
use App\Services\NetworkShareService;
use App\Services\PdfImportService;
use App\Services\PromptService;
use App\Services\SettingsService;
use App\Services\SignatureService;
use App\Services\SystemStatusService;
use App\Support\FilenameNormalizer;

final class ApplicationFactory
{
    public static function create(string $basePath): Application
    {
        Env::load($basePath . '/.env');

        $container = new Container();
        $container->singleton(Config::class, fn () => new Config($basePath . '/config'));
        $container->singleton(\PDO::class, fn (Container $c) => Database::connect($c->get(Config::class)));
        $container->singleton(View::class, fn () => new View($basePath . '/resources/views'));
        $container->singleton(CsrfTokenManager::class, fn () => new CsrfTokenManager());
        $container->singleton(PasswordHasher::class, fn () => new PasswordHasher());
        $container->singleton(UserRepository::class, fn (Container $c) => new UserRepository($c->get(\PDO::class)));
        $container->singleton(PromptRepository::class, fn (Container $c) => new PromptRepository($c->get(\PDO::class)));
        $container->singleton(DocumentRepository::class, fn (Container $c) => new DocumentRepository($c->get(\PDO::class)));
        $container->singleton(DocumentTypeRepository::class, fn (Container $c) => new DocumentTypeRepository($c->get(\PDO::class)));
        $container->singleton(RoleRepository::class, fn (Container $c) => new RoleRepository($c->get(\PDO::class)));
        $container->singleton(SignatureRepository::class, fn (Container $c) => new SignatureRepository($c->get(\PDO::class)));
        $container->singleton(AuditLogRepository::class, fn (Container $c) => new AuditLogRepository($c->get(\PDO::class)));
        $container->singleton(SystemSettingRepository::class, fn (Container $c) => new SystemSettingRepository($c->get(\PDO::class)));
        $container->singleton(DeviceRepository::class, fn (Container $c) => new DeviceRepository($c->get(\PDO::class)));
        $container->singleton(DeviceAssignmentRepository::class, fn (Container $c) => new DeviceAssignmentRepository($c->get(\PDO::class)));
        $container->singleton(DeviceSessionRepository::class, fn (Container $c) => new DeviceSessionRepository($c->get(\PDO::class)));
        $container->singleton(DeviceHistoryRepository::class, fn (Container $c) => new DeviceHistoryRepository($c->get(\PDO::class)));
        $container->singleton(DeviceTokenManager::class, fn () => new DeviceTokenManager());
        $container->singleton(DeviceService::class, fn (Container $c) => new DeviceService(
            $c->get(DeviceRepository::class),
            $c->get(DeviceAssignmentRepository::class),
            $c->get(DeviceSessionRepository::class),
            $c->get(DeviceHistoryRepository::class),
            $c->get(DocumentRepository::class),
            $c->get(AuditLogRepository::class),
            $c->get(DeviceTokenManager::class)
        ));
        $container->singleton(SettingsService::class, fn (Container $c) => new SettingsService($c->get(SystemSettingRepository::class), $c->get(Config::class)));
        $container->singleton(SystemStatusService::class, fn (Container $c) => new SystemStatusService($c->get(\PDO::class), $c->get(SettingsService::class), $c->get(NetworkShareService::class)));
        $container->singleton(MailService::class, fn (Container $c) => new MailService($c->get(SettingsService::class)));
        $container->singleton(NetworkShareService::class, fn () => new NetworkShareService());
        $container->singleton(PromptService::class, fn (Container $c) => new PromptService($c->get(PromptRepository::class)));
        $container->singleton(AuthService::class, fn (Container $c) => new AuthService($c->get(UserRepository::class), $c->get(PasswordHasher::class)));
        $container->singleton(CaseNumberExtractor::class, fn () => new CaseNumberExtractor());
        $container->singleton(FilenameNormalizer::class, fn () => new FilenameNormalizer());
        $container->singleton(SignatureService::class, fn (Container $c) => new SignatureService($c->get(FilenameNormalizer::class)));
        $container->singleton(LocalAiClient::class . '.vision', fn (Container $c) => new LocalAiClient(self::aiConfig($c->get(SettingsService::class), 'vision')));
        $container->singleton(LocalAiClient::class . '.analysis', fn (Container $c) => new LocalAiClient(self::aiConfig($c->get(SettingsService::class), 'analysis')));
        $container->singleton(DocumentAnalysisService::class, fn (Container $c) => new DocumentAnalysisService(
            $c->get(LocalAiClient::class . '.vision'),
            $c->get(LocalAiClient::class . '.analysis'),
            $c->get(PromptService::class),
            $c->get(CaseNumberExtractor::class)
        ));
        $container->singleton(PdfImportService::class, fn (Container $c) => new PdfImportService(
            $c->get(SettingsService::class)->getString('app.import_watch_path'),
            (int) $c->get(Config::class)->get('app.max_upload_bytes'),
            (array) $c->get(Config::class)->get('app.allowed_upload_mime'),
            $c->get(NetworkShareService::class),
            [
                'domain' => $c->get(SettingsService::class)->getString('import.share_domain'),
                'username' => $c->get(SettingsService::class)->getString('import.share_username'),
                'password' => $c->get(SettingsService::class)->getString('import.share_password'),
            ]
        ));

        self::ensureDefaultAdmin($container->get(\PDO::class), $container->get(PasswordHasher::class));

        $session = new SessionManager($container->get(Config::class));
        $session->start();

        $authController = new AuthController($container->get(View::class), $container->get(AuthService::class), $container->get(CsrfTokenManager::class));
        $documentController = new DocumentController(
            $container->get(View::class),
            $container->get(PdfImportService::class),
            $container->get(DocumentAnalysisService::class),
            $container->get(SignatureService::class),
            $container->get(DocumentRepository::class),
            $container->get(AuditLogRepository::class),
            $container->get(Config::class),
            $container->get(CsrfTokenManager::class)
        );
        $adminController = new AdminController(
            $container->get(View::class),
            $container->get(PromptService::class),
            $container->get(SettingsService::class),
            $container->get(DocumentTypeRepository::class),
            $container->get(UserRepository::class),
            $container->get(RoleRepository::class),
            $container->get(PasswordHasher::class),
            $container->get(MailService::class),
            $container->get(NetworkShareService::class),
            $container->get(DeviceService::class),
            $container->get(CsrfTokenManager::class)
        );
        $dashboardController = new DashboardController(
            $container->get(View::class),
            $container->get(DocumentRepository::class),
            $container->get(AuditLogRepository::class),
            $container->get(SystemStatusService::class),
            $container->get(SettingsService::class),
            $container->get(DeviceService::class),
            $container->get(CsrfTokenManager::class)
        );
        $patientController = new PatientController(
            $container->get(View::class),
            $container->get(DocumentRepository::class),
            $container->get(SignatureRepository::class),
            $container->get(SignatureService::class),
            $container->get(AuditLogRepository::class),
            $container->get(SettingsService::class),
            $container->get(MailService::class),
            $container->get(CsrfTokenManager::class)
        );
        $kioskController = new KioskController(
            $container->get(View::class),
            $container->get(DeviceService::class),
            $container->get(DocumentRepository::class),
            $container->get(SignatureRepository::class),
            $container->get(SignatureService::class),
            $container->get(SettingsService::class),
            $container->get(MailService::class),
            $container->get(CsrfTokenManager::class),
            $container->get(Config::class)
        );
        $deviceController = new DeviceController(
            $container->get(View::class),
            $container->get(DeviceService::class)
        );

        $router = new Router();
        (require $basePath . '/routes/web.php')($router, $authController, $documentController, $adminController, $dashboardController, $patientController, $kioskController, $deviceController);

        $middleware = [
            new SecurityHeadersMiddleware($container->get(Config::class)),
            new RouteGuardMiddleware(),
            new CsrfMiddleware($container->get(CsrfTokenManager::class)),
        ];

        return new Application($router, $middleware);
    }

    /** @return array<string,mixed> */
    private static function aiConfig(SettingsService $settings, string $type): array
    {
        return [
            'host' => $settings->getString('ai.' . $type . '.host'),
            'port' => $settings->getInt('ai.' . $type . '.port'),
            'api_key' => $settings->getString('ai.' . $type . '.api_key'),
            'model' => $settings->getString('ai.' . $type . '.model'),
            'timeout' => $settings->getInt('ai.' . $type . '.timeout', 60),
        ];
    }

    private static function ensureDefaultAdmin(\PDO $pdo, PasswordHasher $hasher): void
    {
        try {
            $usersTable = $pdo->query("SHOW TABLES LIKE 'users'");
            $rolesTable = $pdo->query("SHOW TABLES LIKE 'roles'");

            if ($usersTable === false || $rolesTable === false || $usersTable->fetchColumn() === false || $rolesTable->fetchColumn() === false) {
                return;
            }

            $adminExistsStmt = $pdo->prepare("SELECT 1 FROM users WHERE username = :username LIMIT 1");
            $adminExistsStmt->execute(['username' => 'admin']);
            if ($adminExistsStmt->fetchColumn() !== false) {
                return;
            }

            $roleStmt = $pdo->prepare("SELECT id FROM roles WHERE name = :name LIMIT 1");
            $roleStmt->execute(['name' => 'admin']);
            $roleId = $roleStmt->fetchColumn();

            if ($roleId === false) {
                $insertRoleStmt = $pdo->prepare("INSERT INTO roles (name) VALUES (:name)");
                $insertRoleStmt->execute(['name' => 'admin']);
                $roleId = $pdo->lastInsertId();
            }

            $insertUserStmt = $pdo->prepare('INSERT INTO users (username, password_hash, role_id, is_active) VALUES (:username, :password_hash, :role_id, 1)');
            $insertUserStmt->execute([
                'username' => 'admin',
                'password_hash' => $hasher->hash('admin'),
                'role_id' => (int) $roleId,
            ]);
        } catch (\Throwable) {
            // Ignore bootstrap auto-seeding errors and continue normal startup.
        }
    }
}
