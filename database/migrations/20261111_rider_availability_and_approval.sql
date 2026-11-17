ALTER TABLE rider_users
    ADD COLUMN IF NOT EXISTS last_seen_at DATETIME NULL;

ALTER TABLE transactions
    ADD COLUMN IF NOT EXISTS rider_assignment_status VARCHAR(30) NOT NULL DEFAULT 'unassigned',
    ADD COLUMN IF NOT EXISTS rider_assignment_responded_at DATETIME NULL;

UPDATE transactions
SET rider_assignment_status = 'accepted'
WHERE COALESCE(rider_id, assigned_rider) IS NOT NULL
  AND rider_assignment_status = 'unassigned';
