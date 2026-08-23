ERS Operational Audit Trail v5 — Patch Package

1. Back up the production website and database.
2. Run data/sql/2026_08_05_operational_audit_trail.sql.
3. Copy this package over the project document root, preserving folders.
4. Clear PHP OPcache/application cache.
5. Run data/sql/2026_08_05_verify_operational_audit.sql.
6. Perform the dispatcher → responder → admin staging test in
   deployment_docs/README_DEPLOYMENT_TAGALOG.md.

PATCH_MANIFEST.txt lists the 28 intentional application/database files.
Do not delete unrelated production files.
