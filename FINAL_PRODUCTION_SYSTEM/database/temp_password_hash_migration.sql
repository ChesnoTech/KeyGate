-- Migration: Hash temp_password values with bcrypt
-- This migration widens the temp_password column to store bcrypt hashes
-- and must be followed by running the PHP migration script below.

-- Step 1: Widen column to hold bcrypt hashes (60 chars)
ALTER TABLE technicians MODIFY COLUMN temp_password VARCHAR(255) DEFAULT NULL;

-- Step 2: The existing plaintext temp passwords must be hashed via PHP
-- because SQL cannot generate bcrypt hashes natively.
-- Run this after the ALTER:
--   docker exec oem-activation-web php /var/www/html/activate/database/hash_temp_passwords.php
