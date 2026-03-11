> **Note:** References to replacing `main_v2.PS1` are historical — v2 has been retired.
> Current activation script: `activation/main_v3.PS1`. Client launcher: `client/OEM_Activator.cmd`.

# Hardware Collection System v3.0 - Implementation Summary

## Overview

The OEM Activation System has been upgraded to **v3.0** with a completely redesigned hardware collection architecture. The system now collects comprehensive hardware information **immediately after technician login**, regardless of whether activation succeeds, fails, or if Windows was already activated.

---

## 🎯 Key Requirements Implemented

### 1. ✅ Hardware Collection Timing
- **BEFORE:** Hardware was collected only after successful activation
- **AFTER:** Hardware collection happens immediately after technician login and order number entry
- **Works for:** Success, failure, already-activated, and all other scenarios

### 2. ✅ Optional Fields
- **OEM ID** and **Roll Serial** are now optional (NULL allowed in database)
- Displays as "(Not provided)" in admin panel when values are missing

### 3. ✅ Order Number Visibility
- Order number is now prominently displayed in the Keys table
- Shows "Not used" for keys that haven't been allocated yet
- Directly links to hardware information

### 4. ✅ Complete Disk Layout Collection
- **Enhanced partition collection** shows ALL partitions including:
  - ✅ EFI System Partitions
  - ✅ Microsoft Reserved Partitions (MSR)
  - ✅ Recovery Partitions
  - ✅ System Reserved (MBR style)
  - ✅ OEM Partitions
  - ✅ Hidden/System partitions
  - ✅ Windows OS partitions
  - ✅ Data partitions
- Displays like professional partition management software
- Shows partition purpose, bootable status, primary/logical type, starting offset

### 5. ✅ Comprehensive Hardware Information Collected
- **Motherboard:** Manufacturer, Model/Product, Serial Number, Version
- **BIOS:** Manufacturer, Version, Release Date, Serial Number
- **CPU:** Name, Manufacturer, Core Count, Logical Processors, Max Clock Speed
- **RAM:** Total Capacity, Slots Used/Total, Per-Module Details (manufacturer, capacity, speed, part number, serial)
- **Video Cards:** Name, Driver Version, Video Processor, VRAM, Resolution (supports multiple cards)
- **Storage:** Model, Interface Type, Size, Serial Number, Media Type (supports multiple drives)
- **Disk Layout:** Complete multi-disk partition mapping with partition purposes
- **Operating System:** Name, Version, Architecture
- **Secure Boot Status:** Enabled/Disabled/Unknown (handles legacy BIOS gracefully)
- **Computer Name:** NetBIOS name of the PC

---

## 📁 Files Created/Modified

### New Files

1. **`activation/main_v3.PS1`** - PowerShell Client v3.0
   - Hardware collection integrated at login time
   - Enhanced disk layout collection function
   - Uses new `collect-hardware-v2.php` API endpoint
   - Handles already-activated Windows scenario

2. **`activation/Collect-DiskLayout.ps1`** - Standalone disk layout function
   - Can be imported separately for testing
   - Shows complete partition table including hidden partitions

3. **`api/collect-hardware-v2.php`** - New API Endpoint
   - Accepts hardware data at login time
   - Stores with order number (not tied to activation attempt)
   - Prevents duplicates (checks if order already has hardware)
   - Links technician via session token

4. **`database/hardware_collection_v2_migration.sql`** - Database Migration
   - Makes `oem_identifier` and `roll_serial` nullable
   - Adds `complete_disk_layout` JSON column
   - Adds `technician_id` and `session_token` to `hardware_info`
   - Creates `hardware_collection_log` table for tracking attempts
   - Creates `v_order_hardware` view for easy queries

5. **`database/hardware_collection_v2_fix.sql`** - Fix Script
   - Applied missing migration steps
   - Fixed collation issues

### Modified Files

6. **`admin_v2.php`** - Admin Panel Updates
   - Added `get_hardware_by_order` action (line ~358)
   - Added **Hardware** column to Keys table (line ~1680)
   - Added `viewHardwareByOrder()` JavaScript function (line ~2156)
   - Enhanced `renderHardwareInfo()` to display complete disk layout with professional table view (line ~2270)
   - Hardware info now accessible from Keys tab via order number

