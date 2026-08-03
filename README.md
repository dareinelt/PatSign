# PatSign - Lokale Patienten-Signaturplattform

PatSign ist eine lokal betreibbare PHP-8.4-MVC-Anwendung ohne Framework zur Digitalisierung des Patienten-Unterschriftenprozesses in Praxen, Kliniken und vergleichbaren Einrichtungen. Die Anwendung importiert PDF-Dokumente, analysiert sie über lokal konfigurierbare OpenAI-kompatible KI-Endpunkte, führt Patientinnen und Patienten durch eine reduzierte Signaturstrecke und legt signierte Dokumente revisionsorientiert ab.

Der Fokus liegt auf einem kontrollierten lokalen Betrieb: keine CDN-Abhängigkeiten, keine Cloud-Pflicht für KI, rollenbasierte Bedienoberflächen, nachvollziehbare Audit-Logs und ein Kioskmodus für iPads oder andere Tablets als dedizierte Signaturterminals.

## Funktionsumfang

### Dashboard für medizinisches Personal

Das Dashboard unter `/dashboard` ist die zentrale Arbeitsoberfläche für den Praxis- oder Stationsbetrieb. Es zeigt wartende Patientenmappen, Dokumentstatus, Systemzustände und aktuelle Aktivitäten. Über die Suche können Mappen anhand von Name, Vorname oder Fallnummer gefunden werden. Wartende Mappen können entweder im lokalen Patientenmodus gestartet oder direkt an ein registriertes Signaturgerät gesendet werden.

Vor der Übergabe an einen Patienten öffnet die Schaltfläche „Patientenmappe öffnen" ein Übersichts-Overlay mit allen Mappen, die innerhalb eines konfigurierbaren Zeitraums offene Dokumente enthalten. Das Personal sieht auf einen Blick, welche Dokumente noch fehlen oder bereits unterschrieben sind, und kann die Sitzung direkt aus dem Overlay starten oder an ein Gerät senden. Die KI-Kachel wird bei laufenden Analysen zum interaktiven Button: Ein Dialog zeigt Originaldateinamen, Laufzeiten und einen Fortschrittsbalken. Über eine Notfall-Schaltfläche kann ein Dokument sofort ins Clearing verschoben werden, ohne auf den Abschluss der Analyse zu warten.

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

### Interaktive Formulare (Inline-Ausfüllfunktion)

Ausfüllbare Dokumente (z. B. Anamnesebögen, Fragebögen, Selbstauskünfte, Checklisten) werden automatisch erkannt und können direkt auf dem Gerät ausgefüllt werden – nahtlos im bestehenden Signaturworkflow, ohne sichtbaren Unterschied in der Bedienung:

- **Erkennung**: Die bestehende KI-Analyse liefert zusätzlich `interactive` + `confidence`. Bei interaktiven Dokumenten folgt eine Formularanalyse: vorhandene PDF-Formularfelder (AcroForms) haben Vorrang, andernfalls erkennt die Vision-KI Eingabebereiche mit strukturierten Koordinaten.
- **Darstellung**: Das Original-PDF bleibt unverändert sichtbar; darüber liegt eine transparente Eingabeebene (Overlay) mit relativen Koordinaten, die automatisch mit Zoom und Ausrichtung skaliert.
- **Feldtypen**: Freitext, mehrzeiliger Text, Zahl, Datum, Uhrzeit, Ja/Nein, Checkbox, Radio, Dropdown, Mehrfachauswahl, Unterschrift, Initialen, Telefon, E-Mail – erweiterbar über eine Feldtyp-Registry.
- **Eingabe**: Tastatur und/oder Apple-Pencil-Handschrift (administrativ konfigurierbar), Autosave mit Wiederaufnahme nach Verbindungsabbruch oder Browserneustart.
- **Validierung**: ausschließlich serverseitig (Pflichtfelder, Formate, Zahlenbereiche, Regex); Pflichtfeld-Gate vor dem Fortfahren, Fortschrittsanzeige.
- **Vorbelegung**: bekannte Patientendaten (Name, Geburtsdatum, Fallnummer, aktuelles Datum) werden automatisch eingetragen und bleiben editierbar, sofern nicht gesperrt.
- **Abschluss**: Beim Unterschreiben werden Original-PDF, Formularinhalte, Abschlussseite und Unterschrift serverseitig zu einem finalen PDF zusammengeführt. Das Original bleibt unverändert archiviert; die ausgefüllte Version erhält eine eigene Dokument-ID. Danach sind Formularinhalte eingefroren.
- **Administration**: eigene Sektion „Formulare“ (Analyse aktivieren, Vision-Modell, Pflichtfeldprüfung, Autosave-Intervall, Handschrift/Tastatur, Vorbelegung).
- **Freihandmodus (Alternative)**: Statt der Formularanalyse kann in der Sektion „Formulare“ das freie Schreiben mit dem Apple Pencil aktiviert werden. Im Kioskmodus liegt dann über jeder PDF-Seite eine Zeichenebene: Der Stift schreibt direkt auf dem gesamten Dokument, der Finger scrollt weiterhin. Hoch-/Runter-Buttons erleichtern das Blättern, ein „Rückgängig“-Button entfernt die letzte Stifteingabe. Beim Unterschreiben werden die Stifteingaben serverseitig in das finale PDF eingebrannt.

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

