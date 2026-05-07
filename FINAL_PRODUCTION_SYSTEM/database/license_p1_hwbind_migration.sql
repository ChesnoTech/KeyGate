-- =============================================================
-- KeyGate v2.3.0 P1 — Hardware-bound licensing
-- =============================================================
-- Binds each license_info row to the host's server-side hardware
-- fingerprint (composite SHA256 of machine-id | system-uuid | NIC
-- MAC | root volume UUID). A license cannot be moved to a different
-- VM/host without invoking the Worker /api/rebind route, which is
-- quota-limited (3 rebinds per rolling 365 days).
--
-- Enum extension adds:
--   'rebinding_required' — set by getEffectiveLicense() when the
--     host's current hwfp diverges from the row's bound hwfp; UI
--     directs admin to click "Rebind hardware".
--   'clock_drift'        — reserved for P2 (clock-rollback defense).
-- =============================================================

ALTER TABLE `#__license_info`
  ADD COLUMN hardware_fingerprint CHAR(64) NULL DEFAULT NULL AFTER instance_id,
  ADD COLUMN hwfp_bound_at        TIMESTAMP NULL DEFAULT NULL AFTER hardware_fingerprint,
  ADD COLUMN hwfp_rebind_count    TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER hwfp_bound_at,
  ADD COLUMN hwfp_last_rebind_at  TIMESTAMP NULL DEFAULT NULL AFTER hwfp_rebind_count,
  ADD INDEX idx_hwfp (hardware_fingerprint);

-- Extend validation_status enum to cover hardware rebind + clock drift.
-- MariaDB requires the full enum list on MODIFY.
ALTER TABLE `#__license_info`
  MODIFY validation_status
    ENUM('valid','expired','revoked','invalid','pending','rebinding_required','clock_drift')
    NOT NULL DEFAULT 'pending';

-- system_config slots used by P1.
INSERT INTO `#__system_config` (config_key, config_value, description) VALUES
    ('server_hwfp', '', 'Cached server-side hardware fingerprint (JSON, components + composite hash)')
ON DUPLICATE KEY UPDATE config_key = config_key;

INSERT INTO `#__system_config` (config_key, config_value, description) VALUES
    ('license_prev_tier', '', 'Last-known good tier; used during 7-day rebinding_required grace')
ON DUPLICATE KEY UPDATE config_key = config_key;
