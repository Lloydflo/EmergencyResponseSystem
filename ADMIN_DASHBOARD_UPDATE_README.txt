ALERTARA QC — ADMIN DASHBOARD ACCURACY UPDATE v2
Build: 20260807-admin-dashboard-accurate-v2

CHANGED FILES
-------------
admin/index.php
api/admin_dashboard_summary.php
api/admin_dashboard_weather.php
api/alerts_active.php
includes/dashboard_weather.php
css/dashboard.css
js/admin-dashboard.js

WHAT CHANGED
------------
1. Dashboard metrics now use real API responses only. The legacy random-number
   refresh behavior and duplicate dashboard scripts were removed.
2. Month-to-date incident charts use the same created_at date scope as the
   "Incidents this month" metric. Chart totals therefore reconcile with the
   monthly metric.
3. Critical priority is a separate category and is no longer counted as Low.
4. Unknown incident types/priorities are retained in an Other category instead
   of being silently dropped.
5. "Active Users" was renamed to "Active Accounts" because the source is the
   users.status account flag, not a live online-presence signal.
6. "Resource Records" was renamed to "Registered Units" and prefers the units
   table, with compatibility fallbacks for legacy resource tables.
7. Weather moved out of the page-render request into a protected JSON endpoint.
   It now uses exact coordinates, a five-minute server cache, observation time,
   provider attribution, stale-cache fallback, and explicit unavailable states.
8. Active Alerts now uses the same cached weather payload as the widget and no
   longer trusts a weather condition supplied by a GET parameter.
9. Metric cards use a balanced responsive grid; charts, feeds, modals, header
   spacing, and dark mode were reorganized.

WEATHER CONFIGURATION
---------------------
The default coordinates are near Quezon City Hall / the command-center area:
  Latitude:  14.6507
  Longitude: 121.0494
  Label:     Quezon City Command Center

Override them at the PHP-FPM environment level when your command center has a
more exact GPS position:
  ERS_WEATHER_LAT=14.6507
  ERS_WEATHER_LON=121.0494
  ERS_WEATHER_LOCATION="LGU #4 Command Center, Quezon City"

The weather source is Open-Meteo and no API key is committed to the repository.
Outbound HTTPS access from PHP-FPM is required. cURL is preferred; PHP stream
fallback is supported. During a short provider outage, a cached observation up
to six hours old is clearly labeled as cached. Older data is not shown as live.

DEPLOYMENT
----------
1. Extract this ZIP directly over the EmergencyResponseSystem project root.
2. Review git status.
3. Commit and push the seven changed files.
4. On production, pull main and restart PHP-FPM.
5. Hard-refresh the browser.

LOCAL COMMIT EXAMPLE (PowerShell)
---------------------------------
git add admin/index.php `
  api/admin_dashboard_summary.php `
  api/admin_dashboard_weather.php `
  api/alerts_active.php `
  includes/dashboard_weather.php `
  css/dashboard.css `
  js/admin-dashboard.js

git commit -m "Fix admin dashboard data and live weather accuracy"
git push origin main

PRODUCTION
----------
git pull --ff-only origin main
sudo systemctl restart php8.3-fpm
sudo systemctl reload nginx

SERVER VERIFICATION
-------------------
grep -n "20260807-admin-dashboard-accurate-v2" admin/index.php
php -l admin/index.php
php -l api/admin_dashboard_summary.php
php -l api/admin_dashboard_weather.php
php -l api/alerts_active.php
php -l includes/dashboard_weather.php
node --check js/admin-dashboard.js

Open these while signed in as admin:
  /api/admin_dashboard_summary.php
  /api/admin_dashboard_weather.php

Expected JSON fields:
  summary: ok=true, metrics, charts, scope, generated_at
  weather: ok=true, observation, next_hour, today, provider, coordinates
