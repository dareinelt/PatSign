# Deployment-Anleitung

1. Repository ausrollen
2. `.env` setzen (siehe [`.env`-Referenz](#env-referenz) unten)
3. Composer-Abhängigkeiten installieren
4. DB-Migration ausführen
5. Webserver auf `public/` zeigen lassen
6. HTTPS + Backupjobs + Logrotation aktivieren

---

## `.env`-Referenz

Kopiere `.env.example` nach `.env` und passe alle Werte vor dem ersten Start an.

```bash
cp .env.example .env
```

### Anwendung

| Variable | Beispielwert | Bedeutung |
|---|---|---|
| `APP_ENV` | `production` | Umgebungsmodus. Im Betrieb immer `production`. |
| `APP_DEBUG` | `false` | Debug-Ausgaben. Im Betrieb **zwingend** `false`. |
| `APP_URL` | `https://mein-server.intern` | Vollständige URL inkl. Schema. Wird für Weiterleitungen verwendet. |
| `APP_TIMEZONE` | `Europe/Berlin` | PHP-Zeitzone. |
| `APP_NAME` | `PatSign` | Anzeigename der Anwendung. |
| `APP_KEY` | *(zufälliger Wert)* | **Muss** vor Inbetriebnahme auf einen einzigartigen, zufälligen Wert gesetzt werden (siehe unten). |

#### `APP_KEY` – Anwendungsschlüssel

`APP_KEY` ist der kryptografische Hauptschlüssel der Anwendung. Er darf **niemals** auf dem Standardwert `change-me` belassen werden.

Empfohlene Erzeugung eines sicheren Schlüssels:

```bash
php -r "echo bin2hex(random_bytes(32));"
```

Den ausgegebenen Wert in die `.env` eintragen:

```
APP_KEY=a3f8...einstellen
```

> **Wichtig:** Der `APP_KEY` darf nach Inbetriebnahme nicht mehr geändert werden, ohne bestehende Sessions zu invalidieren. Bewahre ihn sicher auf und commit ihn **nie** in die Versionsverwaltung.

---

### Datenbank

| Variable | Beispielwert | Bedeutung |
|---|---|---|
| `DB_HOST` | `mysql` | Hostname des MySQL-Servers (Docker-Service-Name oder IP). |
| `DB_PORT` | `3306` | MySQL-Port. |
| `DB_DATABASE` | `patsign` | Datenbankname. |
| `DB_USERNAME` | `patsign` | Datenbankbenutzer. |
| `DB_PASSWORD` | *(starkes Passwort)* | Datenbankpasswort. Im Betrieb unbedingt ändern. |

---

### Session & CSRF-Schutz

Die Session-Konfiguration wirkt sich direkt auf den CSRF-Schutz aus.  
PatSign verwendet sitzungsgebundene CSRF-Tokens (erzeugt mit `random_bytes(32)`), die bei jeder POST-Anfrage validiert werden.

| Variable | Empfohlener Wert | Bedeutung |
|---|---|---|
| `SESSION_SECURE` | `true` | Cookie wird **nur über HTTPS** übertragen. Schützt den Session-Cookie (und damit den CSRF-Token) vor Abfangen im Netz. Nur auf `false` setzen, wenn HTTPS nicht verfügbar ist (z. B. reine Entwicklungsumgebung). |
| `SESSION_HTTP_ONLY` | `true` | Session-Cookie ist per JavaScript nicht auslesbar. Verhindert Cookie-Diebstahl durch XSS. **Nicht ändern.** |
| `SESSION_SAME_SITE` | `Strict` | Cookie wird nicht bei Cross-Site-Anfragen mitgeschickt. Bietet zusätzlichen CSRF-Schutz auf Browser-Ebene. Zulässige Werte: `Strict`, `Lax`, `None` (letzteres nur mit `SESSION_SECURE=true`). |
| `SESSION_LIFETIME` | `120` | Session-Lebensdauer in Minuten. Nach Ablauf wird der Benutzer automatisch abgemeldet und ein neuer CSRF-Token vergeben. |

> **Hinweis:** Damit `SESSION_SECURE=true` greift, **muss** die Anwendung unter HTTPS erreichbar sein (vgl. `APP_URL`).

---

### KI-Endpunkte

Alle KI-Aufrufe erfolgen ausschließlich an lokal konfigurierbare OpenAI-kompatible Endpunkte.

| Variable | Beispielwert | Bedeutung |
|---|---|---|
| `VISION_HOST` | `http://vision-api` | URL des Vision-KI-Dienstes. |
| `VISION_PORT` | `11434` | Port des Vision-Dienstes. |
| `VISION_API_KEY` | `local-key` | API-Schlüssel (bei lokalem Dienst frei wählbar). |
| `VISION_MODEL` | `vision-local` | Modellbezeichnung. |
| `VISION_TIMEOUT` | `600` | Timeout in Sekunden (lokale Vision-Modelle brauchen bei mehrseitigen Dokumenten mehrere Minuten). |
| `ANALYSIS_HOST` | `http://analysis-api` | URL des Analyse-KI-Dienstes. |
| `ANALYSIS_PORT` | `11435` | Port des Analyse-Dienstes. |
| `ANALYSIS_API_KEY` | `local-key` | API-Schlüssel. |
| `ANALYSIS_MODEL` | `gemma-4-e4b` | Modellbezeichnung. |
| `ANALYSIS_TIMEOUT` | `300` | Timeout in Sekunden. |

---

### Dateiverwaltung

| Variable | Beispielwert | Bedeutung |
|---|---|---|
| `IMPORT_WATCH_PATH` | `/var/www/html/storage/imports` | Verzeichnis, das auf neue PDF-Importe überwacht wird. |
| `NETWORK_SHARE_PATH` | `/var/www/html/storage/network-share` | Pfad für freigegebene Netzwerkablagen. |
| `MAX_UPLOAD_BYTES` | `15728640` | Maximale Uploadgröße in Byte (Standard: 15 MB). |
| `ALLOWED_UPLOAD_MIME` | `application/pdf` | Kommagetrennte Liste erlaubter MIME-Typen. |

---

### E-Mail (SMTP)

| Variable | Beispielwert | Bedeutung |
|---|---|---|
| `SMTP_HOST` | `smtp.example.com` | SMTP-Server-Hostname. |
| `SMTP_PORT` | `587` | SMTP-Port. |
| `SMTP_USERNAME` | `user@example.com` | SMTP-Benutzername. |
| `SMTP_PASSWORD` | *(Passwort)* | SMTP-Passwort. |
| `SMTP_ENCRYPTION` | `tls` | Verschlüsselung: `tls`, `ssl` oder leer. |
| `SMTP_FROM` | `clinic@example.local` | Absenderadresse. |
| `SMTP_FROM_NAME` | `PatSign` | Anzeigename des Absenders. |

---

## Produktions-Checkliste `.env`

Folgende Punkte **müssen** vor Inbetriebnahme erledigt sein:

- [ ] `APP_ENV=production`
- [ ] `APP_DEBUG=false`
- [ ] `APP_KEY` auf einzigartigen Zufallswert gesetzt (kein `change-me`)
- [ ] `APP_URL` auf die echte HTTPS-URL gesetzt
- [ ] `DB_PASSWORD` geändert (kein Standard-Passwort)
- [ ] `SESSION_SECURE=true` (HTTPS vorausgesetzt)
- [ ] `SESSION_HTTP_ONLY=true`
- [ ] `SESSION_SAME_SITE=Strict`
- [ ] `.env` Datei nicht in die Versionsverwaltung eingecheckt (`.gitignore` prüfen)
