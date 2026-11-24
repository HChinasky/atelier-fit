# Database backups

## What runs

`app:backup-database`, daily at 02:30 through the scheduler (`routes/console.php`). It does two things:

1. `pg_dump --format=custom` of the application database, uploaded to the private object store under the `backups/` prefix as `fitting_room-YYYY-MM-DD_HHMMSS.dump`.
2. Deletes stored backups older than `BACKUP_RETENTION_DAYS` (default 30).

It runs an hour **before** the retention sweep (03:30). That order is deliberate: a dump taken after the sweep would be the only surviving copy of the rows the sweep had just deleted for good.

## Why the retention period matters

A backup is a copy of personal data that outlives the erasure of the original. The privacy policy tells people their data is deleted; the honest version of that statement is "deleted, and gone from backups within 30 days". That number is `BACKUP_RETENTION_DAYS`, it is enforced by the command, and it is published in the policy — change one and change the other.

Backups live under their own prefix for the same reason. The photo sweep (`app:purge-expired-photos`) walks the `uploads` prefix by object age and deletes anything past 60 hours; a dump sharing that prefix would be deleted as though it were an expired photo, and nobody would notice until a restore was needed.

## Settings

| Variable | Default | Meaning |
|---|---|---|
| `BACKUP_DISK` | `r2` | Storage disk. The same private bucket as photos. |
| `BACKUP_PREFIX` | `backups` | Key prefix; keeps dumps clear of the photo lifecycle rule. |
| `BACKUP_RETENTION_DAYS` | `30` | How long a dump survives. `0` disables pruning and says so on every run. |
| `PG_DUMP_PATH` | `/usr/bin/pg_dump` | Absolute — cron and systemd do not share your shell's PATH. |
| `BACKUP_TIMEOUT` | `900` | Seconds allowed for the dump. |

The database password is read from the application's own configuration and passed to `pg_dump` through `PGPASSWORD` in the process environment — never as a command-line argument (those are visible in the process list) and never written into a unit file.

## Production

The server runs PostgreSQL 16.15 with `pg_dump` at `/usr/bin/pg_dump`, matching the server version, so a dump restores cleanly on the same host. PHP must be invoked as `php8.2`: the default `php` on that box is 8.4 and lacks extensions this application needs.

The scheduler already runs as `scheduler.service`, so nothing new has to be installed for the daily run — the command is picked up with the rest of the schedule.

Verify it is scheduled:

```
php8.2 artisan schedule:list | grep backup
```

Run one by hand, or prune without dumping:

```
php8.2 artisan app:backup-database
php8.2 artisan app:backup-database --prune-only
```

### If you prefer a dedicated timer

The scheduler covers this. A separate systemd timer is only worth it if backups must survive the scheduler being stopped:

```ini
# /etc/systemd/system/fitting-room-backup.service
[Unit]
Description=AI Fitting Room - database backup
After=network-online.target postgresql.service

[Service]
Type=oneshot
WorkingDirectory=/var/www/fitting-room
ExecStart=/usr/bin/php8.2 artisan app:backup-database
User=root
```

```ini
# /etc/systemd/system/fitting-room-backup.timer
[Unit]
Description=Daily database backup

[Timer]
OnCalendar=*-*-* 02:30:00
Persistent=true

[Install]
WantedBy=timers.target
```

```
systemctl daemon-reload && systemctl enable --now fitting-room-backup.timer
```

No credentials appear in either file: the command reads them from the application's `.env`. If this timer is enabled, remove the schedule entry from `routes/console.php` so the dump does not run twice.

## Restoring

```
# fetch the dump from the bucket first, then:
pg_restore --host=127.0.0.1 --username=fitting --dbname=fitting_room --clean --no-owner <file>.dump
```

Restoring reinstates rows that retention may since have deleted, including personal data someone asked to have erased. After any restore, run the sweeps before the service takes traffic again:

```
php8.2 artisan app:cleanup-expired-data
php8.2 artisan app:purge-expired-photos
```

## What this does not cover

Only the database. Photos and results are not backed up on purpose — they are deleted within 72 hours by design, and archiving them would contradict the promise the service makes about them.
