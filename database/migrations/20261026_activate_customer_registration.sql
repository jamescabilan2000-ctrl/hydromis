-- Customer registration no longer requires administrator approval.
-- Preserve accounts explicitly disabled by an administrator.
UPDATE users SET status='approved' WHERE status='pending';
ALTER TABLE users ALTER COLUMN status SET DEFAULT 'approved';
