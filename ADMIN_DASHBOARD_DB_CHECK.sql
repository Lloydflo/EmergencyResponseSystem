-- Read-only verification queries for Admin Dashboard Accuracy Update v2
-- Run against the production database after deployment.

SET @scope_start = DATE_FORMAT(CONVERT_TZ(NOW(), @@session.time_zone, '+08:00'), '%Y-%m-01 00:00:00');
SET @scope_end = DATE_ADD(DATE(CONVERT_TZ(NOW(), @@session.time_zone, '+08:00')), INTERVAL 1 DAY);

-- Metric: incidents created during the current month to date.
SELECT COUNT(*) AS monthly_incidents
FROM incidents
WHERE created_at >= @scope_start
  AND created_at < @scope_end;

-- Chart reconciliation: every month-to-date incident belongs to one type bucket.
SELECT
    CASE
        WHEN LOWER(COALESCE(type, '')) REGEXP 'medical|ambulance|health' THEN 'medical'
        WHEN LOWER(COALESCE(type, '')) REGEXP 'fire|rescue' THEN 'fire'
        WHEN LOWER(COALESCE(type, '')) REGEXP 'police|crime|security' THEN 'police'
        WHEN LOWER(COALESCE(type, '')) REGEXP 'traffic|accident|vehicle|road' THEN 'traffic'
        ELSE 'other'
    END AS type_bucket,
    COUNT(*) AS total
FROM incidents
WHERE created_at >= @scope_start
  AND created_at < @scope_end
GROUP BY type_bucket
ORDER BY total DESC, type_bucket;

-- Chart reconciliation: Critical is separate and unknown priorities go to Other.
SELECT
    CASE
        WHEN LOWER(COALESCE(priority, '')) IN ('critical', 'emergency') THEN 'critical'
        WHEN LOWER(COALESCE(priority, '')) IN ('high', 'urgent') THEN 'high'
        WHEN LOWER(COALESCE(priority, '')) IN ('medium', 'moderate', 'normal') THEN 'medium'
        WHEN LOWER(COALESCE(priority, '')) = 'low' THEN 'low'
        ELSE 'other'
    END AS priority_bucket,
    COUNT(*) AS total
FROM incidents
WHERE created_at >= @scope_start
  AND created_at < @scope_end
GROUP BY priority_bucket
ORDER BY total DESC, priority_bucket;

-- Metric: all non-terminal incidents currently in the operational queue.
SELECT COUNT(*) AS open_incidents
FROM incidents
WHERE TRIM(COALESCE(status, '')) <> ''
  AND LOWER(TRIM(status)) NOT IN (
      'resolved', 'completed', 'closed',
      'cancelled', 'canceled', 'rejected', 'invalid', 'duplicate'
  );

-- Metric: active account records (not current online-presence sessions).
SELECT COUNT(*) AS active_accounts
FROM users
WHERE LOWER(COALESCE(status, '')) = 'active';

-- Metric: registered response units when the units table is deployed.
SELECT COUNT(*) AS registered_units
FROM units;
