function Collect-HardwareInfo {
    <#
    .SYNOPSIS
    Collects comprehensive hardware information from the PC

    .DESCRIPTION
    Gathers detailed hardware specs including motherboard, BIOS, CPU, RAM, video cards,
    storage devices, partitions, Secure Boot status, and OS information

    .OUTPUTS
    Hashtable containing all hardware information
    #>

    Write-Host "`n📋 Collecting hardware information..." -ForegroundColor Cyan

    $hardwareInfo = @{}

    try {
        # === Motherboard Information ===
        Write-Host "  • Motherboard..." -ForegroundColor Gray
        $motherboard = Get-CimInstance Win32_BaseBoard -ErrorAction Stop
        $hardwareInfo['motherboard_manufacturer'] = $motherboard.Manufacturer
        $hardwareInfo['motherboard_product'] = $motherboard.Product
        $hardwareInfo['motherboard_serial'] = $motherboard.SerialNumber
        $hardwareInfo['motherboard_version'] = $motherboard.Version

        # === BIOS Information ===
        Write-Host "  • BIOS..." -ForegroundColor Gray
        $bios = Get-CimInstance Win32_BIOS -ErrorAction Stop
        $hardwareInfo['bios_manufacturer'] = $bios.Manufacturer
        $hardwareInfo['bios_version'] = $bios.SMBIOSBIOSVersion
        $hardwareInfo['bios_release_date'] = $bios.ReleaseDate
        $hardwareInfo['bios_serial_number'] = $bios.SerialNumber

        # === Secure Boot Status ===
        Write-Host "  • Secure Boot..." -ForegroundColor Gray
        try {
            $secureBootEnabled = Confirm-SecureBootUEFI -ErrorAction Stop
            $hardwareInfo['secure_boot_enabled'] = if ($secureBootEnabled) { 1 } else { 0 }
        } catch {
            # Secure Boot not supported or cmdlet not available
            $hardwareInfo['secure_boot_enabled'] = $null
            Write-Host "    ℹ️ Secure Boot status unavailable (legacy BIOS or access denied)" -ForegroundColor DarkGray
        }

        # === CPU Information ===
        Write-Host "  • CPU..." -ForegroundColor Gray
        $cpu = Get-CimInstance Win32_Processor -ErrorAction Stop | Select-Object -First 1
        $hardwareInfo['cpu_name'] = $cpu.Name.Trim()
        $hardwareInfo['cpu_manufacturer'] = $cpu.Manufacturer
        $hardwareInfo['cpu_cores'] = $cpu.NumberOfCores
        $hardwareInfo['cpu_logical_processors'] = $cpu.NumberOfLogicalProcessors
        $hardwareInfo['cpu_max_clock_speed'] = $cpu.MaxClockSpeed  # In MHz

        # === RAM Information ===
        Write-Host "  • RAM..." -ForegroundColor Gray
        $ramModules = Get-CimInstance Win32_PhysicalMemory -ErrorAction Stop
        $totalRamBytes = ($ramModules | Measure-Object -Property Capacity -Sum).Sum
        $hardwareInfo['ram_total_capacity_gb'] = [math]::Round($totalRamBytes / 1GB, 2)
        $hardwareInfo['ram_slots_used'] = $ramModules.Count

        # Get total RAM slots
        try {
            $ramSlots = Get-CimInstance Win32_PhysicalMemoryArray -ErrorAction Stop
            $hardwareInfo['ram_slots_total'] = ($ramSlots | Measure-Object -Property MemoryDevices -Sum).Sum
        } catch {
            $hardwareInfo['ram_slots_total'] = $ramModules.Count
        }

        # RAM modules details as JSON array
        $ramDetails = @()
        foreach ($module in $ramModules) {
            $ramDetails += @{
                manufacturer = $module.Manufacturer
                capacity_gb = [math]::Round($module.Capacity / 1GB, 2)
                speed_mhz = $module.Speed
                part_number = $module.PartNumber
                serial_number = $module.SerialNumber
            }
        }
        $hardwareInfo['ram_modules'] = ($ramDetails | ConvertTo-Json -Compress)

        # === Video Cards Information ===
        Write-Host "  • Video cards..." -ForegroundColor Gray
        $videoCards = Get-CimInstance Win32_VideoController -ErrorAction Stop
        $videoDetails = @()
        foreach ($card in $videoCards) {
            $videoDetails += @{
                name = $card.Name
                driver_version = $card.DriverVersion
                video_processor = $card.VideoProcessor
                adapter_ram_mb = if ($card.AdapterRAM) { [math]::Round($card.AdapterRAM / 1MB, 0) } else { $null }
                resolution = "$($card.CurrentHorizontalResolution)x$($card.CurrentVerticalResolution)"
            }
        }
        $hardwareInfo['video_cards'] = ($videoDetails | ConvertTo-Json -Compress)

        # === Storage Devices Information ===
        Write-Host "  • Storage..." -ForegroundColor Gray
        $disks = Get-CimInstance Win32_DiskDrive -ErrorAction Stop
        $storageDetails = @()
        foreach ($disk in $disks) {
            $storageDetails += @{
                model = $disk.Model
                interface_type = $disk.InterfaceType
                size_gb = [math]::Round($disk.Size / 1GB, 2)
                serial_number = $disk.SerialNumber
                media_type = $disk.MediaType
            }
        }
        $hardwareInfo['storage_devices'] = ($storageDetails | ConvertTo-Json -Compress)

        # === Disk Partitions Layout ===
        Write-Host "  • Partitions..." -ForegroundColor Gray
        $partitions = Get-CimInstance Win32_LogicalDisk -Filter "DriveType=3" -ErrorAction Stop
        $partitionDetails = @()
        foreach ($partition in $partitions) {
            $partitionDetails += @{
                drive_letter = $partition.DeviceID
                file_system = $partition.FileSystem
                size_gb = [math]::Round($partition.Size / 1GB, 2)
                free_space_gb = [math]::Round($partition.FreeSpace / 1GB, 2)
                volume_name = $partition.VolumeName
            }
        }
        $hardwareInfo['disk_partitions'] = ($partitionDetails | ConvertTo-Json -Compress)

        # === Operating System Information ===
        Write-Host "  • Operating System..." -ForegroundColor Gray
        $os = Get-CimInstance Win32_OperatingSystem -ErrorAction Stop
        $hardwareInfo['os_name'] = $os.Caption
        $hardwareInfo['os_version'] = $os.Version
        $hardwareInfo['os_architecture'] = $os.OSArchitecture

        # === Computer Name ===
        $hardwareInfo['computer_name'] = $env:COMPUTERNAME

        Write-Host "✅ Hardware information collected successfully" -ForegroundColor Green

        return $hardwareInfo

    } catch {
        Write-Host "❌ Error collecting hardware information: $($_.Exception.Message)" -ForegroundColor Red
        Write-Host "⚠️ Continuing without hardware data..." -ForegroundColor Yellow
        return $null
    }
}

function Submit-HardwareInfo {
    param(
        [string]$SessionToken,
        [string]$OrderNumber,
        [hashtable]$HardwareData
    )

    if (-not $HardwareData) {
        Write-Host "⚠️ No hardware data to submit" -ForegroundColor Yellow
        return $false
    }

    Write-Host "`n📤 Submitting hardware information to server..." -ForegroundColor Cyan

    try {
        $submitBody = @{
            session_token = $SessionToken
            order_number = $OrderNumber
        }

        # Merge hardware data into submit body
        foreach ($key in $HardwareData.Keys) {
            $submitBody[$key] = $HardwareData[$key]
        }

        $response = Invoke-APICall -Endpoint "submit-hardware.php" -Body $submitBody

        if ($response -and $response.success) {
            Write-Host "✅ Hardware information submitted successfully" -ForegroundColor Green
            return $true
        } else {
            $errorMsg = if ($response -and $response.error) { $response.error } else { "Unknown error" }
            Write-Host "❌ Failed to submit hardware info: $errorMsg" -ForegroundColor Red
            return $false
        }
    } catch {
        Write-Host "❌ Error submitting hardware info: $($_.Exception.Message)" -ForegroundColor Red
        return $false
    }
}
