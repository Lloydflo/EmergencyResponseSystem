-- Read-only verification query for the after-action approval workflow.
-- Replace 1 with the report ID that was reviewed.
SELECT
    id,
    incident_id,
    responder_id,
    responder_name,
    status,
    reviewer_user_id,
    reviewer_notes,
    submitted_at,
    reviewed_at,
    updated_at
FROM responder_after_action_reports
WHERE id = 1;

-- Expected after approval:
-- status = 'approved'
-- reviewer_user_id IS NOT NULL
-- reviewed_at IS NOT NULL
--
-- Expected after Reject / Return for Revision:
-- status = 'returned'
-- reviewer_notes is populated
-- reviewed_at IS NOT NULL
