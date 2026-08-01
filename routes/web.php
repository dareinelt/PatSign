<?php

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\DeviceController;
use App\Controllers\DocumentController;
use App\Controllers\KioskController;
use App\Controllers\PatientController;
use App\Core\Request;
use App\Core\Router;

return static function (
    Router $router,
    AuthController $auth,
    DocumentController $documents,
    AdminController $admin,
    DashboardController $dashboard,
    PatientController $patient,
    KioskController $kiosk,
    DeviceController $devices
): void {
    $router->get('/', static fn () => \App\Core\Response::redirect('/dashboard'));
    $router->get('/login', static fn () => $auth->showLogin());
    $router->post('/login', static fn (Request $request) => $auth->login($request));
    $router->post('/logout', static fn () => $auth->logout());

    // Dashboard (medizinisches Personal)
    $router->get('/dashboard', static fn () => $dashboard->index());
    $router->get('/dashboard/data', static fn () => $dashboard->data());
    $router->get('/dashboard/search', static fn (Request $request) => $dashboard->search($request));

    // Dokumente
    $router->get('/documents', static fn () => $documents->index());
    $router->post('/documents/upload', static fn (Request $request) => $documents->upload($request));
    $router->get('/documents/watch', static fn () => $documents->scanWatchFolder());
    $router->post('/documents/analyze', static fn (Request $request) => $documents->analyze($request));
    $router->post('/documents/sign', static fn (Request $request) => $documents->sign($request));

    // Patientenmodus
    $router->post('/patient/start', static fn (Request $request) => $patient->start($request));
    $router->get('/patient', static fn () => $patient->wizard());
    $router->get('/patient/document', static fn (Request $request) => $patient->document($request));
    $router->post('/patient/sign', static fn (Request $request) => $patient->sign($request));
    $router->post('/patient/exit', static fn () => $patient->exit());

    // Kioskmodus (registrierte Signaturgeräte, Geräteauth im Controller)
    $router->get('/kiosk', static fn (Request $request) => $kiosk->index($request));
    $router->post('/kiosk/register', static fn (Request $request) => $kiosk->register($request));
    $router->post('/kiosk/reconnect', static fn (Request $request) => $kiosk->reconnect($request));
    $router->get('/kiosk/state', static fn (Request $request) => $kiosk->state($request));
    $router->get('/kiosk/poll', static fn (Request $request) => $kiosk->poll($request));
    $router->post('/kiosk/heartbeat', static fn (Request $request) => $kiosk->heartbeat($request));
    $router->get('/kiosk/document', static fn (Request $request) => $kiosk->document($request));
    $router->post('/kiosk/sign', static fn (Request $request) => $kiosk->sign($request));

    // Geräte (medizinisches Personal)
    $router->get('/devices/overview', static fn () => $devices->overview());
    $router->post('/devices/assign', static fn (Request $request) => $devices->assign($request));

    // Administration
    $router->get('/admin', static fn () => \App\Core\Response::redirect('/admin/general'));
    foreach (['general', 'ai', 'document-types', 'import', 'export', 'smtp', 'logging', 'users', 'roles', 'devices', 'system'] as $section) {
        $router->get('/admin/' . $section, static fn () => $admin->section($section));
    }
    $router->post('/admin/devices', static fn (Request $request) => $admin->deviceAction($request));
    $router->post('/admin/settings', static fn (Request $request) => $admin->saveSettings($request));
    $router->post('/admin/smtp/test', static fn (Request $request) => $admin->sendTestMail($request));
    $router->post('/admin/ai/models', static fn (Request $request) => $admin->fetchAiModels($request));
    $router->post('/admin/ai/test', static fn (Request $request) => $admin->testAiEndpoint($request));
    $router->post('/admin/share/test', static fn (Request $request) => $admin->testNetworkShare($request));
    $router->post('/admin/document-types', static fn (Request $request) => $admin->saveDocumentType($request));
    $router->post('/admin/users', static fn (Request $request) => $admin->saveUser($request));
    $router->post('/admin/roles', static fn (Request $request) => $admin->saveRole($request));
    $router->post('/admin/prompts', static fn (Request $request) => $admin->updatePrompt($request));
};
