# Service-review removal

This API package no longer exposes the responder service-review and star-rating workflow.

Removed endpoints:

- `get-pending-review-incidents.php`
- `get-my-incident-reviews.php`
- `submit-incident-review.php`

Replacement endpoint used by the responder app:

- `GET get-completed-incidents.php?responder_id={id}`

The replacement returns completed incidents for After-Action Report creation and does not return review status or rating fields. Existing database columns or historical `incident_reviews` rows are intentionally left untouched so deployment does not destroy prior data. They can be archived separately after all clients have been upgraded.
