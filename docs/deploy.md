# Deployment

Each licensing company gets its own deployment: its own containers, database, object storage, and branding. Do not point two companies at one database.

## Local Laragon

The checked-in `.env` can stay on SQLite so the app runs without Docker. Install the first administrator, document rules, and fee tables with:

```bash
php artisan licentra:install --name="Customer admin" --email="admin@example.com" --password="a-long-password"
```

Demo data, including a sample company name, is loaded only when `APP_ENV=local` and you run `php artisan db:seed`.

Start a queue worker so document scans leave the scanning state:

```bash
php artisan queue:work
```

## Docker

`docker-compose.yml` runs the PHP app, a Horizon worker, a scheduler, Postgres, Redis, MinIO, ClamAV, and Mailpit. The documents disk in `config/filesystems.php` is the private local disk rooted at `storage/app/private`. The compose file keeps that directory on a volume. MinIO is available when you later point the documents disk at S3-compatible storage.

```bash
docker compose up -d --build
docker compose exec app php artisan licentra:install --name="Customer admin" --email="admin@example.com" --password="a-long-password"
```

The app listens on port 8080. Mailpit is on port 8025. MinIO is on port 9000.

ClamAV is off by default (`CLAMAV_ENABLED=false`) because the signature database is large and a failed scan must not mark clean files as infected. Set `CLAMAV_ENABLED=true` after the `clamav` container reports that its definitions are loaded.

Horizon and the scheduler use the same image as the app. Rebuild the app image and recreate the worker and scheduler together so they do not drift:

```bash
docker compose build app
docker compose up -d --force-recreate app horizon scheduler
```

## Backups

```bash
docker compose exec postgres pg_dump -U licentra licentra | gzip > licentra-$(date +%Y%m%d).sql.gz
```

Restore into an empty database, then `docker compose up -d`.

## Sessions

Idle timeout is 30 minutes (`SESSION_LIFETIME`). The absolute lifetime is 8 hours unless system settings change `absolute_timeout_minutes`.
