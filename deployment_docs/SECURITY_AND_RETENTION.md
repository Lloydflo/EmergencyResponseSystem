# Security and Retention Notes

## Access control

`admin/audit.php` calls `require_role('admin')`. Do not expose this page through a reverse proxy rule that bypasses PHP authentication.

`api/call_audit_event.php` requires an OTP-verified dispatcher or admin web session. It accepts only a restricted milestone allow-list and a bounded audit-session identifier.

## Sensitive information

The audit helper automatically redacts metadata keys associated with:

- passwords and password hashes;
- OTP values;
- access/refresh tokens;
- API keys;
- authorization and cookie values;
- session identifiers;
- secrets/private keys;
- Firebase tokens.

The live call audit endpoint intentionally does not accept or store caller name or phone number. The normal call/incident tables continue to contain the operational data already required by the system.

The audit trail may still contain incident references, actor email, IP address, user agent, locations in legacy details, and operational metadata. Treat the page and exported CSV as restricted records.

## Timestamp trust

Browser/app-provided operational timestamps are normalized to Asia/Manila and accepted only inside a bounded window: no more than 30 days in the past and no more than five minutes in the future. Canonical database timestamps remain the fallback source.

## Audit integrity

`event_key` deduplicates idempotent milestones. During call intake, provisional call-session rows may be enriched later with the canonical call/incident entity and reference. The original action, actor, outcome, and timestamp are not rewritten during this linking step.

Use a database account with only the permissions the application needs. Restrict direct write access to `activity_log`; operational users should interact through the application rather than SQL tools.

## Retention

Set a written retention schedule based on your organization’s operational, contractual, privacy, records-management, and legal requirements. A common technical pattern is:

- retain recent logs in the primary database for fast investigation;
- archive older logs to encrypted, access-controlled storage;
- verify backups and restoration regularly;
- delete records only under an approved policy and documented process.

This package does not impose an automatic purge because the correct period must be determined by your organization.

## Storage growth

Do not copy every GPS point into `activity_log`. Route history already stores those points. The audit trail records only route start and arrival, which reduces storage growth and location exposure.

Monitor:

- `activity_log` row count and table size;
- index size;
- CSV export usage;
- failed/warning events;
- unexpected actors or source channels.

## Export handling

The CSV writer mitigates spreadsheet formula injection for values beginning with `=`, `+`, `-`, or `@`. Exported files must still be handled as sensitive operational data and stored/transmitted through approved channels only.
