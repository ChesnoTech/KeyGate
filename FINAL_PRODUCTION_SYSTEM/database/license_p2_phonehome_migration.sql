-- =============================================================
-- KeyGate v2.3.0 P2 — Phone-home + grace + revocation + clock-drift
-- =============================================================
-- Phone-home turns the Cloudflare Worker into the authoritative tier
-- source. Without it, a JWT registered once was good forever — pirates
-- could buy one license, export the JWT, and seed unlimited installs.
--
-- Phone-home cadence: once per 24h on PHP boot OR daily cron, whichever
-- fires first. Non-blocking; cached tier serves until next successful
-- validate. Grace: 0–14d OK, 14–30d banner, >30d community fallback.
--
-- Revocation: each issued JWT carries a `jti` claim. Worker maintains
-- a KV set `revoked:{jti}`. Validate response carries revoked:true →
-- PHP forces community immediately, regardless of grace window.
--
-- Clock drift: Worker returns server_time; PHP records server vs local
-- delta. Three consecutive checks with >5min drift → 'clock_drift'.
-- Defeats pirates rolling system clock back to dodge expires_at.
-- =============================================================

ALTER TABLE `#__license_info`
  ADD COLUMN validation_failure_count INT UNSIGNED NOT NULL DEFAULT 0
    AFTER last_validated_at,
  ADD COLUMN last_validation_error    TEXT NULL DEFAULT NULL
    AFTER validation_failure_count,
  ADD COLUMN server_time_drift_seconds INT NOT NULL DEFAULT 0
    AFTER last_validation_error,
  ADD COLUMN clock_drift_strikes      TINYINT UNSIGNED NOT NULL DEFAULT 0
    AFTER server_time_drift_seconds,
  ADD COLUMN current_jti              CHAR(36) NULL DEFAULT NULL
    AFTER clock_drift_strikes;

-- system_config slots used by P2.
-- license_validation_cache: JSON of last validate response + HMAC anchor
-- so the cache itself can't be forged via direct UPDATE.
INSERT INTO `#__system_config` (config_key, config_value, description) VALUES
    ('license_validation_cache', '', 'Last /api/validate response (JSON, HMAC-anchored to license_row_secret)')
ON DUPLICATE KEY UPDATE config_key = config_key;

INSERT INTO `#__system_config` (config_key, config_value, description) VALUES
    ('license_phonehome_interval', '86400', 'Seconds between phone-home validate calls (default 24h)')
ON DUPLICATE KEY UPDATE config_key = config_key;
