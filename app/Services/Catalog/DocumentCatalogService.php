<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Repositories\AuditLogRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\DocumentTemplateRepository;
use App\Repositories\PatientFolderRepository;
use App\Services\SettingsService;
use App\Support\BackgroundProcess;
use RuntimeException;

/**
 * Dokumentenkatalog: verwaltet PDF-Vorlagen (anlegen, versionieren,
 * aktivieren/archivieren) und erzeugt beim Hinzufügen zu einer
 * Patientenmappe eine personalisierte Arbeitskopie, die anschließend
 * denselben Workflow durchläuft wie importierte Dokumente.
 */
final class DocumentCatalogService
{
    /** Unterstützte Platzhalter der PDF-Vorlagen. */
    /** Unterstützte Platzhalter mit Beschreibung (für Admin-UI und Doku). */
    public const PLACEHOLDERS = [
        'CASE_NUMBER' => 'Fallnummer des Patienten',
        'FIRST_NAME' => 'Vorname des Patienten',
        'LAST_NAME' => 'Nachname des Patienten',
        'FULL_NAME' => 'Vollständiger Name (Vorname Nachname)',
        'BIRTH_DATE' => 'Geburtsdatum (TT.MM.JJJJ)',
        'CURRENT_DATE' => 'Aktuelles Datum (TT.MM.JJJJ)',
        'CLINIC_NAME' => 'Name der Klinik (Einstellung app.clinic_name)',
        'WARD' => 'Station (sofern hinterlegt)',
        'EMPLOYEE' => 'Name des hinzufügenden Mitarbeiters',
    ];

    public function __construct(
        private readonly DocumentTemplateRepository $templates,
        private readonly DocumentRepository $documents,
        private readonly PatientFolderRepository $folders,
        private readonly PdfPlaceholderService $placeholders,
        private readonly AuditLogRepository $auditLogs,
        private readonly SettingsService $settings,
        private readonly int $maxUploadBytes,
        private readonly string $templateStoragePath,
        private readonly string $processedPath,
        private readonly string $basePath
    ) {}

