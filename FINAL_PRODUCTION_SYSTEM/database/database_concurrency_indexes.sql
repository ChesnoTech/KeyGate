-- Database Performance Indexes for Concurrency Optimization
-- OEM Activation System v2.0
-- Run this after the main database installation

-- Optimize key selection queries (CRITICAL for allocateKeyAtomically)
ALTER TABLE oem_keys 
ADD INDEX idx_status_fail_date (key_status, fail_counter, last_use_date, id);

-- Optimize session lookups for concurrent access
ALTER TABLE active_sessions 
ADD INDEX idx_tech_active_expires (technician_id, is_active, expires_at);

-- Optimize activation attempts queries
ALTER TABLE activation_attempts 
ADD INDEX idx_key_tech_date (key_id, technician_id, attempted_at);

-- Optimize admin session queries
ALTER TABLE admin_sessions 
ADD INDEX idx_admin_active_expires (admin_id, is_active, expires_at);

-- Composite index for common technician queries
ALTER TABLE technicians 
ADD INDEX idx_active_locked (is_active, locked_until);

-- Index for cleanup operations (expired sessions)
ALTER TABLE active_sessions 
ADD INDEX idx_expires_active (expires_at, is_active);

-- Index for audit trail queries
ALTER TABLE admin_activity_log 
ADD INDEX idx_admin_action_time (admin_id, action, created_at);

-- Index for key usage statistics
ALTER TABLE oem_keys 
ADD INDEX idx_first_usage (first_usage_date, key_status);

-- Update table statistics for better query planning
ANALYZE TABLE oem_keys;
ANALYZE TABLE active_sessions;
ANALYZE TABLE technicians;
ANALYZE TABLE activation_attempts;
ANALYZE TABLE admin_sessions;