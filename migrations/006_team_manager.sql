-- Migration 006: Add team manager relationship
-- Allows users to be assigned to a manager (for team goals)

ALTER TABLE users ADD COLUMN IF NOT EXISTS manager_id INT NULL REFERENCES users(id) ON DELETE SET NULL;

-- Index for faster team lookups
CREATE INDEX IF NOT EXISTS idx_users_manager ON users(manager_id);
