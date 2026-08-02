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

## Geräteverwaltung (iPads als Signaturterminals)

- Übersicht aller registrierten Geräte: Name, Status (Aktiv/Gesperrt/Außer Betrieb), Verfügbarkeit (Frei/Belegt/Offline), letzte Aktivität, IP, Browser/OS, zugewiesener Patient, letzter Benutzer.
- Aktionen: Umbenennen, Sperren, Entsperren, Zurücksetzen (neue Zugangsdaten nötig), Außer Betrieb nehmen, Löschen, Aktive Sitzung beenden.
- Aktive Sitzungen, Zuweisungsprotokoll und Gerätehistorie (Registrierung, Umbenennung, Sperren, Zuweisung, Sitzungsstart/-ende, Timeout, Tokenerneuerung, Signaturabschluss) sind einsehbar.
- Zuweisungen und Sitzungen verfallen automatisch nach 30 Minuten; Geräte ohne Heartbeat gelten nach 2 Minuten als offline.

## Abschlussseite

Nach erfolgreicher Unterschrift wird an jedes Dokument einzeln eine Abschlussseite angehängt. Das Ergebnis wird unter `storage/processed` abgelegt und zusätzlich in den Export-Pfad (Netzwerkfreigabe) kopiert.

- Inhalte (Kopfblock, Einleitungstext, Überschriften, Checkbox-Texte zum E-Mail-Versand, Bestätigungssatz, Bearbeitungsinformationen) sind unter *Administration → Abschlussseite* pflegbar.
- Platzhalter: `{nachname}`, `{vorname}`, `{geburtsdatum}`, `{fallnummer}`, `{dokumententyp}`, `{dateiname}`, `{klinik}`, `{ort}`, `{datum}`, `{uhrzeit}`, `{email}`, `{bearbeiter}`, `{beginn}`, `{document_id}`, `{status}`, `{geraet}` — alle Platzhalter werden bei der Ausgabe befüllt (fehlende Werte erscheinen als „unbekannt“ bzw. als Leerlinie bei der E-Mail-Adresse).
- Der E-Mail-Versand wird als Kontrollkästchen dargestellt: Je nach Wunsch des Patienten ist „Versand gewünscht“ oder „kein Versand“ angekreuzt.
- Klinikname und Ort werden unter *Administration → Allgemein* gepflegt und automatisch übernommen.
- Die digitale Unterschrift des Patienten (Zeile „Digitale Signatur:“) sowie Ort und aktuelles Datum werden automatisch unterhalb des Bestätigungssatzes eingefügt.
- Stimmt der Patient dem E-Mail-Versand zu, wird zusätzlich eine Belehrungsseite zwischen Originaldokument und Abschlussseite eingefügt. Sie enthält denselben Kopf- und Fußblock wie die Abschlussseite; ihr Inhalt ist der Text „Belehrungstext E-Mail-Versand (Signaturgerät)“ (Schlüssel `kiosk.email_consent_notice`).
