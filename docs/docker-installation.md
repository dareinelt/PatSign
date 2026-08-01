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

4. **Session-Sicherheit** – für Docker-Quickstarts über `http://localhost:8080` `SESSION_SECURE` **nicht fest setzen**, damit PatSign HTTPS automatisch erkennt:
   ```
   SESSION_HTTP_ONLY=true
   SESSION_SAME_SITE=Strict
   ```
   Optional explizit setzen:
   - `SESSION_SECURE=true` für HTTPS-Deployments
   - `SESSION_SECURE=false` für reine HTTP-Umgebungen

   Diese Einstellungen sichern den Session-Cookie und schützen damit den CSRF-Mechanismus. Ein auf `true` gesetztes `SESSION_SECURE` verhindert bei reinem HTTP, dass der Session-Cookie übertragen wird; dadurch schlägt der Login mit `{"error":"CSRF-Token ungültig"}` fehl.

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
