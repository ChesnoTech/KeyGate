-- Seed demo compliance data with proper order numbers and partition layout checks
-- Order patterns: ЭЛ00-###### (Marketplace 1), ЛЕ00-###### (Marketplace 2), ИП00-###### (In person)
-- Partition sizes use precise GB values that convert to exact MB specs:
--   EFI 260 MB = 0.25390625 GB, MSR 16 MB = 0.015625 GB
--   Recovery 1500 MB = 1.46484375 GB, BIOS 200 MB = 0.1953125 GB
--   512GB: OS 200000 MB = 195.3125 GB, Data 283400 MB = 276.7578125 GB
--   1TB:   OS 400000 MB = 390.625 GB,  Data 551368 MB = 538.4453125 GB
--   256GB: OS 120000 MB = 117.1875 GB, Data 121736 MB = 118.8828125 GB

-- 1. Delete old compliance results to start fresh
DELETE FROM `#__qc_compliance_results`;

-- 2. Update existing hardware_info order numbers to follow product line patterns
-- Also inject complete_disk_layout JSON for partition checking

-- PASS: Good 512 GB layout (ЭЛ00-100001)
UPDATE `#__hardware_info` SET order_number = 'ЭЛ00-100001',
  complete_disk_layout = '[{"disk_number":0,"disk_model":"Samsung SSD 970 EVO 500GB","disk_size_gb":465.76,"partition_style":"GPT","partitions":[{"partition_number":0,"size_gb":0.25390625,"partition_purpose":"EFI","file_system":"FAT32"},{"partition_number":1,"size_gb":0.015625,"partition_purpose":"MSR","file_system":null},{"partition_number":2,"size_gb":195.3125,"partition_purpose":"OS","file_system":"NTFS","drive_letter":"C:"},{"partition_number":3,"size_gb":1.46484375,"partition_purpose":"Recovery","file_system":"NTFS"},{"partition_number":4,"size_gb":276.7578125,"partition_purpose":"Data","file_system":"NTFS","drive_letter":"D:"},{"partition_number":5,"size_gb":0.1953125,"partition_purpose":"Other","file_system":"FAT32","volume_name":"BIOS","drive_letter":"E:"}]}]'
WHERE id = (SELECT id FROM (SELECT MIN(id) AS id FROM `#__hardware_info`) t);

-- PASS: Good 512 GB layout (ЭЛ00-100002)
UPDATE `#__hardware_info` SET order_number = 'ЭЛ00-100002',
  complete_disk_layout = '[{"disk_number":0,"disk_model":"WD Blue SN570 500GB","disk_size_gb":465.76,"partition_style":"GPT","partitions":[{"partition_number":0,"size_gb":0.25390625,"partition_purpose":"EFI","file_system":"FAT32"},{"partition_number":1,"size_gb":0.015625,"partition_purpose":"MSR","file_system":null},{"partition_number":2,"size_gb":195.3125,"partition_purpose":"OS","file_system":"NTFS","drive_letter":"C:"},{"partition_number":3,"size_gb":1.46484375,"partition_purpose":"Recovery","file_system":"NTFS"},{"partition_number":4,"size_gb":276.7578125,"partition_purpose":"Data","file_system":"NTFS","drive_letter":"D:"},{"partition_number":5,"size_gb":0.1953125,"partition_purpose":"Other","file_system":"FAT32","volume_name":"BIOS","drive_letter":"E:"}]}]'
WHERE id = (SELECT id FROM (SELECT id FROM `#__hardware_info` ORDER BY id LIMIT 1 OFFSET 1) t);

-- PASS: Good 1 TB layout (ЛЕ00-200001)
UPDATE `#__hardware_info` SET order_number = 'ЛЕ00-200001',
  complete_disk_layout = '[{"disk_number":0,"disk_model":"Kingston A2000 1TB","disk_size_gb":931.51,"partition_style":"GPT","partitions":[{"partition_number":0,"size_gb":0.25390625,"partition_purpose":"EFI","file_system":"FAT32"},{"partition_number":1,"size_gb":0.015625,"partition_purpose":"MSR","file_system":null},{"partition_number":2,"size_gb":390.625,"partition_purpose":"OS","file_system":"NTFS","drive_letter":"C:"},{"partition_number":3,"size_gb":1.46484375,"partition_purpose":"Recovery","file_system":"NTFS"},{"partition_number":4,"size_gb":540.0,"partition_purpose":"Data","file_system":"NTFS","drive_letter":"D:"},{"partition_number":5,"size_gb":0.1953125,"partition_purpose":"Other","file_system":"FAT32","volume_name":"BIOS","drive_letter":"E:"}]}]'
WHERE id = (SELECT id FROM (SELECT id FROM `#__hardware_info` ORDER BY id LIMIT 1 OFFSET 2) t);

