# Phase G17 — Database Backup & Restore Procedure

Three backup points are required around production import, per the cutover
checklist (`docs/PHASE_G_CUTOVER_CHECKLIST.md`):

1. **schema** — structure only, taken once the schema is finalized, before any data exists.
2. **pre-import** — full dump taken immediately before the production import batch is committed.
3. **post-import** — full dump taken immediately after the production import batch is committed and reconciliation PASSes.

## Taking a backup

```bash
migration/scripts/backup_db.sh schema      <db_name> -h<host> -P<port> -u<user> -p<password>
migration/scripts/backup_db.sh pre-import  <db_name> -h<host> -P<port> -u<user> -p<password>
migration/scripts/backup_db.sh post-import <db_name> -h<host> -P<port> -u<user> -p<password>
```

Output lands in `storage/backups/<db_name>_<label>_<UTC timestamp>.sql.gz`
(gzip-compressed `mysqldump` output; `pre-import`/`post-import` use
`--single-transaction` for a consistent InnoDB snapshot without locking the
whole database). `storage/backups/` is gitignored — backups are never
committed to the repository.

## Restoring

```bash
migration/scripts/restore_db.sh <backup_file.sql.gz> <target_db_name> -h<host> -P<port> -u<user> -p<password>
```

The target database **must already exist** — this script deliberately never
runs `CREATE DATABASE` or `DROP DATABASE` on your behalf, since that
decision (and whatever it would destroy) belongs to whoever is running the
restore, made explicitly. The script asks for the database name to be
typed again as a confirmation step before it does anything.

To restore into a **fresh** database:

```bash
mysql -h<host> -P<port> -u<user> -p<password> -e "CREATE DATABASE <target_db_name> CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
migration/scripts/restore_db.sh <backup_file.sql.gz> <target_db_name> -h<host> -P<port> -u<user> -p<password>
```

## Verified

Both scripts were run against the sandbox test instance in this session:
`backup_db.sh schema` and `backup_db.sh pre-import` produced valid
`.sql.gz` files, and `restore_db.sh` successfully restored the schema dump
into a fresh throwaway database (33 tables recreated) before that database
was dropped again. Not yet run against a real production-sized dataset —
timing/disk-space characteristics at production scale should be checked
once real data volumes are known.
