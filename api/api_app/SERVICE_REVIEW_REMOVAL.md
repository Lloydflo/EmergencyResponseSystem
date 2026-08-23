# Service-review removal and report approval workflow

The API no longer exposes responder Service Review/star-rating endpoints. Create Report remains, with the workflow:

```text
Pending -> Submitted -> Approved
                      \-> Returned / Needs Revision -> Pending
```

## Removed endpoints

- `get-pending-review-incidents.php`
- `get-my-incident-reviews.php`
- `submit-incident-review.php`

Historical `incident_reviews` data is not deleted.

## Responder endpoints

```text
GET  get-completed-incidents.php?responder_id={id}
GET  get-my-after-action-reports.php?responder_id={id}
POST upsert-after-action-report.php
```

A responder may save `status=pending` (legacy alias `draft`) or submit `status=submitted`. Submitted and approved reports are read-only to the responder. Returned reports become editable again.

## Admin review endpoints

```text
GET  get-after-action-reports.php?reviewer_id={adminId}&status=submitted
POST review-after-action-report.php
```

Approve:

```text
reviewer_id=ADMIN_ID
report_id=REPORT_ID
action=approve
notes=Optional approval notes
```

Return for revision:

```text
reviewer_id=ADMIN_ID
report_id=REPORT_ID
action=return
notes=Required revision instructions
```

The admin list response includes the submitted report plus the source incident reference, type, description, priority, location, completion notes/image, and completion time so the reviewer can assess legitimacy before approving.

For backward compatibility the table still stores `draft`, `submitted`, `verified`, and `returned`. API responses additionally return `workflow_status` as `pending`, `submitted`, `approved`, or `revision_required`.