### Dokumentenkatalog

- Administratoren und die dedizierte Rolle `dokumentenmanagement` pflegen PDF-Vorlagen (Einwilligungen, Aufklärungsbögen, Datenschutzerklärungen) im Bereich *Administration → Dokumentenkatalog*
- Metadaten je Vorlage: Bezeichnung, Beschreibung, Dokumenttyp, Kategorie; Vorschau und Download direkt aus der Verwaltung
- Versionierung: Ersetzen erzeugt eine neue, unveränderliche Version – bereits verwendete Dokumente bleiben unverändert; Versionshistorie einsehbar
- Vorlagen lassen sich deaktivieren oder archivieren; Löschen nur ohne Verwendungen
- Platzhalter im Format `{{PLATZHALTER}}` (Fallnummer, Name, Geburtsdatum, Datum, Klinik, Mitarbeiter …) werden beim Hochladen automatisch erkannt und beim Hinzufügen serverseitig mit den Patientendaten befüllt
- Personal fügt Katalogdokumente direkt im Dashboard-Mappen-Overlay hinzu (Suche, Kategoriefilter, Vorschau, Mehrfachauswahl); die Dokumente sind sofort „Bereit zur Unterschrift“ und für Patienten nicht von importierten Dokumenten unterscheidbar
- Reihenfolge der Mappe per Pfeiltasten oder Drag & Drop änderbar; nicht unterschriebene Dokumente können wieder entfernt werden
- Vollständige Audit-Protokollierung (Anlegen, Versionieren, Verwenden, Entfernen, Umsortieren)

### Administration

Der Administrationsbereich unter `/admin` ist rollenbeschränkt und enthält die Module:

- Allgemein
- KI
- Dokumenttypen
- Dokumentenkatalog
- Import
- Export
- SMTP
- Logging
- Benutzer
- Rollen
- Geräte
- Formulare
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
| `004_notifications.sql` | Benachrichtigungen über abgeschlossene Hintergrund-Analysen |
| `005_clearing.sql` | Clearing-Workflow für unklare Dokumentzuordnungen |
| `006_forms.sql` | interaktive Formulare: Vorlagen, Felder, Feldtypen, Eingaben, Versionen; `documents.is_interactive`/`form_status` |
| `007_health_history.sql` | Verlaufstabelle `health_check_history` für die öffentliche Statusseite `/health`; Einträge älter als 14 Tage werden automatisch bereinigt |

Migrationen laufen seit PR #38 idempotent: Die Tabelle `schema_migrations` zeichnet jede angewendete Migrationsdatei auf. Wiederholte Aufrufe von `php database/migrate.php` sind daher gefahrlos und führen keine bereits angewendete Migration erneut aus.

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
| `/health`, `/health/data` | Öffentliche Statusseite (Ampellogik, 48h-Timeline) und JSON-Endpunkt für externes Monitoring |
| `/dashboard/folders` | Mappenübersicht mit offenen Dokumenten für konfigurierbaren Zeitraum |
| `/dashboard/analyzing`, `/dashboard/emergency` | Laufende Analysen abrufen und Notfall-Clearing auslösen |
| `/admin/*` | Administrationsmodule |
| `/admin/ai/models`, `/admin/ai/test` | Modellliste und Verbindungstest für KI-Endpunkte |
| `/admin/share/test` | Zugriffstest für Netzwerkfreigaben |

## Entwicklung und Tests

```bash
composer install
composer test
```

Für Syntaxprüfungen kann zusätzlich `php -l` auf geänderte PHP-Dateien angewendet werden. Dokumentationsänderungen benötigen keine Anwendungstests.

