AFTER-ACTION REVIEW & APPROVAL UPDATE v1
Build: 20260806-after-action-approval-v1

CHANGED FILES
- admin/review.php
- js/admin-review-after-action.js (new)
- api/incident_feedback.php
- api/api_app/review-after-action-report.php

FUNCTIONAL CHANGES
1. Review & Feedback now loads responder after-action reports into the admin queue.
2. Rating cards, stars, rating counts, and average-rating UI were removed.
3. The View Feedback / Review Report modal shows the full structured after-action report.
4. A submitted report provides two admin decisions:
   - Approve Report
   - Reject / Return for Revision (admin note required)
5. Approval updates responder_after_action_reports:
   status = 'approved'
   reviewer_user_id = authenticated reviewer
   reviewer_notes = submitted admin note
   reviewed_at = current database timestamp
6. Returned reports use status = 'returned' and become editable by the responder.
7. get-my-after-action-reports.php already maps status='approved' to
   workflow_status='approved', so the responder Approved tab receives it.
8. Existing legacy status='verified' records remain compatible.
9. The admin queue refreshes automatically every 30 seconds while the modal is closed.

DATABASE
No schema migration is required. The supplied responder_after_action_reports table
already contains status, reviewer_user_id, reviewer_notes, and reviewed_at.

Ratings were removed from this admin workflow without dropping legacy rating columns,
so no existing database data is deleted and other legacy clients remain compatible.

DEPLOYMENT
Extract this ZIP into the EmergencyResponseSystem project root and replace files.
Then commit and push from the development machine. On production:

  git pull --ff-only origin main
  sudo systemctl restart php8.3-fpm
  sudo systemctl reload nginx

Hard refresh the browser after deployment.
