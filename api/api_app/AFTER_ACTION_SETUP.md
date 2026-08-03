# After-action reporting database setup

The mobile app message:

> Report was not saved: After-action reporting is not installed on the database yet.

means the API is reachable, but the database does not contain the table expected by the after-action endpoints.

## Preferred installation

Run this file against the same MySQL/MariaDB database used by `../../includes/db.php`:

`migrations/2026_08_03_create_responder_after_action_reports.sql`

Using phpMyAdmin:

1. Select the application database.
2. Open **Import**.
3. Choose the SQL file above.
4. Run the import.
5. Confirm that `responder_after_action_reports` appears in the table list.

Using the MySQL command line:

```bash
mysql -u YOUR_USER -p YOUR_DATABASE < migrations/2026_08_03_create_responder_after_action_reports.sql
```

## Automatic bootstrap

The four after-action endpoints now call `_after_action_schema.php`. When the table is missing, the API runs a fixed `CREATE TABLE IF NOT EXISTS` statement. This works only when the database account has `CREATE` permission.

For a production server, running the SQL migration manually is still recommended so schema installation is controlled and auditable.

## Verification query

```sql
SHOW TABLES LIKE 'responder_after_action_reports';
SHOW COLUMNS FROM responder_after_action_reports;
```