---

## 🗄️ Database Schema Changes

### Table: `oem_keys`
```sql
-- Made optional fields
oem_identifier VARCHAR(20) NULL DEFAULT NULL
roll_serial VARCHAR(20) NULL DEFAULT NULL
```

### Table: `hardware_info`
```sql
-- New columns added
technician_id VARCHAR(10) NULL
session_token VARCHAR(64) NULL
complete_disk_layout JSON NULL  -- Full disk and partition mapping
collection_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP

-- Modified columns
activation_id INT NULL  -- Now optional (was required before)
```

### New Table: `hardware_collection_log`
```sql
CREATE TABLE hardware_collection_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(10) NOT NULL,
    technician_id VARCHAR(10) NOT NULL,
    session_token VARCHAR(64) NOT NULL,
    hardware_info_id INT NULL,
    collection_status ENUM('success', 'failed', 'partial'),
    collection_timestamp TIMESTAMP,
    error_message TEXT NULL
);
```

### New View: `v_order_hardware`
```sql
-- Replaces v_activation_hardware
-- Shows hardware info regardless of activation status
-- LEFT JOINs activation_attempts (not INNER JOIN)
SELECT hardware_id, order_number, technician_name, collection_timestamp,
       activation_result, product_key, motherboard details, CPU, RAM, etc.
FROM hardware_info h
LEFT JOIN technicians t ...
LEFT JOIN activation_attempts a ...
LEFT JOIN oem_keys k ...
```

---

## 🔄 New Workflow

### Previous v2.0 Workflow:
```
1. Technician login
2. Enter order number
3. Get activation key
4. Attempt activation
5. IF SUCCESS → Collect hardware → Submit to server
6. IF FAIL → No hardware collected
```

### New v3.0 Workflow:
```
1. Technician login
2. Enter order number
3. 🆕 COLLECT HARDWARE IMMEDIATELY (regardless of anything)
4. 🆕 Submit hardware to server (collect-hardware-v2.php)
5. Check if Windows already activated
   - IF YES → Exit (hardware already submitted)
6. Get activation key
7. Attempt activation
8. Report result (success/fail)
9. Continue activation loop if needed
```

---

## 🎨 Admin Panel Features

### Keys Tab - New Hardware Column
- Shows "📋 View" button for every key with an order number
- Shows "No order" for unused keys
- Clicking "View" opens hardware modal with complete specs
- Works even if activation failed or was already activated

### Hardware Modal Display
Shows comprehensive information in organized sections:

1. **Activation Details**
   - Order Number
   - Technician Name & ID
   - Product Key (if activation occurred)
   - Activation Timestamp (if applicable)
   - Computer Name

2. **System Information**
   - Operating System
   - OS Version & Architecture
   - Secure Boot Status (with visual indicators)

3. **Motherboard** (manufacturer, model, version, serial)

4. **BIOS** (manufacturer, version, release date, serial)

5. **CPU** (name, manufacturer, cores/threads, max speed)

6. **Memory (RAM)**
   - Total capacity and slot usage
   - Per-module breakdown (manufacturer, capacity, speed, part number)

7. **Video Cards** (all cards with VRAM, resolution, driver version)

8. **Storage Devices** (all drives with model, size, interface, type)

9. **Complete Disk Layout** 🆕
   - Multi-disk support
   - Professional table showing:
     - Partition number
     - Purpose (EFI, Recovery, Windows OS, Data, etc.)
     - Drive letter (if mounted)
     - Size
     - File system
     - Free/Used space
     - Flags (Bootable, Primary)
   - Partition style (GPT/MBR) per disk
   - Disk serial numbers

10. **Collection Metadata** (timestamp, collection method)

---

## 📊 Complete Disk Layout Example

