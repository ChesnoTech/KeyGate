-- Seed product variants and partition templates
-- Disk sizes: 256 GB (~238 GB = 243712 MB), 512 GB (~474 GB = 485376 MB), 1 TB (~931 GB = 953344 MB), 2 TB (~1862 GB = 1907200 MB)

-- Get product line IDs
SET @pl1 = (SELECT id FROM product_lines WHERE order_pattern = 'ЭЛ00-######');
SET @pl2 = (SELECT id FROM product_lines WHERE order_pattern = 'ЛЕ00-######');
SET @pl3 = (SELECT id FROM product_lines WHERE order_pattern = 'ИП00-######');

-- ── Variants for Marketplace 1 ──────────────────────────────────
INSERT IGNORE INTO product_variants (line_id, name, disk_size_min_mb, disk_size_max_mb) VALUES
    (@pl1, '256 GB', 230000, 260000),
    (@pl1, '512 GB', 460000, 510000),
    (@pl1, '1 TB',   900000, 1000000),
    (@pl1, '2 TB',  1800000, 2000000);

-- ── Variants for Marketplace 2 ──────────────────────────────────
INSERT IGNORE INTO product_variants (line_id, name, disk_size_min_mb, disk_size_max_mb) VALUES
    (@pl2, '256 GB', 230000, 260000),
    (@pl2, '512 GB', 460000, 510000),
    (@pl2, '1 TB',   900000, 1000000),
    (@pl2, '2 TB',  1800000, 2000000);

-- ── Variants for In person ──────────────────────────────────────
INSERT IGNORE INTO product_variants (line_id, name, disk_size_min_mb, disk_size_max_mb) VALUES
    (@pl3, '256 GB', 230000, 260000),
    (@pl3, '512 GB', 460000, 510000),
    (@pl3, '1 TB',   900000, 1000000),
    (@pl3, '2 TB',  1800000, 2000000);

-- ══════════════════════════════════════════════════════════════════
-- Partition templates for each variant
-- Layout: EFI(260) + MSR(16) + OS(C:) + Recovery(1500) + Data(D:,flexible) + BIOS(E:,200,FAT32)
-- ══════════════════════════════════════════════════════════════════

-- Helper: insert partitions for a given variant
-- 256 GB ~ 243712 MB total: EFI 260 + MSR 16 + OS 120000 + Recovery 1500 + Data 121736 + BIOS 200
-- 512 GB ~ 485376 MB total: EFI 260 + MSR 16 + OS 200000 + Recovery 1500 + Data 283400 + BIOS 200
-- 1 TB  ~ 953344 MB total:  EFI 260 + MSR 16 + OS 400000 + Recovery 1500 + Data 551368 + BIOS 200
-- 2 TB  ~ 1907200 MB total: EFI 260 + MSR 16 + OS 500000 + Recovery 1500 + Data 1405224 + BIOS 200

-- ── 256 GB variants (all 3 product lines) ────────────────────────
INSERT INTO product_variant_partitions (variant_id, partition_order, partition_name, partition_type, expected_size_mb, tolerance_percent, is_flexible)
SELECT v.id, p.partition_order, p.partition_name, p.partition_type, p.expected_size_mb, p.tolerance_percent, p.is_flexible
FROM product_variants v
CROSS JOIN (
    SELECT 1 AS partition_order, 'EFI'            AS partition_name, 'EFI System'           AS partition_type, 260    AS expected_size_mb, 1.00 AS tolerance_percent, 0 AS is_flexible UNION ALL
    SELECT 2,                    'MSR',                               'Microsoft Reserved',                    16,                      1.00,                     0              UNION ALL
    SELECT 3,                    'OS',                                'NTFS',                                  120000,                  1.00,                     0              UNION ALL
    SELECT 4,                    'Recovery Image',                    'NTFS',                                  1500,                    1.00,                     0              UNION ALL
    SELECT 5,                    'Data',                              'NTFS',                                  121736,                  1.00,                     1              UNION ALL
    SELECT 6,                    'BIOS',                              'FAT32',                                 200,                     1.00,                     0
) p
WHERE v.name = '256 GB'
ON DUPLICATE KEY UPDATE expected_size_mb = VALUES(expected_size_mb), partition_type = VALUES(partition_type), is_flexible = VALUES(is_flexible);

