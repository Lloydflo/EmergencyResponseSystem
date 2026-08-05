# Admin After-Action Report Review API

## Purpose

Ang endpoint pair na ito ang ginagamit ng authorized admin/dispatcher/operator para:

1. makita ang submitted responder report kasama ang source incident evidence;
2. i-check kung legitimate at tugma ang report sa incident record;
3. i-approve ang report; o
4. ibalik ito sa responder na may required revision notes.

## Authorization model used by the existing project

The current API validates that `reviewer_id` belongs to an active user whose role is one of:

```text
admin, dispatcher, operator
```

This preserves the uploaded project's existing ID-based API model. It is not equivalent to a cryptographically authenticated admin session; production hardening should bind the reviewer identity to a server-verified login/session or token rather than trusting a numeric request field alone.

## List reports

```http
GET /api/api_app/get-after-action-reports.php?reviewer_id=12&status=submitted&limit=100
```

Accepted filters:

```text
all
pending
draft
submitted
approved
verified
returned
revision_required
```

Recommended admin review queue:

```text
status=submitted
```

Representative response fields:

```json
{
  "success": true,
  "reports": [
    {
      "id": 91,
      "incident_id": 1402,
      "responder_id": 42,
      "status": "submitted",
      "workflow_status": "submitted",
      "status_label": "Submitted",
      "is_editable": false,
      "incident_summary": "...",
      "actions_taken": "...",
      "incident": {
        "id": 1402,
        "reference_no": "INC-2026-1402",
        "type": "fire",
        "title": "...",
        "description": "...",
        "priority": "high",
        "status": "resolved",
        "location_address": "...",
        "completion_notes": "...",
        "completion_image_path": "https://...",
        "completed_at": "2026-08-05 09:20:00"
      }
    }
  ]
}
```

## Approve

```bash
curl -i -X POST \
  --data-urlencode 'reviewer_id=12' \
  --data-urlencode 'report_id=91' \
  --data-urlencode 'action=approve' \
  --data-urlencode 'notes=Incident evidence and operational report verified.' \
  'https://emergency-response.alertaraqc.com/api/api_app/review-after-action-report.php'
```

API workflow result:

```text
approved
```

Legacy database value retained:

```text
verified
```

## Return for revision

```bash
curl -i -X POST \
  --data-urlencode 'reviewer_id=12' \
  --data-urlencode 'report_id=91' \
  --data-urlencode 'action=return' \
  --data-urlencode 'notes=Please reconcile the assisted-person count with the completion notes.' \
  'https://emergency-response.alertaraqc.com/api/api_app/review-after-action-report.php'
```

`notes` is required for `action=return`.

API workflow result:

```text
revision_required
```

Legacy database value retained:

```text
returned
```

## State transition rules

```text
Pending (draft)
    -> Submitted
        -> Approved (verified)
        -> Needs Revision (returned)
            -> Pending/editable
            -> Submitted again
```

Only `submitted` reports may be approved or returned. Submitted and approved reports are read-only to the responder. Re-submitting a returned report clears the old review decision fields and places it back in the admin queue.
