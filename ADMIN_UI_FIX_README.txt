ADMIN RESOURCES + INTER-AGENCY UI FIX v1
Build date: 2026-08-07

CHANGED FILES
1. admin/resources.php
2. css/interagency-command.css

ADMIN RESOURCES
- Removes the Request Backup button from the admin Resource Status header.
- Keeps the dispatcher backup-request workflow untouched.
- Changes the status filter label from "All Statuses" to "All Status".
- Adds a null guard so the existing dormant request-modal script cannot throw
  when the admin trigger is absent.
- Updates the resource UI build marker to:
  20260807-admin-resource-cleanup-v2

INTER-AGENCY COORDINATION
- Changes the conversation filters to a 2x2 layout so All, Departments,
  Responders, and Groups are fully readable.
- Stacks Add Conversation and Create Group controls beside the filters.
- Adds text-containment rules for workflow lanes, metric notes, module cards,
  chat headings, and thread metadata.
- Adds a compact layout for screens at 420px and below.

NO DATABASE MIGRATION
No database tables, API payloads, CRUD behavior, archive behavior, or
coordination functionality were changed.

DEPLOYMENT
Extract this ZIP into the EmergencyResponseSystem project root and replace the
existing files. Final paths must be:

EmergencyResponseSystem/admin/resources.php
EmergencyResponseSystem/css/interagency-command.css

Then commit and deploy normally. Use Ctrl+Shift+R after deployment so the
browser revalidates interagency-command.css.
