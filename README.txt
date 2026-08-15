Anonymous Tip Inbox — Single Incident Action + UI Polish v2
Build: 20260808-admin-tip-details-v2

PURPOSE
- Keep only one admin incident-details action for converted/linked anonymous tips.
- Keep the admin inside Inter-Agency Conversations and show read-only details only.
- Prevent long descriptions from crowding the date/source and workflow footer.
- Improve spacing and hierarchy in the linked-incident area.

CHANGED FILES
- admin/interagency.php
- css/admin-anonymous-tip.css
- js/admin-anonymous-tip-details.js

FINAL ADMIN BEHAVIOR
- The Linked Incident card is the single clickable control.
- The duplicate Open Incident / View Incident Details quick-action is removed.
- Converted tips do not show a dead grid of disabled review actions.
- Clicking the Linked Incident card opens the existing centered read-only incident modal.
- No redirect to dispatcher/incident.php or the Admin Dashboard.
- Admin receives incident information only; dispatcher controls remain unavailable.
- Queue descriptions are limited to two lines, while date, source, and workflow remain visible.

INSTALLATION — VS CODE / POWERSHELL
1. Synchronize first:
   cd D:\EmergencyResponseSystem
   git pull --rebase origin main

2. Extract the ZIP directly into D:\EmergencyResponseSystem and replace existing files.

3. Verify:
   git status --short
   Select-String -Path .\admin\interagency.php, .\css\admin-anonymous-tip.css, .\js\admin-anonymous-tip-details.js -Pattern "20260808-admin-tip-details-v2"

4. Commit and push:
   git add admin/interagency.php css/admin-anonymous-tip.css js/admin-anonymous-tip-details.js
   git commit -m "Remove duplicate anonymous tip incident action"
   git pull --rebase origin main
   git push origin main

DEPLOYMENT — PRODUCTION SERVER
   cd /var/www/emergency_response_alertaraqc
   git pull --ff-only origin main
   sudo systemctl restart php8.3-fpm
   sudo systemctl reload nginx

Then hard refresh the browser with Ctrl + Shift + R.

VERIFICATION
- There must be only one incident-details control in a converted tip.
- The control must be inside the Linked Incident card.
- The browser URL must remain /admin/interagency.php when it is clicked.
- A centered Administrator read-only view modal must open.
- Long descriptions must not hide the date/source row.

No database migration is required. Anonymous-tip status, conversion, evidence,
incident, and dispatch APIs are unchanged.
