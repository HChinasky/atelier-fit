<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Database backups
    |--------------------------------------------------------------------------
    |
    | Where the daily dump goes and how long it survives.
    |
    | The retention period is not a housekeeping preference: the privacy policy
    | tells people their data is erased, and a backup is a copy that outlives the
    | erasure. The shorter this is, the smaller the window in which "deleted"
    | means "deleted everywhere except one archive". Publishing the number and
    | enforcing it is what makes the statement honest — so if it changes here, it
    | changes in the privacy policy too.
    |
    | - disk / prefix: the same private bucket as the photos, under its OWN
    |   prefix. That keeps backups out of reach of the photo lifecycle rule and
    |   out of the photo sweep, which walks the uploads prefix by age and would
    |   otherwise delete a week-old dump as if it were an expired image.
    | - pg_dump_path: absolute, because cron and systemd do not share your PATH.
    | - timeout: seconds for the dump itself; a dump that hangs must not hold the
    |   scheduler.
    |
    */

    'disk' => env('BACKUP_DISK', 'r2'),

    'prefix' => env('BACKUP_PREFIX', 'backups'),

    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),

    'pg_dump_path' => env('PG_DUMP_PATH', '/usr/bin/pg_dump'),

    'timeout' => (int) env('BACKUP_TIMEOUT', 900),

];
