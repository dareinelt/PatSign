# PatSign - Lokale Patienten-Signaturplattform

PatSign ist eine lokal betreibbare PHP-8.4-MVC-Anwendung ohne Framework zur Digitalisierung des Patienten-Unterschriftenprozesses in Praxen, Kliniken und vergleichbaren Einrichtungen. Die Anwendung importiert PDF-Dokumente, analysiert sie über lokal konfigurierbare OpenAI-kompatible KI-Endpunkte, führt Patientinnen und Patienten durch eine reduzierte Signaturstrecke und legt signierte Dokumente revisionsorientiert ab.

Der Fokus liegt auf einem kontrollierten lokalen Betrieb: keine CDN-Abhängigkeiten, keine Cloud-Pflicht für KI, rollenbasierte Bedienoberflächen, nachvollziehbare Audit-Logs und ein Kioskmodus für iPads oder andere Tablets als dedizierte Signaturterminals.

## Funktionsumfang

### Dashboard für medizinisches Personal

Das Dashboard unter `/dashboard` ist die zentrale Arbeitsoberfläche für den Praxis- oder Stationsbetrieb. Es zeigt wartende Patientenmappen, Dokumentstatus, Systemzustände und aktuelle Aktivitäten. Über die Suche können Mappen anhand von Name, Vorname oder Fallnummer gefunden werden. Wartende Mappen können entweder im lokalen Patientenmodus gestartet oder direkt an ein registriertes Signaturgerät gesendet werden.

### Patientenmodus

Der Patientenmodus unter `/patient` führt durch einen klar begrenzten Wizard:

1. Begrüßung und Start durch das Personal
2. Dokumentübersicht
3. PDF-Anzeige
4. Lesebestätigung
5. optionale E-Mail-Zustimmung
6. Signatur per Canvas, Apple Pencil oder Touch
7. Zusammenfassung
8. Abschlussseite und Signaturabschluss

Der Modus ist bewusst navigationsarm gehalten, damit Patientinnen und Patienten ausschließlich die ihnen zugeordnete Mappe bearbeiten.

### iPad-Kioskmodus und Geräteverwaltung

Tablets können unter `/kiosk` als dedizierte Signaturterminals registriert werden. Registrierte Geräte zeigen im Leerlauf einen Wartebildschirm, erhalten neue Zuweisungen per Long Polling und wechseln automatisch in den Signaturassistenten. Nach Abschluss der Unterschrift wird das Gerät wieder freigegeben.

Die Geräteverwaltung im Administrationsbereich bietet:

- Registrierung mit eindeutigem Gerätenamen
- Statusübersicht für frei, belegt, offline, gesperrt oder außer Betrieb
- Aktionen zum Umbenennen, Sperren, Entsperren, Zurücksetzen, Außer-Betrieb-Nehmen, Löschen und Beenden aktiver Sitzungen
- Protokollierung von Registrierung, Zuweisung, Sitzung, Timeout, Tokenerneuerung und Signaturabschluss
- automatische Freigabe und Ablauf von Zuweisungen nach 30 Minuten
- Offline-Erkennung über Heartbeats

Die Geräteauthentifizierung nutzt UUIDs, gerätegebundene Tokens, gehashte Tokenablage und rotierende Session-Tokens pro Zuweisung. Dokumente werden im Kioskmodus nur über den autorisierten Kiosk-Endpunkt der aktiven Zuweisung ausgeliefert.

### Clearing für nicht zuordenbare Dokumente

