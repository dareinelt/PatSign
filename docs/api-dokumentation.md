# API-Dokumentation (interne Routen)

- `GET /health` Öffentliche Healthcheck-Seite (Ampeldarstellung aller Komponenten inkl. KI-Endpunkte + 48h-Zeitstrahl; kein Login nötig; HTTP 503 bei Störung)
- `GET /health/data` Healthcheck als JSON (`status`, `checks`, `timeline`, `generated_at`; kein Login nötig)
- `GET /login` Login-Formular
- `POST /login` Anmeldung
- `POST /logout` Abmeldung
- `GET /documents` Dokumentenansicht
- `POST /documents/upload` PDF-Upload (Analyse läuft asynchron im Hintergrund)
- `GET /documents/watch` Importordner-Scan
- `POST /documents/analyze` Vision+Analyse (lokal)
- `POST /documents/sign` Abschlussseiten-Metadaten
- `GET /notifications` Benachrichtigungen (JSON: `unread` + letzte 20 Einträge)
- `POST /notifications/read` Benachrichtigung(en) als gelesen markieren (`id` optional, sonst alle)
- `GET /admin` Administration
- `POST /admin/share/test` Zugriffstest Netzwerkverzeichnis (Import/Export, UNC-Pfad mit optionalen Anmeldedaten)
- `POST /admin/prompts` Prompt-Version anlegen/aktivieren
- `POST /admin/devices` Geräteaktion (umbenennen, sperren, entsperren, zurücksetzen, außer Betrieb, löschen, Sitzung beenden)
- `GET /devices/overview` Geräteübersicht (JSON, für Dashboard)
- `POST /devices/assign` Patientenmappe an Gerät senden

## Dokumentenkatalog

Administration (Rollen `admin` und `dokumentenmanagement`):

- `GET /admin/document-catalog` Katalogverwaltung (Vorlagenübersicht, Kategorien, Platzhalter-Hilfe)
- `POST /admin/document-catalog/save` Neue Vorlage anlegen (multipart, PDF wird serverseitig validiert, Platzhalter automatisch erkannt)
- `POST /admin/document-catalog/update` Metadaten bearbeiten (Bezeichnung, Beschreibung, Dokumenttyp, Kategorie)
- `POST /admin/document-catalog/replace` Vorlage ersetzen → neue unveränderliche Version; bereits verwendete Dokumente bleiben unverändert
- `POST /admin/document-catalog/status` Statusaktion (`activate`/`deactivate`/`archive`/`restore`/`delete`; Löschen nur ohne Verwendungen)
- `POST /admin/document-catalog/categories` Kategorie anlegen/umbenennen/löschen
- `GET /admin/document-catalog/file?id=&version=&mode=` Vorschau/Download einer Vorlagenversion (authentifizierte Auslieferung)

Personal-Dashboard (angemeldete Benutzer):

- `POST /dashboard/folder` Patientenmappe manuell anlegen (`last_name`, `first_name`, `birth_date`; `case_number` optional – ohne Fallnummer entsteht eine temporäre Mappe)
- `GET /catalog/templates?q=&category_id=` Aktive Vorlagen für die Auswahl (JSON, Suche + Kategoriefilter)
- `GET /catalog/preview?id=` Vorschau der aktuellen Vorlagenversion
- `POST /catalog/add` Vorlagen personalisiert zur Patientenmappe hinzufügen (`case_number`, `template_ids[]`; Platzhalter werden serverseitig befüllt, Dokument erhält Status `ready`)
- `POST /catalog/remove` Dokument aus Mappe entfernen (`document_id`; nur nicht unterschriebene Dokumente)
- `POST /catalog/reorder` Dokumentreihenfolge der Mappe speichern (`case_number`, `order[]`; bestimmt die Reihenfolge in der Patientenansicht)

## Formular-Endpunkte Patientenmodus (erfordern aktive Patientensitzung; Dokument muss zur Fallnummer gehören)

- `GET|POST /patient/form?document_id=` Formularstruktur abrufen (Felder mit relativen Koordinaten 0–1, gespeicherte Werte, Vorbelegung, Konfiguration)
- `POST /patient/form/save` Eingaben speichern (Autosave; `values` = JSON-Objekt `field_uuid => Wert`, liefert Feldfehler + Pflichtfeld-Fortschritt)
- `POST /patient/form/validate` Formular serverseitig validieren
- `POST /patient/form/complete` Formular abschließen (nur wenn alle Pflichtfelder gültig ausgefüllt sind, sofern Pflichtfeldprüfung aktiv)

## Kiosk-Endpunkte (Geräteauthentifizierung per `X-Device-Id`/`X-Device-Token` bzw. httpOnly-Cookies)

- `GET /kiosk` Kioskoberfläche bzw. Registrierungsassistent
- `POST /kiosk/register` Gerät registrieren (eindeutiger Name, liefert UUID + Gerätetoken)
- `POST /kiosk/reconnect` Cookies aus gespeicherten Zugangsdaten wiederherstellen
- `GET /kiosk/state` Aktuellen Zustand/Zuweisung abrufen (rotiert Sitzungstoken)
- `GET /kiosk/poll` Long Polling auf neue Zuweisung (max. 20 s)
- `POST /kiosk/heartbeat` Lebenszeichen (aktualisiert letzte Aktivität)
- `GET /kiosk/document?id=` Autorisierte PDF-Auslieferung (nur Dokumente der aktiven Zuweisung, sonst 403)
- `POST /kiosk/sign` Unterschriften speichern (erfordert `X-Session-Token`; gibt Gerät automatisch frei und rotiert das Gerätetoken)
- `GET|POST /kiosk/form?document_id=` Formularstruktur abrufen (nur Dokumente der aktiven Zuweisung, sonst 403)
- `POST /kiosk/form/save` Eingaben speichern (Autosave)
- `POST /kiosk/form/validate` Formular serverseitig validieren
- `POST /kiosk/form/complete` Formular abschließen (Pflichtfeld-Gate)

Hinweise zu den Formular-Endpunkten: Nach der Unterschrift sind Formularantworten eingefroren (HTTP 409 bei Änderungsversuchen). Alle Validierungen erfolgen ausschließlich serverseitig; Felddefinitionen und Koordinaten sind nicht clientseitig manipulierbar (unbekannte Feld-UUIDs werden ignoriert).