## Vorteile bei Workflowoptimierung

PatSign reduziert manuelle Schritte und Wartezeiten im Praxis- und Klinikbetrieb spürbar:

- **Vollständig digitaler Dokumentenworkflow**: PDFs werden automatisch importiert, per KI analysiert und der richtigen Patientenmappe zugeordnet. Personal und Patienten arbeiten ausschließlich digital – kein Ausdrucken, kein Scannen, kein manuelles Ablegen.
- **Inline-Formularausfüllung**: Anamnesebögen und Fragebögen können direkt auf dem iPad ausgefüllt werden, nahtlos vor der Unterschrift. Kein Medienbruch, kein Wechsel zwischen Papier und System.
- **Freihandmodus**: Als Alternative zur Formularanalyse ermöglicht der Freihandmodus das direkte Schreiben mit dem Apple Pencil auf dem Dokument. Handschriftliche Anmerkungen werden serverseitig in das signierte PDF eingebrannt, ohne dass ein zweites System oder Papierdokument benötigt wird.
- **Kiosk-Geräteverwaltung**: Tablets werden zentral registriert, zugewiesen und überwacht. Das Personal startet die Sitzung per Klick vom Dashboard; das Gerät führt den Patienten selbstständig durch den Wizard und meldet sich nach Abschluss automatisch zurück.
- **Mappenübersicht vor Übergabe**: Bevor das Tablet an einen Patienten übergeben wird, kann das Personal im Dashboard auf einen Blick prüfen, welche Dokumente noch fehlen oder offen sind – mit konfigurierbarem Zeitraum und direkter Start-Aktion.
- **Bereits unterschriebene Dokumente werden übersprungen**: Erhält ein Patient erneut eine Mappe, werden bereits signierte Dokumente automatisch ausgeblendet. Doppelarbeit wird vermieden.
- **Notfall-Aktion bei langen Analysen**: Wenn ein Dokument dringend benötigt wird und die KI-Analyse zu lange läuft, kann das Personal per Schaltfläche das Dokument sofort ins Clearing verschieben und manuell zuordnen – ohne Wartezeit.
- **Öffentliche Statusseite**: Die passwortfreie `/health`-Seite mit Ampellogik und 48-Stunden-Timeline erlaubt Operations-Teams, den Systemzustand ohne Login und ohne Zugang zum Admin-Bereich zu überwachen.
- **Sichere Migrationsverwaltung**: Migrationen laufen nur einmal. Wiederholte Aufrufe von `php database/migrate.php` sind idempotent und können im Routinebetrieb und bei Updates gefahrlos ausgeführt werden.

## Umweltaspekte

PatSign ist konsequent auf papierlosen Betrieb ausgelegt und unterstützt die Nachhaltigkeitsziele von Praxen und Kliniken:

- **Papiereinsparung**: Aufklärungs- und Einwilligungsformulare, Anamnesebögen, Fragebögen und Dokumentationsbögen werden vollständig digital abgebildet. Kein Dokument muss ausgedruckt, unterschrieben und anschließend gescannt oder abgeheftet werden.
- **Lokale KI – keine Cloud-Abhängigkeit**: KI-Analysen laufen auf lokal betriebenen, OpenAI-kompatiblen Endpunkten. Es wird keine Rechenleistung in externen Rechenzentren benötigt und keine Patientendaten über das Netz übertragen.
- **Freihandmodus als Papierersatz**: Statt Handschrift auf Papier schreiben Patienten direkt auf dem Touchscreen. Die Eingaben werden digital archiviert, ohne Papierverbrauch oder physischen Ablageaufwand.
- **Revisionssichere digitale Ablage**: Signierte Dokumente werden mit Abschlussseite, Belehrungstext und Unterschrift in einem einzigen PDF archiviert. Physische Akten entfallen, Lager- und Transportaufwand für Papierdokumente werden eliminiert.
- **Datenschutzkonforme E-Mail-Einwilligung dokumentiert im PDF**: Der Datenschutz-Belehrungstext für den digitalen E-Mail-Versand wird als eigene Seite ins signierte PDF eingebettet. Damit ist die datenschutzrechtliche Aufklärung lückenlos dokumentiert, ohne einen zusätzlichen Papierdruck.
- **Energieeffiziente Infrastruktur**: Der Betrieb auf vorhandener interner Hardware (z. B. bestehende Server, NAS, Tablets) vermeidet zusätzliche Hardware-Investitionen. Docker-basiertes Deployment ermöglicht ressourcenschonenden Betrieb auf gemeinsam genutzten Systemen.

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

