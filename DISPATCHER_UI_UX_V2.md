# Dispatcher UI/UX v4

## Deployment

1. Back up the application and database.
2. Replace the project files while preserving their paths.
3. Run `data/sql/2026_08_13_incident_intake_source.sql` once.
4. Restart Apache/PHP-FPM if OPcache is enabled, then reload the Dispatcher pages.

`api/calls_create.php` also attempts to add the nullable source column safely,
but running the migration during deployment is the recommended path.

## Intake-source behavior

- `TRN-*`: **Call** from the partner emergency app
- `REF-*` with recorded call source or a bound `call_accepted` audit: **Call**
  from this website
- Other `REF-*`: **Manual** website incident
- `TIP-*`: **TIP** converted to an incident

The four visible filters are exactly **All**, **Call**, **Manual**, and **TIP**.
No pending incident is removed from All. A `TRN-*` incident always contributes
to Call; website calls and manual records remain distinguishable inside the
shared `REF-*` namespace through existing durable source/audit evidence.

## Dispatcher-wide incoming-call alert

- Every Dispatcher submodule now checks a session-protected, read-only alert
  feed for recent unanswered partner-app live calls.
- The visible alert and sidebar badge open **Call Receiving & Logs**.
- The alert performs no accept, reject, acknowledge, Socket.IO emit, WebRTC
  operation, or call-status write. The existing Call Receiving page remains the
  sole owner of the answer and logging process.
- The Call Receiving page suppresses the duplicate global alert because it
  already has the full incoming-call interface.

## UI fixes

- Incident Priority uses one readable queue column, compact operational
  summaries, labeled actions, and no horizontal scrolling.
- Incident actions remain labeled and touch-sized.
- Dispatch Center has exactly four visible source filters with live counts.
  Transferred app calls and website calls are combined under Call.
- Call Center layout is visually reorganized by CSS only. `dispatcher/call.php`,
  `api/calls_create.php`, Socket.IO/WebRTC, accept/reject, transfer, and logging
  behavior are unchanged from the uploaded project.
- Dispatch, Details, Call Reporter, unit assignment, and map workflows are unchanged.
