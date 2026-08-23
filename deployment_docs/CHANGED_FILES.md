# Intentional Changed Files

## Admin interface

- `admin/audit.php` — organized Operational Audit Trail, filters, pagination, CSV, Log No., lifecycle, and durations.
- `includes/sidebar.php` — sidebar label and dispatcher active-call audit-session persistence/end event.

## Shared audit/authentication

- `includes/activity_log.php` — structured event writer, actor/source/category/outcome inference, redaction, deduplication, request context, timestamp normalization, and legacy fallback.
- `includes/auth.php` — structured web logout context while preserving compatible actions.
- `otp.php` — structured OTP-verified web login event.

## Dispatcher and web API

- `dispatcher/call.php` — immediate call received/accepted/rejected/ended milestones, exact timestamps, and removal of duplicate legacy call log request.
- `api/call_audit_event.php` — authenticated live call-milestone endpoint.
- `api/calls_create.php` — canonical call/incident audit events, timestamp fallback, and audit-session linking.
- `api/dispatch_unit.php` — dispatch success/failure events.
- `api/incident_update.php` — structured incident change event.
- `api/incident_resolve.php` — incident resolution event.
- `api/activity_event.php` — structured coordination activity while preserving existing message behavior.

## Responder app backend

- `api/api_app/login.php`
- `api/api_app/logout.php`
- `api/api_app/mark-assignment-received.php`
- `api/api_app/update-assignment-status.php`
- `api/api_app/save-route-point.php`
- `api/api_app/mark-route-arrived.php`
- `api/api_app/_assignment.php`
- `api/api_app/upsert-after-action-report.php`
- `api/api_app/review-after-action-report.php`
- `api/api_app/send-backup-request.php`
- `api/api_app/cancel-backup-request.php`
- `api/api_app/send-resource-request.php`
- `api/api_app/cancel-resource-request.php`

## Database

- `data/sql/2026_08_05_operational_audit_trail.sql` — production migration.
- `data/sql/2026_08_05_verify_operational_audit.sql` — read-only checks.
- `data/sql/emergency_response_test.sql` — fresh-install activity-log schema/index alignment.