### PR #16 - README aktualisieren

PR #16 ergänzt die README um die Änderungen aus PR #10 bis PR #15.

### PR #17 - Fix

### PR #18 - Fix

### PR #19 - Fix

### PR #20 - Fix

### PR #21 - Upload-Ergebnis als Dialog anzeigen

PR #21 ersetzt die rohe JSON-Antwort nach dem Dokument-Upload durch einen Overlay-Dialog mit dem Ergebnis der Verarbeitung.

### PR #22 - Dokument-Upload mit Analyse anstoßen

PR #22 legt beim Upload den Dokumentdatensatz an und startet die KI-Analyse.

### PR #23 - Hintergrundverarbeitung und Benachrichtigungen

PR #23 lagert die Dokumentanalyse in einen Hintergrundprozess aus und führt eine Benachrichtigungszentrale ein.

### PR #24 - Fix

### PR #25 - Fix

### PR #26 - Fix

### PR #27 - Analyse-Prompt härten

PR #27 übergibt das Fallnummern-Muster an den Analyse-Prompt, erzwingt konfigurierte Dokumenttypen und begrenzt die Ausgabe.

### PR #28 - Fallnummern der letzten zwei Jahre akzeptieren

PR #28 erweitert die Fallnummernerkennung auf Fallnummern aus den vergangenen zwei Jahren.

### PR #29 - Fix

### PR #30 - Fix

### PR #31 - Übersicht heute signierter Dokumente

PR #31 ergänzt eine Übersicht der heute signierten Dokumente mit PDF-Vorschauen.

### PR #32 - Abschlussseite an signierte Dokumente anhängen

PR #32 fügt signierten Dokumenten eine Abschlussseite hinzu und übernimmt die neue Vorlage als Standard.

### PR #33 - Fix

### PR #34 - Clearing-Bereich für nicht zuordenbare Dokumente

PR #34 führt einen Clearing-Bereich ein, in dem nicht eindeutig zuordenbare Dokumente geprüft, korrigiert und Patientenmappen zugewiesen werden können.

### PR #35 - README aktualisieren

PR #35 ergänzt die README um die Änderungen aus PR #21 bis PR #34.

### PR #36 - Mappenübersicht-Overlay mit konfigurierbarem Zeitraum

PR #36 ersetzt den einfachen Fallnummern-Dialog auf dem Dashboard durch ein Übersichts-Overlay. Das Personal sieht alle Patientenmappen mit offenen Dokumenten innerhalb eines konfigurierbaren Zeitraums (Standard: 24 Stunden), inklusive Dokumentliste, Signaturstatus und direkten Aktionsschaltflächen für Patientenmodus oder Gerätezuweisung. Die manuelle Fallnummerneingabe bleibt weiterhin verfügbar.

### PR #37 - Interaktive PDF-Formulare: Inline-Ausfüllfunktion im Signaturworkflow

PR #37 integriert die Ausfüllfunktion für interaktive Formulare direkt in den Signaturworkflow. Die KI erkennt ausfüllbare Dokumente (Anamnesebögen, Fragebögen, Checklisten); PDF-Formularfelder (AcroForms) haben Vorrang, ansonsten erkennt die Vision-KI Eingabebereiche. Eine transparente Eingabeebene über dem PDF ermöglicht die Dateneingabe per Tastatur oder Apple Pencil. Pflichtfelder werden ausschließlich serverseitig geprüft, Formulardaten werden mit Autosave gesichert und beim Unterschreiben serverseitig in das finale PDF eingebrannt. Das Original bleibt unverändert archiviert. Neu hinzugekommen sind Migration `006_forms.sql`, eine Feldtyp-Registry mit 14 Typen, die Admin-Sektion „Formulare" sowie neue Unit-Tests.

### PR #38 - Migrationen idempotent machen

PR #38 verhindert, dass `php database/migrate.php` bereits angewendete Migrationen erneut ausführt. Eine neue Tabelle `schema_migrations` zeichnet jede ausgeführte Migrationsdatei auf. Beim ersten Lauf auf einer bereits migrierten Datenbank werden vorhandene Schemamerkmale erkannt und ältere Migrationen als angewendet markiert, ohne sie erneut auszuführen.

### PR #39 - Belehrungstext zur E-Mail-Einwilligung auf der Kiosk-Seite

