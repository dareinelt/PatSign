# PatSign – Lokale Patienten-Signaturplattform

PatSign ist eine lokale PHP-8.4-MVC-Anwendung ohne Framework zur Digitalisierung des Patienten-Unterschriftenprozesses (PDF-Import, lokale Vision/Analyse-KI, Patientenmappe, Signaturabschlussseite, revisionsorientierte Ablage).

## Schnellstart (Docker)

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php database/migrate.php
```

App: `http://localhost:8080`  
Mailpit (Dev): `http://localhost:8025`  
phpMyAdmin (Dev): `http://localhost:8081`

## Default-Admin

- Benutzername: `admin`
- Passwort: `admin`

## Architektur

- Eigene MVC-Struktur (`app/Controllers`, `app/Services`, `app/Repositories`, `resources/views`)
- Router, DI-Container, Middleware, Session-Manager, Config-Layer
- Security: Argon2id, CSRF, Prepared Statements, CSP/Sicherheitsheader, Dateityp-/Größenprüfung
- KI-Aufrufe ausschließlich an lokal konfigurierbare OpenAI-kompatible Endpunkte
- Prompt-Versionierung in Datenbank

## Dokumentation

- [IIS-Installation](docs/iis-installation.md)
- [Docker-Installation](docs/docker-installation.md)
- [Administratorhandbuch](docs/administratorhandbuch.md)
- [Benutzerhandbuch](docs/benutzerhandbuch.md)
- [API-Dokumentation](docs/api-dokumentation.md)
- [Datenbankschema & ER](docs/datenbankschema.md)
- [Architektur/Klassen/Sequenz](docs/architektur.md)
- [Deployment](docs/deployment.md)
- [Backup & Restore](docs/backup-restore.md)

## Tests

```bash
composer install
composer test
```
