# Dispatcher UI/UX v3

## Deployment

1. Back up the application and database.
2. Replace the project files while preserving their paths.
3. Run `data/sql/2026_08_13_incident_intake_source.sql` once.
4. Restart Apache/PHP-FPM if OPcache is enabled, then reload the Dispatcher pages.

`api/calls_create.php` also attempts to add the nullable source column safely,
but running the migration during deployment is the recommended path.

## Intake-source behavior

- Accepted live call: **Emergency Call**
- Create Incident form: **Manual Entry**
- Converted anonymous tip: **Converted TIP**
- Incoming external/group case: stays visible in **All** with its source badge
- Historical row without durable provenance: stays visible in **All** as an
  unverified legacy source

The four visible filters are **All**, **Calls**, **Manual**, and **TIP**. No
pending incident is removed from All. Historical ambiguous rows are not guessed
as Manual or Call because the former schema used the same call/incident records
for both flows. Existing TIP, external, and bound accepted-call records are
recognized from their durable link/audit evidence at read time. A linked
`call_accepted` audit is shown in Calls even when its original incident record
also carries an external source label.

## UI fixes

- Incident Priority uses one readable queue column, compact operational
  summaries, labeled actions, and no horizontal scrolling.
- Incident actions remain labeled and touch-sized.
- Dispatch Center has exactly four visible source filters with live counts.
  External and legacy items remain identified inside All without adding tabs.
- Call Center layout is visually reorganized by CSS only. `dispatcher/call.php`,
  `api/calls_create.php`, Socket.IO/WebRTC, accept/reject, transfer, and logging
  behavior are unchanged from the uploaded project.
- Dispatch, Details, Call Reporter, unit assignment, and map workflows are unchanged.
