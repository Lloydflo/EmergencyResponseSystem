# Operational Audit Event Catalog

## Structured fields

Ang migration ay nagdadagdag ng sumusunod sa `activity_log`:

| Field | Gamit |
|---|---|
| `actor_name`, `actor_email`, `actor_role` | Snapshot ng gumawa ng action sa oras ng event |
| `source_channel` | `responder_app`, `dispatcher_web`, `admin_web`, `external_api`, `server_api`, o `system` |
| `event_category` | Organized process group |
| `event_outcome` | `success`, `warning`, `failed`, o `info` |
| `reference_no` | Incident/call reference para sa filtering at lifecycle |
| `metadata_json` | Sanitized structured context |
| `ip_address`, `user_agent` | Request context kapag available |
| `request_id` | Correlation ID ng server request |
| `event_key` | Deduplication key para sa idempotent milestones |
| `created_at` | Authoritative event time sa operational timeline |

Ang internal `activity_log.id` ay nananatiling database primary key, ngunit hindi ito ipinapakita bilang front-facing ID. Ang admin table ay gumagamit ng **Log No.** bilang sequential result count.

## Dispatcher call intake

| Action | UI label | Trigger | Time source | Outcome |
|---|---|---|---|---|
| `call_received` | Emergency Call Received | Incoming call is presented to dispatcher queue, at canonical fallback during incident creation | Incoming payload `start`/`received_at` | Success |
| `call_accepted` | Emergency Call Accepted | Dispatcher clicks Accept | Browser click timestamp, server-bounded | Success |
| `call_rejected` | Emergency Call Rejected | Dispatcher clicks Reject | Browser click timestamp, server-bounded | Warning |
| `call_ended` | Emergency Call Ended | Active call/session ends | Browser end timestamp, server-bounded | Info |
| `incident_created` | Incident Record Created | Call intake is converted to an incident | Canonical `incidents.created_at` | Success |

Ang browser ay gumagawa ng random `audit_session_id`. Ginagamit ito para ma-deduplicate at mai-link ang live call milestones sa final call/incident record pagkatapos ma-log ang incident. Walang caller name o phone number sa live call-audit request metadata.

## Dispatch and incident operations

| Action | Trigger | Actor/source |
|---|---|---|
| `dispatch_confirmed` | Successful unit/responder dispatch | Dispatcher/Admin website |
| `dispatch_failed` | Failed dispatch attempt | Dispatcher/Admin website |
| `incident_updated` | Auditable incident field change | Dispatcher/Admin website |
| `incident_resolved` | Incident marked resolved/completed | Responder app or operations website |

For description/location edits, structured metadata records that the value changed without duplicating old/new free-text content.

## Responder assignment and navigation

| Action | Trigger |
|---|---|
| `assignment_received` | Responder acknowledges assignment |
| `navigation_started` | Responder changes assignment to en route |
| `navigation_cancelled` | Responder stops navigation and returns to received |
| `route_tracking_started` | First GPS route point for responder/incident |
| `responder_on_scene` | Responder reports on-scene status |
| `route_arrived` | Route summary records arrival |
| `assignment_completed` | Legacy assignment completion transition |
| `incident_resolved` | Completion workflow validates and closes incident |

Every GPS point remains in `responder_route_history`. Only the first route point and arrival are duplicated as audit milestones.

## Reports and admin review

| Action | Trigger |
|---|---|
| `after_action_report_saved` | Report is saved as Pending |
| `after_action_report_submitted` | Report enters Submitted/admin-review status |
| `after_action_report_approved` | Admin approves report |
| `after_action_report_returned` | Admin returns report for revision |

This is separate from the removed Service Review/star-rating functionality.

## Resources and support

| Action | Trigger |
|---|---|
| `backup_requested` | Responder requests backup |
| `backup_request_cancelled` | Responder cancels backup request |
| `resource_requested` | Responder requests equipment/resource |
| `resource_request_cancelled` | Responder cancels resource request |

## Authentication and coordination

| Action | Trigger |
|---|---|
| `login`, `logout` | OTP-verified web sign-in/sign-out |
| `responder_login`, `responder_logout` | Mobile responder sign-in/sign-out |
| `chat` / message actions | Coordination/interagency activity through existing activity endpoint |

Passwords, OTPs, tokens, API keys, authorization values, cookies, sessions, secrets, and private keys are redacted from structured metadata.

## Incident lifecycle durations

Kapag nag-filter ang admin sa exact reference, kino-compute ng page ang:

1. call receipt → call acceptance;
2. acceptance → incident logging;
3. incident logging → dispatch;
4. dispatch → assignment receipt;
5. assignment receipt → navigation;
6. navigation → arrival;
7. arrival → completion;
8. completion → report submission;
9. report submission → admin review.

Kapag kulang ang source timestamp, `Not yet recorded` ang lalabas sa halip na hulaan ang duration.
