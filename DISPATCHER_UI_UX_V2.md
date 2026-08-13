# Dispatcher UI/UX v2

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
- Incoming external/group case: **Inter-agency**
- Historical row without durable provenance: **Needs review**

Historical ambiguous rows are not guessed as Manual or Call. The former schema
used the same call/incident records for both flows, so a guess could hide an
operational case under the wrong filter. Newly created records are persisted
with an exact source, and accepted-call audit rows are linked to their call ID.
Existing TIP, inter-agency, and bound accepted-call records are recognized from
their durable link/audit evidence at read time.

## UI fixes

- Incident Priority styles are cache-versioned, and incident cards adapt from
  two columns to one without horizontal scrolling.
- Incident actions remain labeled and touch-sized.
- Dispatch Center has live source counts, explicit source badges, and a separate
  Needs review queue for legacy records.
- Dispatch, Details, Call Reporter, unit assignment, and map workflows are unchanged.
