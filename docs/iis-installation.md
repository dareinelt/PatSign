# IIS-Installationsanleitung

1. PHP 8.4 via FastCGI auf IIS installieren.
2. Projekt nach `C:\inetpub\patsign` deployen.
3. DocumentRoot auf `public/` setzen.
4. HTTPS (TLS) erzwingen.
5. Schreibrechte nur für `storage/` und `logs/` vergeben.
6. `.env` lokal pflegen, keine Cloud-Endpunkte konfigurieren.
