# Docker-Installationsanleitung

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php database/migrate.php
```

Optional Produktion:

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
```