```
💽 Complete Disk Layout

┌─ Disk 0 - Samsung SSD 970 EVO Plus 500GB ─────────────────┐
│ Total Size: 476.94 GB                                       │
│ Interface: SCSI                                             │
│ Partition Style: GPT                                        │
│ Serial Number: S4EWNF0R123456A                             │
│                                                             │
│ Partitions:                                                 │
│ ┌───┬──────────────────────┬───────┬────────┬──────────┐  │
│ │ # │ Purpose              │ Drive │ Size   │ FS       │  │
│ ├───┼──────────────────────┼───────┼────────┼──────────┤  │
│ │ 0 │ EFI System Partition │   -   │ 0.10GB │ FAT32    │  │
│ │ 1 │ Microsoft Reserved   │   -   │ 0.13GB │ N/A      │  │
│ │ 2 │ Windows OS           │  C:   │400.00GB│ NTFS     │  │
│ │ 3 │ Recovery Partition   │   -   │ 0.50GB │ NTFS     │  │
│ └───┴──────────────────────┴───────┴────────┴──────────┘  │
└─────────────────────────────────────────────────────────────┘
```

---

## 🔌 API Endpoints

### New Endpoint: `collect-hardware-v2.php`
**Purpose:** Collect hardware at login time (not tied to activation)

**Input:**
```json
{
  "session_token": "abc123...",
  "order_number": "ORD01",
  "motherboard_manufacturer": "ASUSTeK COMPUTER INC.",
  "motherboard_product": "PRIME B450M-A",
  ... (all 30+ hardware fields)
  "complete_disk_layout": "[{disk_number: 0, partitions: [...]}]"
}
```

**Output (Success):**
```json
{
  "success": true,
  "message": "Hardware information collected successfully",
  "hardware_id": 123,
  "technician": "John Doe"
}
```

**Output (Duplicate):**
```json
{
  "success": true,
  "message": "Hardware information already collected for this order",
  "duplicate": true,
  "hardware_id": 123,
  "collected_ago_seconds": 145
}
```

### Updated Endpoint: `admin_v2.php?action=get_hardware_by_order`
**Purpose:** Retrieve hardware info by order number

**Input:** `?action=get_hardware_by_order&order_number=ORD01`

**Output:**
```json
{
  "success": true,
  "hardware": {
    "order_number": "ORD01",
    "technician_name": "John Doe",
    "activation_result": "success",  // or null if not activated
    "motherboard_manufacturer": "...",
    ... (all hardware fields)
  }
}
```

---

## 🧪 Testing Scenarios

### Scenario 1: Normal Activation (Success)
1. Technician logs in
2. Enters order number "ABC12"
3. **Hardware collected immediately**
4. Windows not activated → Proceeds with activation
5. Activation succeeds
6. Admin panel → Keys tab → Shows "ABC12" with "📋 View" button
7. Clicking View shows complete hardware specs

### Scenario 2: Activation Failure
1. Technician logs in
2. Enters order number "DEF34"
3. **Hardware collected immediately**
4. Activation attempts fail (bad key)
5. Session ends with failure
6. Admin panel → Keys tab → Shows "DEF34" with "📋 View" button
7. **Hardware info still viewable** despite activation failure

### Scenario 3: Already Activated Windows
1. Technician logs in
2. Enters order number "GHI56"
3. **Hardware collected immediately**
4. System detects Windows already activated
5. **Script exits early (no activation needed)**
6. Admin panel → Shows "GHI56" with hardware data
7. **Hardware collected even though no activation occurred**

### Scenario 4: Multiple Disks with Complex Partitioning
1. PC has 2 drives: 500GB SSD (GPT) + 2TB HDD (MBR)
2. SSD has EFI + MSR + Windows + Recovery
3. HDD has multiple data partitions
4. **Complete layout collected** showing all partitions on both disks
5. Admin panel displays professional partition table with:
   - Disk 0 (SSD): EFI, MSR, Windows OS, Recovery
   - Disk 1 (HDD): Data Partition 1, Data Partition 2, Data Partition 3
6. Shows partition purposes, sizes, bootable flags, etc.

---

## 📋 Migration Instructions

### For Existing Installations

1. **Backup Current System**
   ```bash
   docker exec oem-activation-db mysqldump -uroot -proot_password_123 oem_activation > backup_before_v3.sql
   ```

