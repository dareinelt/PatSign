# Administratorhandbuch

## Bereiche
- Benutzer- und Rollenverwaltung (DB-Tabellen `users`, `roles`)
- Dokumenttypen (`document_types`)
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