-- ── 512 GB variants (all 3 product lines) ────────────────────────
INSERT INTO product_variant_partitions (variant_id, partition_order, partition_name, partition_type, expected_size_mb, tolerance_percent, is_flexible)
SELECT v.id, p.partition_order, p.partition_name, p.partition_type, p.expected_size_mb, p.tolerance_percent, p.is_flexible
FROM product_variants v
CROSS JOIN (
    SELECT 1 AS partition_order, 'EFI'            AS partition_name, 'EFI System'           AS partition_type, 260    AS expected_size_mb, 1.00 AS tolerance_percent, 0 AS is_flexible UNION ALL
    SELECT 2,                    'MSR',                               'Microsoft Reserved',                    16,                      1.00,                     0              UNION ALL
    SELECT 3,                    'OS',                                'NTFS',                                  200000,                  1.00,                     0              UNION ALL
    SELECT 4,                    'Recovery Image',                    'NTFS',                                  1500,                    1.00,                     0              UNION ALL
    SELECT 5,                    'Data',                              'NTFS',                                  283400,                  1.00,                     1              UNION ALL
    SELECT 6,                    'BIOS',                              'FAT32',                                 200,                     1.00,                     0
) p
WHERE v.name = '512 GB'
ON DUPLICATE KEY UPDATE expected_size_mb = VALUES(expected_size_mb), partition_type = VALUES(partition_type), is_flexible = VALUES(is_flexible);

-- ── 1 TB variants (all 3 product lines) ──────────────────────────
INSERT INTO product_variant_partitions (variant_id, partition_order, partition_name, partition_type, expected_size_mb, tolerance_percent, is_flexible)
SELECT v.id, p.partition_order, p.partition_name, p.partition_type, p.expected_size_mb, p.tolerance_percent, p.is_flexible
FROM product_variants v
CROSS JOIN (
    SELECT 1 AS partition_order, 'EFI'            AS partition_name, 'EFI System'           AS partition_type, 260    AS expected_size_mb, 1.00 AS tolerance_percent, 0 AS is_flexible UNION ALL
    SELECT 2,                    'MSR',                               'Microsoft Reserved',                    16,                      1.00,                     0              UNION ALL
    SELECT 3,                    'OS',                                'NTFS',                                  400000,                  1.00,                     0              UNION ALL
    SELECT 4,                    'Recovery Image',                    'NTFS',                                  1500,                    1.00,                     0              UNION ALL
    SELECT 5,                    'Data',                              'NTFS',                                  551368,                  1.00,                     1              UNION ALL
    SELECT 6,                    'BIOS',                              'FAT32',                                 200,                     1.00,                     0
) p
WHERE v.name = '1 TB'
ON DUPLICATE KEY UPDATE expected_size_mb = VALUES(expected_size_mb), partition_type = VALUES(partition_type), is_flexible = VALUES(is_flexible);

-- ── 2 TB variants (all 3 product lines) ──────────────────────────
INSERT INTO product_variant_partitions (variant_id, partition_order, partition_name, partition_type, expected_size_mb, tolerance_percent, is_flexible)
SELECT v.id, p.partition_order, p.partition_name, p.partition_type, p.expected_size_mb, p.tolerance_percent, p.is_flexible
FROM product_variants v
CROSS JOIN (
    SELECT 1 AS partition_order, 'EFI'            AS partition_name, 'EFI System'           AS partition_type, 260    AS expected_size_mb, 1.00 AS tolerance_percent, 0 AS is_flexible UNION ALL
    SELECT 2,                    'MSR',                               'Microsoft Reserved',                    16,                      1.00,                     0              UNION ALL
    SELECT 3,                    'OS',                                'NTFS',                                  500000,                  1.00,                     0              UNION ALL
    SELECT 4,                    'Recovery Image',                    'NTFS',                                  1500,                    1.00,                     0              UNION ALL
    SELECT 5,                    'Data',                              'NTFS',                                  1405224,                 1.00,                     1              UNION ALL
    SELECT 6,                    'BIOS',                              'FAT32',                                 200,                     1.00,                     0
) p
WHERE v.name = '2 TB'
ON DUPLICATE KEY UPDATE expected_size_mb = VALUES(expected_size_mb), partition_type = VALUES(partition_type), is_flexible = VALUES(is_flexible);
