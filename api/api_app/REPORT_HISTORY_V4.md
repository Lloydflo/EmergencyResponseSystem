# Report History v4 Backend

This backend exposes a derived 24-hour Approved/History classification without adding a new mutable database status or moving records to another table.

New admin filters:

```text
status=active_approved
status=history
status=history&year=2026&month=8
```

Run `migrations/2026_08_05_add_after_action_history_index.sql` for an existing installation, then deploy the matching Android 17.3 / versionCode 26 source.