2. **Apply Database Migration**
   ```bash
   cat database/hardware_collection_v2_fix.sql | docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation
   ```

3. **Deploy New API Endpoint**
   ```bash
   docker cp api/collect-hardware-v2.php oem-activation-web:/var/www/html/activate/api/
   ```

4. **Deploy Updated Admin Panel**
   ```bash
   docker cp admin_v2.php oem-activation-web:/var/www/html/activate/
   ```

5. **Deploy New PowerShell Client**
   - Replace `activation/main_v2.PS1` with `activation/main_v3.PS1`
   - Update CMD launcher to call `main_v3.PS1`
   - Test on a development PC first

6. **Verify Installation**
   ```bash
   # Check database structure
   docker exec oem-activation-db mariadb -e "DESCRIBE hardware_info;" oem_activation

   # Check if view exists
   docker exec oem-activation-db mariadb -e "SHOW CREATE VIEW v_order_hardware;" oem_activation

   # Check if API endpoint exists
   docker exec oem-activation-web ls -la /var/www/html/activate/api/collect-hardware-v2.php
   ```

---

## 🔍 Troubleshooting

### Issue: Hardware not showing in admin panel
**Check:**
1. Is `complete_disk_layout` column present?
   ```sql
   SHOW COLUMNS FROM hardware_info LIKE 'complete_disk_layout';
   ```
2. Is `v_order_hardware` view created?
   ```sql
   SELECT * FROM v_order_hardware LIMIT 1;
   ```

### Issue: Partition layout shows "Basic View" instead of complete layout
**Reason:** PowerShell client v2 was used (not v3)
**Solution:** Ensure technicians are using `main_v3.PS1`

### Issue: Hardware collection fails silently
**Check:** `hardware_collection_log` table
```sql
SELECT * FROM hardware_collection_log WHERE collection_status = 'failed' ORDER BY collection_timestamp DESC LIMIT 10;
```

---

## 📈 Benefits of v3.0

1. **✅ Complete Data Collection** - Hardware info collected regardless of activation outcome
2. **✅ Better Diagnostics** - Can see hardware specs even when activation fails (helps troubleshoot)
3. **✅ Accurate Inventory** - Every technician login creates a hardware record
4. **✅ Professional Partition View** - Disk layout shown like partition management tools
5. **✅ Flexible Schema** - Optional OEM ID/Roll Serial fields handle real-world variability
6. **✅ Enhanced Visibility** - Hardware accessible directly from Keys table via order number
7. **✅ Audit Trail** - `hardware_collection_log` tracks all collection attempts
8. **✅ Future-Proof** - Decoupled architecture supports hardware-only workflows

---

## 🚀 Future Enhancements

Potential additions for v4.0:
- **Hardware change detection:** Compare hardware between multiple collections for same order
- **Bulk hardware export:** Export all hardware data to Excel for inventory purposes
- **Hardware search:** Find PCs by CPU model, RAM size, motherboard, etc.
- **Disk health monitoring:** Integrate SMART data collection
- **Network adapter info:** MAC addresses, IP addresses, network configuration
- **USB device enumeration:** List all connected USB devices
- **Software inventory:** Installed programs, Windows updates, drivers

---

## 📝 Summary

The OEM Activation System v3.0 represents a **fundamental architectural shift** in hardware collection. By decoupling hardware collection from activation results and moving it to login time, the system now provides:

- **100% hardware collection rate** (regardless of activation success/failure)
- **Complete disk topology** including all hidden/system partitions
- **Professional-grade partition management views** in the admin panel
- **Flexible data model** supporting real-world scenarios where OEM IDs may not be available
- **Direct hardware access** from the Keys table via order numbers

All changes are **backward compatible** - old activation records without hardware data continue to work, and the system gracefully handles both v2 (legacy partitions) and v3 (complete disk layout) data formats.

---

**Document Version:** 1.0
**Last Updated:** 2026-01-29
**Author:** OEM Activation System Development Team
**System Version:** v3.0 (Hardware Collection Edition)
