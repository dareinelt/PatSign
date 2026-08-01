# API-Dokumentation (interne Routen)

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
