# IIS-Installationsanleitung

1. PHP 8.4 via FastCGI auf IIS installieren.
2. Projekt nach `C:\inetpub\patsign` deployen.
3. DocumentRoot auf `public/` setzen.
4. HTTPS (TLS) erzwingen.
5. Schreibrechte nur für `storage/` und `logs/` vergeben.
6. `.env` lokal pflegen, keine Cloud-Endpunkte konfigurieren.

## `.env` anpassen

Kopiere `.env.example` nach `.env` und passe vor dem ersten Start mindestens folgende Werte an:

- **`APP_KEY`** – Standardwert `change-me` durch einen sicheren Zufallswert ersetzen:

  ```powershell
  php -r "echo bin2hex(random_bytes(32));"
  ```

  ```
  APP_KEY=<erzeugter-wert>
  ```

- **`APP_URL`** – auf die HTTPS-URL des IIS-Servers setzen (z. B. `https://patsign.intern`).

- **`DB_PASSWORD`** – Standard-Passwort durch ein starkes Passwort ersetzen.

- **Session-Sicherheit** (wirkt sich direkt auf den CSRF-Schutz aus):

  ```
  SESSION_SECURE=true
  SESSION_HTTP_ONLY=true
  SESSION_SAME_SITE=Strict
  ```

  > `SESSION_SECURE=true` erfordert HTTPS. Da IIS HTTPS vorausgesetzt wird (Schritt 4), sollte dieser Wert immer `true` bleiben.

> Eine vollständige Beschreibung aller `.env`-Variablen ist in der [Deployment-Anleitung](deployment.md#env-referenz) zu finden.
