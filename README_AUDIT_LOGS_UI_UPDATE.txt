EMERGENCY RESPONSE SYSTEM — AUDIT LOGS UI UPDATE
=================================================

Scope
-----
This update reorganizes only the Audit Logs interface. Existing audit-log
queries, filtering semantics, CSV export, lifecycle lookup, and record-writing
behavior were not changed.

Changed files
-------------
1. admin/audit.php   — reorganized Audit Logs page markup and presentation
2. css/audit.css     — new dedicated responsive/dark-mode styles
3. js/audit.js       — new accessible audit-detail dialog behavior

UI improvements
---------------
- Compact primary search for general terms and incident references
- Collapsible advanced filters for role, source, process, outcome, dates,
  and page size
- Active-filter chips with individual removal and Clear all
- Consolidated table columns for a cleaner desktop layout
- Responsive record cards on narrower tablet/mobile widths
- Technical metadata moved into a per-record View dialog
- Improved empty/error states, pagination, hierarchy, spacing, and readability
- Dark-mode and reduced-motion support

Installation (focused package)
------------------------------
Back up the current project, then copy the admin, css, and js folders from this
package into the project root while preserving their paths. Allow the three
listed files to be added/replaced, then hard-refresh the browser.

Validation completed
--------------------
- PHP syntax: passed
- JavaScript syntax: passed
- CSS parse: passed (0 parse errors)
- Desktop/tablet/mobile render checks: passed; no horizontal page overflow
- Audit detail dialog open/focus/close behavior: passed

Packaging note
--------------
The full-project ZIP excludes the .git directory and temporary preview files.
It preserves the rest of the project state that was present in the uploaded
archive.
