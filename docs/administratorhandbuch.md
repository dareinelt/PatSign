# Administratorhandbuch

## Bereiche
- Benutzer- und Rollenverwaltung (DB-Tabellen `users`, `roles`)
- Allgemein: Klinikname, Ort, Logo und Farben (`system_settings`, Schlüssel `general.*`)
- Abschlussseite: Textbausteine der letzten Seite, die beim Signieren an jedes Dokument angehängt wird (`system_settings`, Schlüssel `completion.*`)
- Dokumenttypen (`document_types`); optional kann eingestellt werden, dass nur Seite 1 eines Dokuments zur KI-Erkennung und -Analyse an die KI-Endpunkte gesendet wird (`system_settings`, Schlüssel `analysis.first_page_only`)
- Promptverwaltung inkl. Versionierung (`prompts`)
- KI-Endpunkte und Modellparameter (`.env`/`system_settings`)
- SMTP und Netzwerkpfadkonfiguration (`system_settings`)
- Audit-Logging (`audit_logs`, `storage/logs/audit.log`)
- Geräteverwaltung (`devices`, `device_assignments`, `device_sessions`, `device_history`)
- Dokumentenkatalog (`document_templates`, `document_template_versions`, `document_template_categories`)

## Geräteverwaltung (iPads als Signaturterminals)

- Übersicht aller registrierten Geräte: Name, Status (Aktiv/Gesperrt/Außer Betrieb), Verfügbarkeit (Frei/Belegt/Offline), letzte Aktivität, IP, Browser/OS, zugewiesener Patient, letzter Benutzer.
- Aktionen: Umbenennen, Sperren, Entsperren, Zurücksetzen (neue Zugangsdaten nötig), Außer Betrieb nehmen, Löschen, Aktive Sitzung beenden.
- Aktive Sitzungen, Zuweisungsprotokoll und Gerätehistorie (Registrierung, Umbenennung, Sperren, Zuweisung, Sitzungsstart/-ende, Timeout, Tokenerneuerung, Signaturabschluss) sind einsehbar.
- Zuweisungen und Sitzungen verfallen automatisch nach 30 Minuten; Geräte ohne Heartbeat gelten nach 2 Minuten als offline.

## Dokumentenkatalog

Unter *Administration → Dokumentenkatalog* werden PDF-Vorlagen gepflegt, die das medizinische Personal direkt aus dem Dashboard einer Patientenmappe hinzufügen kann. Neben Administratoren hat die Rolle `dokumentenmanagement` Zugriff – ausschließlich auf diesen Bereich, ohne weitere Admin-Funktionen.

- **Anlegen:** PDF hochladen und Metadaten pflegen (Bezeichnung, Beschreibung, Dokumenttyp, Kategorie). Die Datei wird serverseitig validiert (gültige, unverschlüsselte PDF, Größenlimit wie beim Upload); enthaltene Platzhalter werden automatisch erkannt und angezeigt.
- **Versionierung:** „Ersetzen“ legt eine neue, unveränderliche Version an. Bereits zu Mappen hinzugefügte Dokumente verweisen weiterhin auf die alte Version und bleiben unverändert. Die Versionshistorie (Datei, Zeitpunkt, Benutzer, Verwendungen) ist je Vorlage einsehbar.
- **Status:** Vorlagen können deaktiviert (nicht mehr auswählbar) oder archiviert werden. Löschen ist nur möglich, solange die Vorlage in keiner Mappe verwendet wurde – ansonsten archivieren.
- **Kategorien:** frei pflegbar; löschen nur, wenn keine Vorlage zugeordnet ist. Standardkategorien werden per Seeder angelegt.
- **Platzhalter:** Vorlagen können Platzhalter im Format `{{PLATZHALTER}}` enthalten (z. B. `{{CASE_NUMBER}}`, `{{FIRST_NAME}}`, `{{LAST_NAME}}`, `{{FULL_NAME}}`, `{{BIRTH_DATE}}`, `{{CURRENT_DATE}}`, `{{CLINIC_NAME}}`, `{{WARD}}`, `{{EMPLOYEE}}`). Beim Hinzufügen zur Mappe werden sie serverseitig mit den Patientendaten befüllt. Für nicht befüllbare Platzhalter wird der konfigurierbare Ersatzwert eingesetzt (`catalog.placeholder_default`).
  - *Hinweis:* Die Ersetzung funktioniert bei Vorlagen mit Standard-Schriftarten (WinAnsi-Kodierung, z. B. Helvetica/Arial). Werden beim Hochladen keine Platzhalter erkannt, obwohl welche enthalten sind, nutzt die PDF vermutlich eingebettete Subset-Schriften – die Vorlage dann mit Standardschriften neu erzeugen.
- **Einstellungen:** `catalog.placeholder_default` (Ersatzwert) und `catalog.validation_enabled` (optionale KI-Validierung personalisierter Katalogdokumente; standardmäßig aus, da Dokumenttyp und Patientenzuordnung bereits bekannt sind – Katalogdokumente sind sofort „Bereit zur Unterschrift“).
- **Audit:** Alle Aktionen werden protokolliert (`template_created`, `template_versioned`, `template_updated`, `template_deleted`, `template_used`, `catalog_document_added`, `catalog_document_removed`, `folder_documents_reordered`, `catalog_category_*`).

## Abschlussseite

Nach erfolgreicher Unterschrift wird an jedes Dokument einzeln eine Abschlussseite angehängt. Das Ergebnis wird unter `storage/processed` abgelegt und zusätzlich in den Export-Pfad (Netzwerkfreigabe) kopiert.

- Inhalte (Kopfblock, Einleitungstext, Überschriften, Checkbox-Texte zum E-Mail-Versand, Bestätigungssatz, Bearbeitungsinformationen) sind unter *Administration → Abschlussseite* pflegbar.
- Platzhalter: `{nachname}`, `{vorname}`, `{geburtsdatum}`, `{fallnummer}`, `{dokumententyp}`, `{dateiname}`, `{klinik}`, `{ort}`, `{datum}`, `{uhrzeit}`, `{email}`, `{bearbeiter}`, `{beginn}`, `{document_id}`, `{status}`, `{geraet}` — alle Platzhalter werden bei der Ausgabe befüllt (fehlende Werte erscheinen als „unbekannt“ bzw. als Leerlinie bei der E-Mail-Adresse).
- Der E-Mail-Versand wird als Kontrollkästchen dargestellt: Je nach Wunsch des Patienten ist „Versand gewünscht“ oder „kein Versand“ angekreuzt.
- Klinikname und Ort werden unter *Administration → Allgemein* gepflegt und automatisch übernommen.
- Die digitale Unterschrift des Patienten (Zeile „Digitale Signatur:“) sowie Ort und aktuelles Datum werden automatisch unterhalb des Bestätigungssatzes eingefügt.
- Stimmt der Patient dem E-Mail-Versand zu, wird zusätzlich eine Belehrungsseite zwischen Originaldokument und Abschlussseite eingefügt. Sie enthält denselben Kopf- und Fußblock wie die Abschlussseite; ihr Inhalt ist der Text „Belehrungstext E-Mail-Versand (Signaturgerät)“ (Schlüssel `kiosk.email_consent_notice`).
