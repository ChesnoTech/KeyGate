# Local USB Device Detection Script
# Run this on the admin PC to detect USB devices and output JSON

# Get USB drives (Get-CimInstance for Win11 25H2 compatibility)
$usbDevices = Get-CimInstance Win32_DiskDrive | Where-Object { $_.InterfaceType -eq 'USB' }

$devices = @()

foreach ($disk in $usbDevices) {
    # Get partition and volume information via CIM associations
    $partitions = Get-CimAssociatedInstance -InputObject $disk -ResultClassName Win32_DiskPartition -ErrorAction SilentlyContinue

    $driveLetter = ""
    $volumeName = ""

    foreach ($partition in $partitions) {
        $volumes = Get-CimAssociatedInstance -InputObject $partition -ResultClassName Win32_LogicalDisk -ErrorAction SilentlyContinue
        if ($volumes) {
            $driveLetter = $volumes.DeviceID
            $volumeName = $volumes.VolumeName
            break
        }
    }

    # Extract manufacturer from caption
    $manufacturer = $disk.Caption -replace $disk.Model, '' -replace 'USB Device', '' -replace '\s+', ' '
    $manufacturer = $manufacturer.Trim()
    if ([string]::IsNullOrWhiteSpace($manufacturer) -or $manufacturer -eq 'USB Device') {
        $modelParts = $disk.Model.Split(' ')
        $manufacturer = $modelParts[0]
    }

    # Create device object
    $device = @{
        serial_number = $disk.SerialNumber.Trim()
        model = $disk.Model.Trim()
        manufacturer = $manufacturer
        capacity_gb = [Math]::Round($disk.Size / 1GB, 2)
        drive_letter = $driveLetter
        volume_name = $volumeName
        status = $disk.Status
        suggested_name = if ($volumeName) { $volumeName } else { $disk.Model }
    }

    $devices += $device
}

# Output as JSON
$output = @{
    success = $true
    devices = $devices
    count = $devices.Count
    method = "local-powershell"
    timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
}

$output | ConvertTo-Json -Depth 10
