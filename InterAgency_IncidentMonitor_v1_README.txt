INTER-AGENCY ACTIVE INCIDENT MONITOR v1
Build: 20260807-incident-monitor-v1

PURPOSE
- Removes the duplicate Workflow Lanes panel from the upper-right Operations Desk area.
- Replaces it with an Incident Monitor containing two working tabs:
  1. Active Incidents — lists all currently active incidents.
  2. Focused Incident — shows the existing live incident summary card.
- Moves the existing active incident banner out of the chat column so it no longer covers or compresses chat messages.
- Keeps the four full module launchers below (Command Center, Event Coordination, Anonymous Tip Inbox, External Incident Inbox) unchanged.

CHANGED FILES
- js/interagency-operations.js
- css/interagency-command.css

NO DATABASE OR API MIGRATION
- Uses the existing authenticated endpoint: api/incidents_list.php?status=active
- Uses the existing incident-details modal through the page's current event handler.
- No schema, CRUD, messaging, or module API changes.

INSTALL IN VS CODE / WINDOWS
1. Extract InterAgency_IncidentMonitor_v1.zip directly into the project root.
2. Confirm the final paths are:
   D:\EmergencyResponseSystem\js\interagency-operations.js
   D:\EmergencyResponseSystem\css\interagency-command.css
3. Commit and push:

   cd D:\EmergencyResponseSystem
   git add js/interagency-operations.js css/interagency-command.css
   git commit -m "Replace workflow lanes with active incident monitor"
   git push origin main

PRODUCTION DEPLOYMENT
   cd /var/www/emergency_response_alertaraqc
   git pull --ff-only origin main
   sudo systemctl restart php8.3-fpm
   sudo systemctl reload nginx

Then hard refresh the browser with Ctrl+Shift+R.

SERVER VERIFICATION
   grep -n "20260807-incident-monitor-v1" js/interagency-operations.js
   grep -n "Active incident monitor" css/interagency-command.css
   node --check js/interagency-operations.js

EXPECTED RESULT
- The upper-right panel says Incident Monitor instead of Workflow Lanes.
- Active Incidents is the default tab and shows the live active-incident queue.
- Focused Incident shows the existing detailed active-incident card.
- Clicking an incident opens the existing incident-details dialog.
- The chat area begins directly with typing state/messages and is no longer obstructed by the active-incident card.