- Dokumente, die die KI nicht eindeutig einer Patientenmappe zuordnen kann (fehlende/ungültige Fallnummer, niedrige Konfidenz, unbekannter Dokumenttyp, mehrere Treffer, Analysefehler), landen automatisch im Clearing-Bereich statt verworfen zu werden
- Übersicht mit Mehrfachauswahl: mehrere Dokumente gleichzeitig einem Patienten zuordnen, einer neuen Mappe hinzufügen oder archivieren
- Detailansicht mit großer PDF-Vorschau (Zoom), KI-Ergebnis, manuell korrigierbaren Werten und Live-Patientensuche
- Neue oder temporäre Patientenmappen (ohne Fallnummer, Kennzeichnung „Temporär"); Fallnummer kann später nachgetragen werden, Dokumente werden dabei automatisch umbenannt
- „Analyse erneut starten": Vision, Analysemodell oder beide erneut ausführen; frühere Ergebnisse bleiben historisch erhalten
- Standardisierte, administrativ erweiterbare Fehlercodes (z. B. `NO_CASE_NUMBER`, `LOW_CONFIDENCE`, `MULTIPLE_MATCHES`)
- Navigation mit Badge offener Vorgänge, Dashboard-Widget und Clearing-Statistiken (durchschnittliche Bearbeitungszeit, häufigste Fehlergründe, manuelle Zuordnungen, erfolgreiche Neuanalysen)
- Zugriff nur für Rollen `admin` und `operator`; alle Schritte werden revisionssicher in Audit-Log und Fallhistorie protokolliert
- Konfigurierbar in der Administration: Konfidenzschwellwert, automatische Clearing-Zuordnung, temporäre Mappen, automatische Neuanalyse, maximale KI-Versuche

### Administration

Der Administrationsbereich unter `/admin` ist rollenbeschränkt und enthält die Module:

- Allgemein
- KI
- Dokumenttypen
- Import
- Export
- SMTP
- Logging
- Benutzer
- Rollen
- Geräte
- System

Einstellungen werden in der Datenbank gespeichert und überschreiben die Konfigurationsdateien. Dadurch lassen sich KI-Endpunkte, SMTP, Import/Export, Dokumenttypen, Prompts, Benutzer und Rollen ohne Quellcodeänderungen anpassen.

### Import, Export und Netzwerkpfade

PDFs können manuell hochgeladen oder über einen Watch-Folder importiert werden. Import- und Exportverzeichnisse unterstützen lokale Pfade sowie UNC-Netzwerkpfade wie `\\Server\Freigabe`. Für Netzwerkfreigaben können optional Domäne, Benutzername und Passwort hinterlegt werden. Über die Admin-Oberfläche kann der Zugriff direkt getestet werden.

Unter Windows wird für UNC-Verbindungen `net use` verwendet. Unter Linux oder Docker kann `smbclient` genutzt werden; alternativ funktioniert ein direkter Zugriff, wenn die Freigabe bereits per CIFS oder vergleichbar gemountet ist.

### Lokale KI-Integration

PatSign unterscheidet zwei KI-Workflows:

- **Vision-KI** für visuelle oder dokumentbezogene Erkennung
- **Analyse-KI** für strukturierte Auswertung, Klassifikation und fachliche Extraktion

Empfohlen wird der Betrieb über **zwei getrennte KI-Endpunkte**, z. B. zwei lokale OpenAI-kompatible Dienste oder zwei getrennte Modellserver. Dadurch können Vision- und Analyseanfragen unabhängig skaliert, mit unterschiedlichen Modellen betrieben und bei Lastspitzen sauber getrennt werden.

Der Betrieb beider Workflows auf **einem gemeinsamen KI-Endpunkt** ist weiterhin möglich, sofern dieser Endpunkt beide benötigten Modelle anbietet. In diesem Modus ist jedoch mit Performanceeinschränkungen zu rechnen: lange Vision-Anfragen können Analyseanfragen blockieren, Modellwechsel können Latenz erzeugen und Timeouts sollten konservativer gewählt werden. Für Tests, kleine Installationen oder Einzelplatzbetrieb ist ein gemeinsamer Endpunkt praktikabel; für produktive Mehrgeräte- oder Klinikabläufe sind getrennte Endpunkte die robustere Betriebsform.

Die Admin-Seite kann verfügbare Modelle vom Endpunkt laden und je Endpunkt einen Verbindungstest ausführen. Modellfelder sind echte Dropdowns, behalten aber gespeicherte Werte bei, falls der KI-Dienst temporär nicht erreichbar ist.

### Prompt-Versionierung

Vision- und Analyse-Prompts werden versioniert gespeichert. Beim Bearbeiten wird der aktive Prompt als Vorlage in das Textfeld geladen; beim Speichern entsteht eine neue Version, ältere Versionen bleiben erhalten.

## Schnellstart mit Docker

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php database/migrate.php
```

Danach sind erreichbar:

| Dienst | URL |
| --- | --- |
| PatSign | `http://localhost:8080` |
| Mailpit | `http://localhost:8025` |
| phpMyAdmin | `http://localhost:8081` |

Für den HTTP-Quickstart sollte `SESSION_SECURE` in der `.env` nicht fest auf `true` gesetzt sein, damit der Session-Cookie übertragen und der Login-CSRF-Check erfolgreich validiert wird. Für HTTPS-Deployments kann `SESSION_SECURE=true` gesetzt oder die automatische Erkennung genutzt werden.

## Default-Admin

Beim ersten Start wird automatisch ein Administrationskonto angelegt, falls noch kein Benutzer existiert:

| Feld | Wert |
| --- | --- |
| Benutzername | `admin` |
| Passwort | `admin` |

Das Passwort sollte unmittelbar nach dem ersten Login geändert werden.

## Wichtige Konfiguration

Die Basiswerte liegen in `.env` und können teilweise über `system_settings` in der Admin-Oberfläche überschrieben werden.

| Bereich | Variablen |
| --- | --- |
| Anwendung | `APP_ENV`, `APP_DEBUG`, `APP_URL`, `APP_TIMEZONE`, `APP_NAME` |
| Datenbank | `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` |
| Sessions | `SESSION_SECURE`, `SESSION_HTTP_ONLY`, `SESSION_SAME_SITE`, `SESSION_LIFETIME` |
| Vision-KI | `VISION_HOST`, `VISION_PORT`, `VISION_API_KEY`, `VISION_MODEL`, `VISION_TIMEOUT` |
| Analyse-KI | `ANALYSIS_HOST`, `ANALYSIS_PORT`, `ANALYSIS_API_KEY`, `ANALYSIS_MODEL`, `ANALYSIS_TIMEOUT` |
| Import/Storage | `IMPORT_WATCH_PATH`, `NETWORK_SHARE_PATH`, `MAX_UPLOAD_BYTES`, `ALLOWED_UPLOAD_MIME` |
| SMTP | `SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`, `SMTP_ENCRYPTION`, `SMTP_FROM`, `SMTP_FROM_NAME` |

KI-Hosts können mit oder ohne Schema und mit oder ohne `/v1`-Suffix angegeben werden. Die Anwendung normalisiert OpenAI-kompatible Endpunkte und verwendet für Modelllisten `GET /v1/models`.

## Datenbank und Migrationen

Migrationen werden über Composer oder direkt per PHP ausgeführt:

```bash
composer migrate
# oder
php database/migrate.php
```

Aktuelle Migrationsstände:

| Migration | Inhalt |
| --- | --- |
| `001_initial.sql` | Basisschema für Benutzer, Rollen, Dokumente, Signaturen, Prompts, Einstellungen und Logs |
| `002_ui_admin.sql` | erweiterte Dokumentstatus für Dashboard, Patientenmodus und Admin-Workflows |
| `003_devices.sql` | Geräte, Gerätesitzungen, Zuweisungen und Gerätehistorie für den Kioskmodus |

## Sicherheit und Betrieb

PatSign ist für geschützte interne Netze vorgesehen. Wichtige Sicherheitsmechanismen sind:

- Argon2id-Passwort-Hashing
- CSRF-Schutz für klassische Formular- und Admin-Aktionen
- gerätebasierte Tokenauthentifizierung für Kiosk-Endpunkte
- Prepared Statements über PDO
- Security-Header und strikte CSP ohne Inline-JavaScript
- rollenbasierte Zugriffskontrolle per RouteGuard
- Dateityp- und Größenprüfung für Uploads
- Audit-Logging in Datenbank und Logdateien
- PDF-Anzeige mit gezielter `SAMEORIGIN`-Freigabe für den Patienten-/Kioskviewer

Kiosk-POSTs sind dort von CSRF ausgenommen, wo sie nicht mit Benutzersessions, sondern mit Geräte- und Session-Tokens authentifiziert werden.

## Architektur

Die Anwendung nutzt eine eigene schlanke MVC-Struktur:

| Ebene | Pfade |
| --- | --- |
| Controller | `app/Controllers` |
| Services | `app/Services` |
| Repositories | `app/Repositories` |
| Core | `app/Core` |
| Middleware | `app/Middleware` |
| Security | `app/Security` |
| Views | `resources/views` |
| öffentliche Assets | `public` |
| Routen | `routes/web.php` |
| Migrationen | `database/migrations` |

Die wichtigsten Services sind unter anderem `PdfImportService`, `DocumentAnalysisService`, `LocalAiClient`, `SignatureService`, `SettingsService`, `NetworkShareService`, `SystemStatusService`, `DeviceService` und `PatientAssignmentService`.

## Wichtige Routen

| Route | Zweck |
| --- | --- |
| `/login`, `/logout` | Anmeldung und Abmeldung |
| `/dashboard` | Arbeitsdashboard |
| `/dashboard/data`, `/dashboard/search` | Dashboard-Daten und Suche |
| `/documents` | Dokumentübersicht |
| `/documents/upload`, `/documents/watch`, `/documents/analyze`, `/documents/sign` | Dokumentimport, Analyse und Signaturabschluss |
| `/patient` | Patientenwizard |
| `/patient/document`, `/patient/sign`, `/patient/exit` | Dokumentanzeige und Signatur im Patientenmodus |
| `/kiosk` | Tablet-Kioskmodus |
| `/kiosk/register`, `/kiosk/reconnect`, `/kiosk/state`, `/kiosk/poll`, `/kiosk/heartbeat`, `/kiosk/document`, `/kiosk/sign` | Registrierung, Long Polling, Heartbeat und Signatur für Geräte |
| `/devices/overview`, `/devices/assign` | Geräteübersicht und Zuweisung durch medizinisches Personal |
| `/clearing` | Clearing-Übersicht für nicht zuordenbare Dokumente |
| `/clearing/case`, `/clearing/document`, `/clearing/patients/search` | Detailansicht, PDF-Auslieferung und Live-Patientensuche |
| `/clearing/update`, `/clearing/assign`, `/clearing/folder`, `/clearing/case-number`, `/clearing/reanalyze`, `/clearing/complete`, `/clearing/archive` | Werte korrigieren, zuordnen, Mappen anlegen, Neuanalyse und Abschluss |
| `/admin/*` | Administrationsmodule |
| `/admin/ai/models`, `/admin/ai/test` | Modellliste und Verbindungstest für KI-Endpunkte |
| `/admin/share/test` | Zugriffstest für Netzwerkfreigaben |

## Entwicklung und Tests

```bash
composer install
composer test
```

Für Syntaxprüfungen kann zusätzlich `php -l` auf geänderte PHP-Dateien angewendet werden. Dokumentationsänderungen benötigen keine Anwendungstests.

## Änderungen seit PR #10

### PR #10 - UI-Redesign: Dashboard, Patientenmodus und Administrationsbereich

Mit PR #10 wurde PatSign von einem minimalen UI-Skelett zu einer bedienbaren Fachanwendung erweitert. Neu hinzugekommen sind das Dashboard für medizinisches Personal, der geführte Patientenmodus und ein vollständiger Administrationsbereich. Außerdem wurden die Zugriffskontrolle über `RouteGuardMiddleware`, ein modulares lokales CSS-/JS-Designsystem, CSP-konforme Assets und die Migration `002_ui_admin.sql` eingeführt.

### PR #11 - KI-Einstellungen: Modelle vom Endpunkt laden und Verbindungstest

PR #11 verbessert die lokale KI-Konfiguration. Die Anwendung kann verfügbare Modelle über OpenAI-kompatible `/v1/models`-Endpunkte laden, Vision- und Analyse-Endpunkte separat testen und Formularwerte vor dem Speichern validieren. Zusätzlich wurde die URL-Normalisierung robuster, sodass Hosts mit Port, Schema oder `/v1`-Suffix korrekt funktionieren.

### PR #12 - Modell-Auswahl als echtes Dropdown

PR #12 ersetzt die bisherigen Freitextfelder für KI-Modelle durch echte Dropdowns. Dadurch wählen Admins aus den vom Endpunkt gemeldeten Modellen, statt Modellnamen manuell einzutippen. Gespeicherte Werte bleiben sichtbar, auch wenn der Endpunkt gerade nicht erreichbar ist.

### PR #13 - Aktiven Prompt als Vorlage vorbefüllen

PR #13 verbessert die Promptpflege. Beim Öffnen der KI-Adminseite wird der aktive Prompt für Vision oder Analyse direkt in das Textfeld geladen. Beim Wechsel des Prompt-Typs wird die Vorlage entsprechend aktualisiert. Das versionierte Speicherverhalten bleibt erhalten.

### PR #14 - UNC-Netzwerkpfade mit Zugangsdaten und Zugriffstest

PR #14 erweitert Import und Export um UNC-Netzwerkpfade mit optionalen Zugangsdaten. Admins können Domäne, Benutzer und Passwort für Import- und Exportfreigaben speichern und den Zugriff direkt aus der Oberfläche testen. `NetworkShareService`, `PdfImportService` und `SystemStatusService` berücksichtigen diese Einstellungen.

### PR #15 - Geräteverwaltung und iPad-Kioskmodus

PR #15 führt registrierte Signaturgeräte ein. iPads oder Tablets können als Kioskgeräte eingerichtet, vom Dashboard aus mit Patientenmappen belegt und nach Abschluss automatisch wieder freigegeben werden. Dazu kamen die Tabellen `devices`, `device_assignments`, `device_sessions` und `device_history`, Geräteauthentifizierung mit Tokenrotation, Long Polling, Heartbeats, Kiosk-Dokumentzugriff und ein Adminmodul für Geräteverwaltung und Gerätehistorie.

## Weiterführende Dokumentation

- [IIS-Installation](docs/iis-installation.md)
- [Docker-Installation](docs/docker-installation.md)
- [Administratorhandbuch](docs/administratorhandbuch.md)
- [Benutzerhandbuch](docs/benutzerhandbuch.md)
- [API-Dokumentation](docs/api-dokumentation.md)
- [Datenbankschema & ER](docs/datenbankschema.md)
- [Architektur/Klassen/Sequenz](docs/architektur.md)
- [Deployment](docs/deployment.md)
- [Backup & Restore](docs/backup-restore.md)