    /* ------------------------------------------------------------------ */
    /* Vorlagenverwaltung                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * Neue Vorlage aus einem Upload anlegen (Version 1).
     *
     * @param array<string,mixed> $file $_FILES-Eintrag
     * @param array{name:string,description:?string,document_type:string,category_id:?int,is_active:bool} $meta
     */
    public function createTemplate(array $file, array $meta, ?int $userId): int
    {
        $upload = $this->acceptUpload($file);
        $uuid = self::uuid();

        $templateId = $this->templates->create([
            'template_uuid' => $uuid,
            'name' => $meta['name'],
            'description' => $meta['description'],
            'document_type' => $meta['document_type'],
            'category_id' => $meta['category_id'],
            'is_active' => $meta['is_active'] ? 1 : 0,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        $this->storeVersionFile($templateId, $uuid, $upload, $userId);
        $this->audit('template_created', ['template_id' => $templateId, 'name' => $meta['name'], 'file' => $upload['name']], $userId);

        return $templateId;
    }

    /**
     * Vorlage durch neue PDF-Datei ersetzen: erzeugt eine neue Version,
     * bestehende Versionen bleiben unverändert erhalten.
     *
     * @param array<string,mixed> $file
     */
    public function replaceTemplateFile(int $templateId, array $file, ?int $userId): int
    {
        $template = $this->requireTemplate($templateId);
        $upload = $this->acceptUpload($file);

        $version = $this->storeVersionFile($templateId, (string) $template['template_uuid'], $upload, $userId);
        $this->audit('template_versioned', ['template_id' => $templateId, 'name' => $template['name'], 'version' => $version, 'file' => $upload['name']], $userId);

        return $version;
    }

    public function updateMetadata(int $templateId, string $name, ?string $description, string $documentType, ?int $categoryId, ?int $userId): void
    {
        $this->requireTemplate($templateId);
        $this->templates->updateMetadata($templateId, $name, $description, $documentType, $categoryId, $userId);
        $this->audit('template_updated', ['template_id' => $templateId, 'name' => $name], $userId);
    }

    public function setActive(int $templateId, bool $active, ?int $userId): void
    {
        $template = $this->requireTemplate($templateId);
        $this->templates->setActive($templateId, $active, $userId);
        $this->audit($active ? 'template_activated' : 'template_deactivated', ['template_id' => $templateId, 'name' => $template['name']], $userId);
    }

    public function setArchived(int $templateId, bool $archived, ?int $userId): void
    {
        $template = $this->requireTemplate($templateId);
        $this->templates->setArchived($templateId, $archived, $userId);
        $this->audit($archived ? 'template_archived' : 'template_restored', ['template_id' => $templateId, 'name' => $template['name']], $userId);
    }

    /** Löschen nur bei Nichtverwendung; entfernt auch die Vorlagendateien. */
    public function deleteTemplate(int $templateId, ?int $userId): bool
    {
        $template = $this->requireTemplate($templateId);
        $versions = $this->templates->versions($templateId);

        if (!$this->templates->delete($templateId)) {
            return false;
        }

        foreach ($versions as $version) {
            @unlink((string) $version['file_path']);
        }
        $dir = $this->templateDirectory((string) $template['template_uuid']);
        if (is_dir($dir)) {
            @rmdir($dir);
        }

        $this->audit('template_deleted', ['template_id' => $templateId, 'name' => $template['name']], $userId);

        return true;
    }

    public function saveCategory(string $action, int $id, string $name, ?int $userId): string
    {
        if ($action === 'delete' && $id > 0) {
            if (!$this->templates->deleteCategory($id)) {
                throw new RuntimeException('Kategorie wird noch von Vorlagen verwendet.');
            }
            $this->audit('catalog_category_deleted', ['category_id' => $id], $userId);

            return 'Kategorie gelöscht.';
        }
        if ($name === '') {
            throw new RuntimeException('Bitte einen Namen angeben.');
        }
        if ($action === 'update' && $id > 0) {
            $this->templates->updateCategory($id, $name);
            $this->audit('catalog_category_updated', ['category_id' => $id, 'name' => $name], $userId);

            return 'Kategorie aktualisiert.';
        }
        $id = $this->templates->createCategory($name);
        $this->audit('catalog_category_created', ['category_id' => $id, 'name' => $name], $userId);

        return 'Kategorie angelegt.';
    }

    /* ------------------------------------------------------------------ */
    /* Verwendung im Workflow                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Fügt Vorlagen als personalisierte Arbeitskopien einer Patientenmappe hinzu.
     * Die Kopien erhalten eine eigene Dokument-ID, den Verweis auf die
     * verwendete Vorlagenversion und laufen anschließend wie jedes andere
     * Dokument durch Signatur, Export und Archivierung.
     *
     * @param list<int> $templateIds
     * @return list<array<string,mixed>> Angelegte Dokumente
     */
    public function addToFolder(string $caseNumber, array $templateIds, ?int $userId, string $employeeName = ''): array
    {
        $context = $this->folderContext($caseNumber);
        $created = [];

        foreach ($templateIds as $templateId) {
            $template = $this->requireTemplate((int) $templateId);
            if ((int) $template['is_active'] !== 1 || (int) $template['is_archived'] === 1) {
                throw new RuntimeException('Vorlage "' . $template['name'] . '" ist nicht aktiv.');
            }
            $version = $this->templates->currentVersion((int) $template['id']);
            if ($version === null || !is_file((string) $version['file_path'])) {
                throw new RuntimeException('Vorlagendatei zu "' . $template['name'] . '" wurde nicht gefunden.');
            }

            $documentUuid = self::uuid();
            $outputPath = rtrim($this->processedPath, '/\\') . DIRECTORY_SEPARATOR . 'catalog_' . $documentUuid . '.pdf';
            $result = $this->placeholders->personalize(
                (string) $version['file_path'],
                $this->placeholderValues($context, $employeeName),
                $outputPath,
                $this->settings->getString('catalog.placeholder_default', '')
            );

            $validate = $this->settings->getBool('catalog.validation_enabled', false);
            $documentId = $this->documents->createFromTemplate([
                'document_id' => $documentUuid,
                'original_path' => $result['path'],
                'document_type' => (string) $template['document_type'],
                'case_number' => $context['case_number'],
                'first_name' => $context['first_name'],
                'last_name' => $context['last_name'],
                'birth_date' => $context['birth_date'],
                'patient_key' => hash('sha256', implode('|', [
                    (string) $context['last_name'],
                    (string) $context['first_name'],
                    (string) $context['birth_date'],
                    (string) $context['case_number'],
                ])),
                // Metadaten sind bereits bekannt: KI-Analyse wird übersprungen,
                // außer die optionale Validierung ist aktiviert.
                'status' => $validate ? 'imported' : 'ready',
                'patient_folder_id' => $context['folder_id'],
                'template_version_id' => (int) $version['id'],
                'sort_order' => $this->documents->nextSortOrder($context['case_number']),
            ]);

            $this->audit('catalog_document_added', [
                'template_id' => (int) $template['id'],
                'template_name' => (string) $template['name'],
                'template_version' => (int) $version['version'],
                'case_number' => $context['case_number'],
                'replaced_placeholders' => $result['replaced'],
            ], $userId, $documentId);
            $this->audit('template_used', [
                'template_id' => (int) $template['id'],
                'template_version' => (int) $version['version'],
            ], $userId, $documentId);

            if ($validate) {
                $this->documents->updateStatus($documentId, 'analyzing');
                BackgroundProcess::runPhpScript($this->basePath . '/bin/process-document.php', [(string) $documentId]);
            }

            $created[] = ['id' => $documentId, 'name' => (string) $template['name']];
        }

        return $created;
    }

    /**
     * Entfernt ein noch nicht unterschriebenes Dokument aus der Mappe
     * (Status "archiviert" – nicht destruktiv, bleibt im Audit nachvollziehbar).
     */
    public function removeFromFolder(int $documentId, ?int $userId): string
    {
        $document = $this->documents->findById($documentId);
        if ($document === null) {
            throw new RuntimeException('Dokument wurde nicht gefunden.');
        }
        if (in_array((string) $document['status'], ['signed', 'sent', 'archived'], true)) {
            throw new RuntimeException('Bereits abgeschlossene Dokumente können nicht entfernt werden.');
        }

        $this->documents->updateStatus($documentId, 'archived');
        $this->audit('catalog_document_removed', [
            'case_number' => (string) ($document['case_number'] ?? ''),
            'document_type' => (string) $document['document_type'],
        ], $userId, $documentId);

        return basename((string) $document['original_path']);
    }

    /**
     * Reihenfolge der Dokumente einer Mappe neu setzen (bestimmt die Anzeige
     * für den Patienten).
     *
     * @param list<int> $orderedDocumentIds
     */
    public function reorderFolder(string $caseNumber, array $orderedDocumentIds, ?int $userId): void
    {
        $this->documents->reorderByCaseNumber($caseNumber, $orderedDocumentIds);
        $this->audit('folder_documents_reordered', [
            'case_number' => $caseNumber,
            'order' => array_values(array_map('intval', $orderedDocumentIds)),
        ], $userId);
    }

    /* ------------------------------------------------------------------ */
    /* Interna                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Patientendaten der Mappe: bevorzugt aus patient_folders, sonst aus den
     * vorhandenen Dokumenten der Fallnummer.
     *
     * @return array{folder_id:?int,case_number:string,first_name:?string,last_name:?string,birth_date:?string}
     */
    private function folderContext(string $caseNumber): array
    {
        $caseNumber = trim($caseNumber);
        if ($caseNumber === '') {
            throw new RuntimeException('Keine Fallnummer angegeben.');
        }

        $folder = $this->folders->findByCaseNumber($caseNumber);
        if ($folder !== null) {
            return [
                'folder_id' => (int) $folder['id'],
                'case_number' => $caseNumber,
                'first_name' => $folder['first_name'] !== null ? (string) $folder['first_name'] : null,
                'last_name' => $folder['last_name'] !== null ? (string) $folder['last_name'] : null,
                'birth_date' => $folder['birth_date'] !== null ? (string) $folder['birth_date'] : null,
            ];
        }

        foreach ($this->documents->findByCaseNumber($caseNumber) as $document) {
            if (!empty($document['last_name'])) {
                return [
                    'folder_id' => isset($document['patient_folder_id']) && $document['patient_folder_id'] !== null
                        ? (int) $document['patient_folder_id'] : null,
                    'case_number' => $caseNumber,
                    'first_name' => $document['first_name'] !== null ? (string) $document['first_name'] : null,
                    'last_name' => (string) $document['last_name'],
                    'birth_date' => $document['birth_date'] !== null ? (string) $document['birth_date'] : null,
                ];
            }
        }

        throw new RuntimeException('Zur Fallnummer wurde keine Patientenmappe gefunden.');
    }

    /**
     * @param array{folder_id:?int,case_number:string,first_name:?string,last_name:?string,birth_date:?string} $context
     * @return array<string,string>
     */
    private function placeholderValues(array $context, string $employeeName): array
    {
        $firstName = (string) ($context['first_name'] ?? '');
        $lastName = (string) ($context['last_name'] ?? '');
        $birthDate = (string) ($context['birth_date'] ?? '');
        if ($birthDate !== '' && ($time = strtotime($birthDate)) !== false) {
            $birthDate = date('d.m.Y', $time);
        }

        return [
            'CASE_NUMBER' => $context['case_number'],
            'FIRST_NAME' => $firstName,
            'LAST_NAME' => $lastName,
            'FULL_NAME' => trim($firstName . ' ' . $lastName),
            'BIRTH_DATE' => $birthDate,
            'CURRENT_DATE' => date('d.m.Y'),
            'CLINIC_NAME' => $this->settings->getString('general.clinic_name', ''),
            'WARD' => $this->settings->getString('general.clinic_location', ''),
            'EMPLOYEE' => $employeeName,
        ];
    }

    /**
     * Upload entgegennehmen und validieren.
     *
     * @param array<string,mixed> $file
     * @return array{tmp:string,name:string,size:int}
     */
    private function acceptUpload(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload fehlgeschlagen.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        $this->placeholders->validate($tmp, $this->maxUploadBytes);

        return [
            'tmp' => $tmp,
            'name' => basename((string) ($file['name'] ?? 'vorlage.pdf')),
            'size' => (int) ($file['size'] ?? 0),
        ];
    }

    /**
     * Vorlagendatei versioniert ablegen und Versionsdatensatz anlegen.
     *
     * @param array{tmp:string,name:string,size:int} $upload
     */
    private function storeVersionFile(int $templateId, string $templateUuid, array $upload, ?int $userId): int
    {
        $dir = $this->templateDirectory($templateUuid);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Vorlagenverzeichnis konnte nicht erstellt werden.');
        }

        $version = $this->templates->nextVersion($templateId);
        $target = $dir . DIRECTORY_SEPARATOR . 'v' . $version . '.pdf';
        if (!@move_uploaded_file($upload['tmp'], $target) && !@rename($upload['tmp'], $target)) {
            throw new RuntimeException('Vorlagendatei konnte nicht gespeichert werden.');
        }

        $found = $this->placeholders->extractPlaceholders($target);
        $this->templates->createVersion($templateId, $version, $target, $upload['name'], $upload['size'], $found, $userId);

        return $version;
    }

    private function templateDirectory(string $templateUuid): string
    {
        return rtrim($this->templateStoragePath, '/\\') . DIRECTORY_SEPARATOR . $templateUuid;
    }

    /** @return array<string,mixed> */
    private function requireTemplate(int $templateId): array
    {
        $template = $this->templates->findById($templateId);
        if ($template === null) {
            throw new RuntimeException('Vorlage wurde nicht gefunden.');
        }

        return $template;
    }

    /** @param array<string,mixed> $context */
    private function audit(string $event, array $context, ?int $userId, ?int $documentId = null): void
    {
        try {
            $this->auditLogs->log($event, $context, $userId, $documentId);
        } catch (\Throwable) {
            // Audit-Logging darf den Vorgang nicht verhindern.
        }
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
