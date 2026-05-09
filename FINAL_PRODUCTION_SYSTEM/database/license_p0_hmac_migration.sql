-- =============================================================
-- KeyGate v2.3.0 P0 — License row integrity HMAC
-- =============================================================
-- Adds an HMAC column to license_info so any row directly INSERTed/UPDATEd
-- without going through registerLicense() (which knows the per-instance
-- row secret) is detected as tampered and forced back to community tier.
--
-- The HMAC formula and verification live in functions/license-helpers.php
-- (computeLicenseRowHmac / verifyLicenseRow).
-- =============================================================

ALTER TABLE `#__license_info`
  ADD COLUMN integrity_hmac CHAR(64) NOT NULL DEFAULT '' AFTER validation_status;

-- Existing rows (legacy paid customers from v2.2.x) have an empty hmac
-- → verifyLicenseRow returns false → they fall back to community on
-- next read. Customers re-register via /api/migrate to get a fresh
-- RS256 JWT, and that path computes a valid HMAC on insert.
