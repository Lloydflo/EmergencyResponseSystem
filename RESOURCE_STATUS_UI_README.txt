RESOURCE STATUS — OPERATIONAL CARD UI + VEHICLE NUMBER LABEL
Build: 20260807-resource-readiness-cards-v1

PURPOSE
- Replaces the database-like main resource table with operational readiness cards.
- Shows a contextual user-facing identifier:
  * Vehicle No. for vehicles
  * Personnel No. for personnel
  * Equipment No. for equipment
- Keeps the existing internal database primary key and API contract unchanged.

FILES
- admin/resources.php
- css/admin-resources.css

WHAT CHANGED
- Operational header and icon-based readiness summary.
- All / Vehicles / Personnel / Equipment category filters with live counts.
- Search now includes vehicle/resource number, plate number, position, location,
  assignment, and notes.
- Card view includes status, assignment, plate/position/quantity, location or
  tracking source, last update, Edit, and Archive actions.
- Add/Edit form label changes automatically by category.
- Backup resource picker uses Operational No. rather than the ambiguous ID label.
- Responsive desktop, tablet, mobile, and dark-theme styles.

UNCHANGED
- Database schema and primary keys.
- api/admin_resources.php and its request/response fields.
- Add, edit, archive, restore, backup request, filtering, and autocomplete logic.

LOCAL DEPLOYMENT (PowerShell)
1. Extract the ZIP directly into D:\EmergencyResponseSystem and replace files.
2. Run:

   cd D:\EmergencyResponseSystem
   git status --short
   git add admin/resources.php css/admin-resources.css
   git commit -m "Redesign resource status as operational cards"
   git push origin main

PRODUCTION DEPLOYMENT
   cd /var/www/emergency_response_alertaraqc
   git pull --ff-only origin main
   sudo systemctl restart php8.3-fpm
   sudo systemctl reload nginx

Then hard-refresh the browser with Ctrl + Shift + R.

SERVER VERIFICATION
   grep -n "20260807-resource-readiness-cards-v1" admin/resources.php
   grep -n "Vehicle No." admin/resources.php
   grep -n "admin-resources.css" admin/resources.php
   php -l admin/resources.php