-- WARNING: 256 GB layout with Data partition slightly too small (ЛЕ00-200002)
-- Data is 113.77 GB = 116500 MB but template requires 121736 MB minimum
UPDATE `#__hardware_info` SET order_number = 'ЛЕ00-200002',
  complete_disk_layout = '[{"disk_number":0,"disk_model":"Samsung SSD 860 EVO 250GB","disk_size_gb":232.89,"partition_style":"GPT","partitions":[{"partition_number":0,"size_gb":0.25390625,"partition_purpose":"EFI","file_system":"FAT32"},{"partition_number":1,"size_gb":0.015625,"partition_purpose":"MSR","file_system":null},{"partition_number":2,"size_gb":117.1875,"partition_purpose":"OS","file_system":"NTFS","drive_letter":"C:"},{"partition_number":3,"size_gb":1.46484375,"partition_purpose":"Recovery","file_system":"NTFS"},{"partition_number":4,"size_gb":113.77,"partition_purpose":"Data","file_system":"NTFS","drive_letter":"D:"},{"partition_number":5,"size_gb":0.1953125,"partition_purpose":"Other","file_system":"FAT32","volume_name":"BIOS","drive_letter":"E:"}]}]'
WHERE id = (SELECT id FROM (SELECT id FROM `#__hardware_info` ORDER BY id LIMIT 1 OFFSET 3) t);

-- PASS: Good 1 TB layout (ИП00-300001)
UPDATE `#__hardware_info` SET order_number = 'ИП00-300001',
  complete_disk_layout = '[{"disk_number":0,"disk_model":"Crucial P3 1TB","disk_size_gb":931.51,"partition_style":"GPT","partitions":[{"partition_number":0,"size_gb":0.25390625,"partition_purpose":"EFI","file_system":"FAT32"},{"partition_number":1,"size_gb":0.015625,"partition_purpose":"MSR","file_system":null},{"partition_number":2,"size_gb":390.625,"partition_purpose":"OS","file_system":"NTFS","drive_letter":"C:"},{"partition_number":3,"size_gb":1.46484375,"partition_purpose":"Recovery","file_system":"NTFS"},{"partition_number":4,"size_gb":540.0,"partition_purpose":"Data","file_system":"NTFS","drive_letter":"D:"},{"partition_number":5,"size_gb":0.1953125,"partition_purpose":"Other","file_system":"FAT32","volume_name":"BIOS","drive_letter":"E:"}]}]'
WHERE id = (SELECT id FROM (SELECT id FROM `#__hardware_info` ORDER BY id LIMIT 1 OFFSET 4) t);

-- FAIL: Bad 512 GB layout — wrong OS size (150 GB instead of ~195 GB)
UPDATE `#__hardware_info` SET order_number = 'ИП00-300002',
  complete_disk_layout = '[{"disk_number":0,"disk_model":"WD Blue SN550 500GB","disk_size_gb":465.76,"partition_style":"GPT","partitions":[{"partition_number":0,"size_gb":0.25390625,"partition_purpose":"EFI","file_system":"FAT32"},{"partition_number":1,"size_gb":0.015625,"partition_purpose":"MSR","file_system":null},{"partition_number":2,"size_gb":146.484375,"partition_purpose":"OS","file_system":"NTFS","drive_letter":"C:"},{"partition_number":3,"size_gb":1.46484375,"partition_purpose":"Recovery","file_system":"NTFS"},{"partition_number":4,"size_gb":317.35,"partition_purpose":"Data","file_system":"NTFS","drive_letter":"D:"},{"partition_number":5,"size_gb":0.1953125,"partition_purpose":"Other","file_system":"FAT32","volume_name":"BIOS","drive_letter":"E:"}]}]'
WHERE id = (SELECT id FROM (SELECT id FROM `#__hardware_info` ORDER BY id LIMIT 1 OFFSET 5) t);

-- No disk layout data (ЭЛ00-100003) — will show "no data" for partition check
UPDATE `#__hardware_info` SET order_number = 'ЭЛ00-100003'
WHERE order_number NOT LIKE 'ЭЛ00-%' AND order_number NOT LIKE 'ЛЕ00-%' AND order_number NOT LIKE 'ИП00-%'
  AND id = (SELECT id FROM (SELECT id FROM `#__hardware_info` WHERE order_number NOT LIKE 'ЭЛ00-%' AND order_number NOT LIKE 'ЛЕ00-%' AND order_number NOT LIKE 'ИП00-%' ORDER BY id LIMIT 1) t);

UPDATE `#__hardware_info` SET order_number = 'ЛЕ00-200003'
WHERE order_number NOT LIKE 'ЭЛ00-%' AND order_number NOT LIKE 'ЛЕ00-%' AND order_number NOT LIKE 'ИП00-%'
  AND id = (SELECT id FROM (SELECT id FROM `#__hardware_info` WHERE order_number NOT LIKE 'ЭЛ00-%' AND order_number NOT LIKE 'ЛЕ00-%' AND order_number NOT LIKE 'ИП00-%' ORDER BY id LIMIT 1) t);

-- Done. Now trigger "Recheck Historical" from the admin UI to regenerate all compliance results.