PR #39 ergänzt den Kiosk-Wizard um einen scrollbaren Datenschutz-Belehrungskasten unterhalb der E-Mail-Einwilligungs-Checkbox. Der Text folgt dem datenschutzrechtlichen Muster zu Art. 9 DSGVO (Risiken unverschlüsselter E-Mails, Widerrufsrecht, Freiwilligkeit). Klinik- und Ortsname werden aus den allgemeinen Einstellungen befüllt. Der Text ist im Admin-Bereich unter „Abschlussseite" pflegbar (Setting `kiosk.email_consent_notice`).

### PR #40 - Freihandmodus: Freies Schreiben mit dem Stift im Kioskmodus

PR #40 führt den Freihandmodus als Alternative zur KI-gestützten Formularanalyse ein. Patienten können im Kioskmodus mit dem Apple Pencil direkt auf dem gesamten Dokument schreiben; der Finger scrollt weiterhin. Hoch/Runter-Schaltflächen erleichtern das Blättern, ein Rückgängig-Button entfernt die letzte Stifteingabe. Beim Unterschreiben werden die Stifteingaben als transparente PNGs je Seite an den Server übertragen und per FPDI in das signierte PDF eingebrannt. Der Freihandmodus wird in der Admin-Sektion „Formulare" aktiviert und schließt das Formularfeld-Overlay sowie die Pflichtfeldprüfung aus.

### PR #41 - Öffentliche Statusseite `/health` mit Ampellogik und 48-Stunden-Timeline

PR #41 fügt eine öffentlich zugängliche Statusseite unter `/health` hinzu. Sie zeigt den Zustand aller Systemkomponenten (Datenbank, Vision-KI, Analyse-KI, SMTP, Speicherpfade) in einer Ampelübersicht und einer 48-Stunden-Timeline mit stündlichen Buckets. Verlaufsdaten werden in der neuen Tabelle `health_check_history` (Migration `007_health_history.sql`) gespeichert und nach 14 Tagen bereinigt. Ein JSON-Endpunkt `/health/data` ermöglicht die Anbindung externer Monitoring-Systeme; bei Fehlern wird HTTP 503 zurückgegeben.

### PR #42 - Dashboard: KI-Kachel mit Analyse-Fortschritt und Notfall-Aktion

PR #42 macht die KI-Kachel auf dem Dashboard interaktiv. Sobald Analysen laufen, ist die Kachel klickbar und öffnet einen Dialog mit den Originaldateinamen, bisherigen Laufzeiten und einem Fortschrittsbalken. Neben jedem Dokument erscheint eine Notfall-Schaltfläche: Sie verschiebt das Dokument sofort ins Clearing, damit es manuell zugeordnet werden kann, ohne auf das Ende der Analyse warten zu müssen. Der Hintergrundprozess prüft vor jedem Schreibvorgang, ob das Dokument bereits per Notfall verschoben wurde.

### PR #43 - Bereits unterschriebene Dokumente nicht erneut im Kiosk vorlegen

PR #43 stellt sicher, dass Dokumente mit Status `signed`, `sent` oder `archived` beim erneuten Laden einer Patientenmappe im Kioskmodus übersprungen werden. Sind alle Dokumente einer Fallnummer bereits unterschrieben, erscheint eine klare Fehlermeldung statt „Keine Dokumente gefunden". Doppelsignaturen werden dadurch technisch ausgeschlossen.

### PR #44 - Kiosk: Lesebestätigung als Pflichtfeld vor Unterschrift erzwingen

PR #44 blockiert das Weitergehen zur Unterschrift, solange die Checkbox „Ich bestätige, dass ich alle Dokumente gelesen habe" nicht gesetzt ist. Eine Fehlermeldung mit Fokussteuerung erscheint bei fehlender Bestätigung; sobald der Haken gesetzt wird, verschwindet sie. Die bestehende serverseitige Prüfung beim Absenden der Unterschrift bleibt als zweite Sicherung erhalten.

### PR #45 - Belehrungsseite zum E-Mail-Versand bei Einwilligung ins PDF einfügen

PR #45 bettet den Datenschutz-Belehrungstext für den E-Mail-Versand als eigene Seite in das signierte PDF ein. Die Seite wird nur bei Einwilligung zwischen Original-PDF und Abschlussseite eingefügt, sowohl im regulären FPDI-Pfad als auch im Ghostscript-Fallback. Kopf- und Fußblock wurden in gemeinsam genutzte Methoden `pageHeader()` und `pageFooter()` extrahiert, die Abschluss- und Belehrungsseite identisch verwenden.

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
