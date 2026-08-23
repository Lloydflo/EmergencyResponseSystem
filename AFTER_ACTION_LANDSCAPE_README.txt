AFTER-ACTION REVIEW LANDSCAPE UX — v2
Build: 20260807-after-action-landscape-v2

CHANGED FILES
- admin/review.php
- js/admin-review-after-action.js
- css/admin-after-action-landscape.css (new)

WHAT CHANGED
- Wide landscape review modal sized for desktop review workflows.
- Fixed modal header with independent scrolling for incident context, report document, and supporting evidence.
- Incident context moved to the left rail.
- Full responder report stays in the center workspace.
- Admin decision note and Approve / Return actions remain visible beside the report on wide screens.
- Operational notes and responder proof photos moved to the right rail.
- Compact two-column report fields reduce vertical scrolling.
- Maximize / restore button added beside the Close button.
- Responsive two-column and single-column fallback for smaller screens.
- Dark mode retained.

UNCHANGED
- Approval / return API endpoint and database update behavior.
- Report status mapping and responder Approved tab workflow.
- Incident, proof, operational-note, and queue APIs.
- Database schema.

DEPLOYMENT
1. Extract this ZIP directly into the project root and replace existing files.
2. Commit and push the three application files.
3. Pull main on production.
4. Restart PHP-FPM and reload Nginx.
5. Hard-refresh the browser.

SERVER VERIFICATION
  grep -n "20260807-after-action-landscape-v2" admin/review.php
  grep -n "admin-after-action-landscape.css" admin/review.php
  php -l admin/review.php
  node --check js/admin-review-after-action.js
