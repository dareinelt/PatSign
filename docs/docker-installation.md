# Docker-Installationsanleitung

```bash
cp .env.example .env
```

**Vor dem ersten Start** müssen in der `.env` mindestens folgende Werte angepasst werden:

1. **`APP_KEY`** – Standardwert `change-me` durch einen sicheren Zufallswert ersetzen:

   ```bash
   php -r "echo bin2hex(random_bytes(32));"
   ```

   Den ausgegebenen Wert in die `.env` eintragen:

   ```
   APP_KEY=<erzeugter-wert>
   ```

2. **`APP_URL`** – auf die tatsächliche URL des Servers setzen (z. B. `https://patsign.intern`).

3. **`DB_PASSWORD`** – Standard-Passwort `patsign` durch ein starkes Passwort ersetzen.

4. **Session-Sicherheit** – folgende Werte für den Produktivbetrieb beibehalten:
   ```
   SESSION_SECURE=true
   SESSION_HTTP_ONLY=true
   SESSION_SAME_SITE=Strict
   ```
   Diese Einstellungen sichern den Session-Cookie und schützen damit den CSRF-Mechanismus.  
   `SESSION_SECURE=true` erfordert HTTPS – bei reinen HTTP-Entwicklungsumgebungen auf `false` setzen.

Danach die Anwendung starten:

```bash
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php database/migrate.php
```

Optional Produktion:

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
```

> Eine vollständige Beschreibung aller `.env`-Variablen und die Produktions-Checkliste sind in der [Deployment-Anleitung](deployment.md#env-referenz) zu finden.
