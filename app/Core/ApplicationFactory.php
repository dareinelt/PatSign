<?php

declare(strict_types=1);

namespace App\Core;

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\DocumentController;
use App\Middleware\CsrfMiddleware;
use App\Middleware\SecurityHeadersMiddleware;
use App\Repositories\PromptRepository;
use App\Repositories\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\PasswordHasher;
use App\Services\AuthService;
use App\Services\CaseNumberExtractor;
use App\Services\DocumentAnalysisService;
use App\Services\LocalAiClient;
use App\Services\PdfImportService;
use App\Services\PromptService;
use App\Services\SignatureService;
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
        $container->singleton(PromptService::class, fn (Container $c) => new PromptService($c->get(PromptRepository::class)));
        $container->singleton(AuthService::class, fn (Container $c) => new AuthService($c->get(UserRepository::class), $c->get(PasswordHasher::class)));
        $container->singleton(CaseNumberExtractor::class, fn () => new CaseNumberExtractor());
        $container->singleton(FilenameNormalizer::class, fn () => new FilenameNormalizer());
        $container->singleton(SignatureService::class, fn (Container $c) => new SignatureService($c->get(FilenameNormalizer::class)));
        $container->singleton(LocalAiClient::class . '.vision', fn (Container $c) => new LocalAiClient((array) $c->get(Config::class)->get('ai.vision')));
        $container->singleton(LocalAiClient::class . '.analysis', fn (Container $c) => new LocalAiClient((array) $c->get(Config::class)->get('ai.analysis')));
        $container->singleton(DocumentAnalysisService::class, fn (Container $c) => new DocumentAnalysisService(
            $c->get(LocalAiClient::class . '.vision'),
            $c->get(LocalAiClient::class . '.analysis'),
            $c->get(PromptService::class),
            $c->get(CaseNumberExtractor::class)
        ));
        $container->singleton(PdfImportService::class, fn (Container $c) => new PdfImportService(
            (string) $c->get(Config::class)->get('app.import_watch_path'),
            (int) $c->get(Config::class)->get('app.max_upload_bytes'),
            (array) $c->get(Config::class)->get('app.allowed_upload_mime')
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
            $container->get(Config::class),
            $container->get(CsrfTokenManager::class)
        );
        $adminController = new AdminController(
            $container->get(View::class),
            $container->get(PromptService::class),
            $container->get(Config::class),
            $container->get(CsrfTokenManager::class)
        );

        $router = new Router();
        (require $basePath . '/routes/web.php')($router, $authController, $documentController, $adminController);

        $middleware = [
            new SecurityHeadersMiddleware($container->get(Config::class)),
            new CsrfMiddleware($container->get(CsrfTokenManager::class)),
        ];

        return new Application($router, $middleware);
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
