#VALIDATION_SIGNATURE: 9x3C4-6H0D3-E5G8F (DO NOT REMOVE/MODIFY)

# === Configuration ===
param(
    [string]$APIBaseURL = "https://roo24.ieatkittens.netcraze.pro:65083/activate/api",
    [string]$Language = "auto"
)

$ActivationDelaySeconds = 10
$MaxRetryAttempts = 3

# === PowerShell 7 Self-Restart (Safety Net) ===
# If running under PS5 and pwsh.exe is available, restart in PS7 for better compatibility
if ($PSVersionTable.PSVersion.Major -lt 7) {
    $pwshPath = Get-Command pwsh -ErrorAction SilentlyContinue
    if ($pwshPath) {
        Write-Host "`nRestarting in PowerShell 7 for better compatibility..." -ForegroundColor Cyan
        $scriptPath = $MyInvocation.MyCommand.Source
        if ($scriptPath -and (Test-Path $scriptPath -ErrorAction SilentlyContinue)) {
            # Script is a local file - re-invoke with -File and forward parameters
            $pwshArgs = @("-NoProfile", "-ExecutionPolicy", "Bypass", "-File", "`"$scriptPath`"", "-APIBaseURL", "`"$APIBaseURL`"")
            if ($Language -and $Language -ne 'auto') {
                $pwshArgs += @("-Language", "`"$Language`"")
            }
        } else {
            # Script was invoked via irm | iex or similar - re-invoke with -Command
            $scriptUrl = $APIBaseURL -replace '/api$', '/activation/main_v2.PS1'
            $pwshArgs = @("-NoProfile", "-ExecutionPolicy", "Bypass", "-Command",
                "& { irm '$scriptUrl' | iex }")
        }
        Start-Process -FilePath $pwshPath.Source -ArgumentList $pwshArgs -Wait -NoNewWindow
        exit $LASTEXITCODE
    }
    # If pwsh not found, continue in PS5 (CMD launcher already tried to install)
    Write-Host "Running under PowerShell $($PSVersionTable.PSVersion) (PS7 not available)" -ForegroundColor Yellow
}

# === Language Support ===
$script:L = @{}

function Load-Language {
    param([string]$LangCode)

    $langDir = Join-Path $PSScriptRoot "lang"
    $langFile = Join-Path $langDir "$LangCode.ps1"

    if (Test-Path $langFile) {
        try {
            $script:L = & $langFile
            return $true
        } catch {
            # Fallback to English on error
            if ($LangCode -ne "en") {
                $enFile = Join-Path $langDir "en.ps1"
                if (Test-Path $enFile) {
                    $script:L = & $enFile
                }
            }
            return $false
        }
    } else {
        # Try English fallback
        $enFile = Join-Path $langDir "en.ps1"
        if (Test-Path $enFile) {
            $script:L = & $enFile
            return $true
        }
        return $false
    }
}

# Load default language (English) for pre-login messages
Load-Language -LangCode "en" | Out-Null

Write-Host ("`n" + $L['banner'] + "`n") -ForegroundColor Cyan
Write-Host ($L['banner_warning'] + "`n")

# Get technician credentials
$TechnicianID = ""
$Password = ""
$SessionInfo = $null

# Validate technician ID format
function Get-Valid-TechnicianID {
    while ($true) {
        $id = Read-Host $L['prompt_tech_id']
        if ($id -match '^[A-Za-z0-9]{1,20}$') { return $id }
        Write-Host $L['tech_id_invalid'] -ForegroundColor Yellow
    }
}

function Get-Valid-OrderNumber {
    while ($true) {
        $o = Read-Host $L['prompt_order']
        if ($o -match '^[A-Za-z0-9]{5}$') { return $o }
        Write-Host $L['order_invalid'] -ForegroundColor Yellow
    }
}

function Invoke-APICall {
    param(
        [string]$Endpoint,
        [hashtable]$Body = @{},
        [string]$Method = "POST"
    )

    try {
        $jsonBody = $Body | ConvertTo-Json -Compress
        $response = Invoke-RestMethod -Uri "$APIBaseURL/$Endpoint" -Method $Method -Body $jsonBody -ContentType "application/json" -UserAgent "PowerShell-OEM-Client"
        return $response
    } catch {
        $errorMsg = $L['api_failed']
        if ($_.Exception.Response) {
            $errorMsg = ($L['api_http_error'] -f $_.Exception.Response.StatusCode)
            if ($_.Exception.Response.StatusCode -eq 401) {
                $errorMsg += $L['api_invalid_credentials']
            } elseif ($_.Exception.Response.StatusCode -eq 423) {
                $errorMsg += $L['api_account_locked']
            }
        }
        Write-Host "❌ $errorMsg" -ForegroundColor Red
        return $null
    }
}

function Authenticate-Technician {
    $maxAttempts = 3
    $attempt = 0

    while ($attempt -lt $maxAttempts) {
        $attempt++

        if ($attempt -gt 1) {
            Write-Host ("`n" + ($L['auth_failed_attempt'] -f $attempt, $maxAttempts)) -ForegroundColor Yellow
        }

        $TechnicianID = Get-Valid-TechnicianID
        $Password = Read-Host $L['prompt_password'] -AsSecureString
        $PasswordText = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($Password))

        Write-Host ("`n" + $L['auth_progress']) -ForegroundColor Cyan

        $loginBody = @{
            technician_id = $TechnicianID
            password = $PasswordText
        }

        $loginResponse = Invoke-APICall -Endpoint "login.php" -Body $loginBody

        if ($loginResponse -and $loginResponse.success) {
            # Switch language based on user preference from server
            $userLang = $loginResponse.preferred_language
            if ($userLang -and $userLang -ne "en") {
                Load-Language -LangCode $userLang | Out-Null
            }
            # If -Language was explicitly set, override
            if ($Language -ne "auto" -and $Language -ne $userLang) {
                Load-Language -LangCode $Language | Out-Null
            }

            Write-Host ($L['auth_success'] -f $loginResponse.full_name) -ForegroundColor Green

            # Check if password change is required
            if ($loginResponse.action_required -eq 'change_password') {
                Write-Host ("`n" + $L['pwd_change_required']) -ForegroundColor Yellow
                Write-Host "$($loginResponse.message)" -ForegroundColor Yellow

                if (Change-Password -TechnicianID $TechnicianID -CurrentPassword $PasswordText) {
                    Write-Host $L['pwd_change_success'] -ForegroundColor Green
                } else {
                    Write-Host $L['pwd_change_failed'] -ForegroundColor Red
                    return $null
                }
            }

            return @{
                technician_id = $loginResponse.technician_id
                full_name = $loginResponse.full_name
            }
        }

        if ($loginResponse -and $loginResponse.error -eq 'Account locked due to too many failed attempts') {
            Write-Host $L['auth_locked'] -ForegroundColor Red
            return $null
        }

        # Clear password from memory
        $PasswordText = $null
    }

    Write-Host ($L['auth_max_attempts'] -f $maxAttempts) -ForegroundColor Red
    return $null
}

function Change-Password {
    param(
        [string]$TechnicianID,
        [string]$CurrentPassword
    )

    $maxAttempts = 3
    $attempt = 0

    while ($attempt -lt $maxAttempts) {
        $attempt++

        Write-Host ("`n" + $L['pwd_requirements']) -ForegroundColor Cyan
        Write-Host $L['pwd_req_length'] -ForegroundColor Gray
        Write-Host $L['pwd_req_complexity'] -ForegroundColor Gray
        Write-Host ($L['pwd_req_different'] + "`n") -ForegroundColor Gray

        $NewPassword = Read-Host $L['pwd_new'] -AsSecureString
        $NewPasswordText = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($NewPassword))

        $ConfirmPassword = Read-Host $L['pwd_confirm'] -AsSecureString
        $ConfirmPasswordText = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($ConfirmPassword))

        if ($NewPasswordText -ne $ConfirmPasswordText) {
            Write-Host $L['pwd_mismatch'] -ForegroundColor Red
            $NewPasswordText = $null
            $ConfirmPasswordText = $null
            continue
        }

        $changeBody = @{
            technician_id = $TechnicianID
            current_password = $CurrentPassword
            new_password = $NewPasswordText
            confirm_password = $ConfirmPasswordText
        }

        $changeResponse = Invoke-APICall -Endpoint "change-password.php" -Body $changeBody

        # Clear passwords from memory
        $NewPasswordText = $null
        $ConfirmPasswordText = $null

        if ($changeResponse -and $changeResponse.success) {
            return $true
        } else {
            Write-Host ($L['pwd_change_error'] -f $changeResponse.error) -ForegroundColor Red
            if ($attempt -lt $maxAttempts) {
                Write-Host $L['pwd_try_again'] -ForegroundColor Yellow
            }
        }
    }

    return $false
}

function Sync-SystemTime {
    Write-Host ("`n" + $L['time_sync']) -ForegroundColor Cyan

    try {
        # Stop Windows Time service
        $stopResult = & net stop w32time 2>&1
        Start-Sleep -Seconds 1

        # Start Windows Time service
        $startResult = & net start w32time 2>&1
        Start-Sleep -Seconds 1

        # Force time resync
        $resyncResult = & w32tm /resync 2>&1

        if ($LASTEXITCODE -eq 0) {
            Write-Host $L['time_sync_success'] -ForegroundColor Green
            return $true
        } else {
            Write-Host $L['time_sync_warning'] -ForegroundColor Yellow
            return $true  # Continue anyway as this is not critical
        }
    } catch {
        Write-Host ($L['time_sync_error'] -f $_.Exception.Message) -ForegroundColor Yellow
        return $true  # Continue anyway as this is not critical
    }
}

function Activate-Key {
    param ([string]$key)

    # Synchronize system time before activation
    Sync-SystemTime

    # Clean up any existing product key before installing new one
    Write-Host ("`n" + $L['key_cleanup']) -ForegroundColor Cyan
    & cscript.exe //B C:\Windows\System32\slmgr.vbs /upk 2>&1 | Out-Null
    & cscript.exe //B C:\Windows\System32\slmgr.vbs /cpky 2>&1 | Out-Null
    Start-Sleep -Seconds 5

    Write-Host ($L['key_installing']) -ForegroundColor Cyan

    try {
        $ipkResult = & cscript.exe //NoLogo C:\Windows\System32\slmgr.vbs /ipk $key 2>&1
        if ($LASTEXITCODE -ne 0) {
            Write-Host ($L['key_install_code'] -f $LASTEXITCODE) -ForegroundColor Yellow
        } else {
            Write-Host $L['key_install_success'] -ForegroundColor Green
        }
    } catch {
        Write-Host ($L['key_install_error'] -f $_.Exception.Message) -ForegroundColor Red
        return $false
    }

    Start-Sleep -Seconds 5

    Write-Host $L['activation_progress'] -ForegroundColor Cyan

    try {
        $atoResult = & cscript.exe //NoLogo C:\Windows\System32\slmgr.vbs /ato 2>&1
        if ($LASTEXITCODE -ne 0) {
            Write-Host ($L['activation_code'] -f $LASTEXITCODE) -ForegroundColor Yellow
        } else {
            Write-Host $L['activation_command_done'] -ForegroundColor Green
        }
    } catch {
        Write-Host ($L['activation_error'] -f $_.Exception.Message) -ForegroundColor Red
    }

    Write-Host ("`n" + ($L['activation_waiting'] -f $ActivationDelaySeconds)) -ForegroundColor Yellow
    for ($i = $ActivationDelaySeconds; $i -ge 1; $i--) {
        Write-Host -NoNewline ("`r" + ($L['activation_checking'] -f $i))
        Start-Sleep -Seconds 1
    }

    # Verify activation status with progressive delays (legacy timing strategy)
    # Gives Microsoft servers up to ~55 seconds total to confirm activation
    $maxChecks = 6
    $checkDelay = 5
    for ($i = 0; $i -lt $maxChecks; $i++) {
        try {
            $licenseStatus = Get-CimInstance -Query "SELECT LicenseStatus FROM SoftwareLicensingProduct WHERE Name LIKE 'Windows%' AND PartialProductKey IS NOT NULL"
            foreach ($prod in $licenseStatus) {
                if ($prod.LicenseStatus -eq 1) {
                    Write-Host ("`n" + $L['activation_confirmed']) -ForegroundColor Green
                    return $true
                }
            }
        } catch {
            Write-Host $L['activation_check_failed'] -ForegroundColor Yellow
        }
        if ($i -lt $maxChecks - 1) {
            Write-Host -NoNewline ("`r" + ($L['activation_still_waiting'] -f [math]::Floor(($maxChecks - $i - 1) * $checkDelay)))
            Start-Sleep -Seconds $checkDelay
            $checkDelay += 2
        }
    }

    Write-Host ("`n" + $L['activation_verify_failed']) -ForegroundColor Red
    return $false
}

function Collect-HardwareInfo {
    Write-Host ("`n" + $L['hw_collecting']) -ForegroundColor Cyan
    $hardwareInfo = @{}

    try {
        # Motherboard Information
        Write-Host $L['hw_motherboard'] -ForegroundColor Gray
        $motherboard = Get-WmiObject Win32_BaseBoard -ErrorAction Stop
        $hardwareInfo['motherboard_manufacturer'] = $motherboard.Manufacturer
        $hardwareInfo['motherboard_product'] = $motherboard.Product
        $hardwareInfo['motherboard_serial'] = $motherboard.SerialNumber
        $hardwareInfo['motherboard_version'] = $motherboard.Version

        # System Enclosure / Chassis Information
        Write-Host $L['hw_chassis'] -ForegroundColor Gray
        try {
            $chassis = Get-WmiObject Win32_SystemEnclosure -ErrorAction Stop | Select-Object -First 1
            $hardwareInfo['chassis_manufacturer'] = $chassis.Manufacturer
            $hardwareInfo['chassis_serial'] = $chassis.SerialNumber
            $chassisTypes = @{1='Other';2='Unknown';3='Desktop';4='Low Profile Desktop';5='Pizza Box';6='Mini Tower';7='Tower';8='Portable';9='Laptop';10='Notebook';11='Hand Held';12='Docking Station';13='All in One';14='Sub Notebook';15='Space-Saving';16='Lunch Box';17='Main Server Chassis';18='Expansion Chassis';19='SubChassis';20='Bus Expansion Chassis';21='Peripheral Chassis';22='RAID Chassis';23='Rack Mount Chassis';24='Sealed-Case PC';25='Multi-system chassis';26='Compact PCI';27='Advanced TCA';28='Blade';29='Blade Enclosure';30='Tablet';31='Convertible';32='Detachable';33='IoT Gateway';34='Embedded PC';35='Mini PC';36='Stick PC'}
            $typeNum = if ($chassis.ChassisTypes) { $chassis.ChassisTypes[0] } else { 0 }
            $hardwareInfo['chassis_type'] = if ($chassisTypes.ContainsKey([int]$typeNum)) { $chassisTypes[[int]$typeNum] } else { "Type $typeNum" }
        } catch {
            Write-Host ($L['hw_unavailable'] -f "Chassis info") -ForegroundColor DarkGray
        }

        # System Product Information (OEM system info)
        Write-Host $L['hw_system_product'] -ForegroundColor Gray
        try {
            $csProduct = Get-WmiObject Win32_ComputerSystemProduct -ErrorAction Stop
            $hardwareInfo['system_manufacturer'] = $csProduct.Vendor
            $hardwareInfo['system_product_name'] = $csProduct.Name
            $hardwareInfo['system_serial'] = $csProduct.IdentifyingNumber
            $hardwareInfo['system_uuid'] = $csProduct.UUID
        } catch {
            Write-Host ($L['hw_unavailable'] -f "System product info") -ForegroundColor DarkGray
        }

        # BIOS Information
        Write-Host $L['hw_bios'] -ForegroundColor Gray
        $bios = Get-WmiObject Win32_BIOS -ErrorAction Stop
        $hardwareInfo['bios_manufacturer'] = $bios.Manufacturer
        $hardwareInfo['bios_version'] = $bios.SMBIOSBIOSVersion
        $hardwareInfo['bios_release_date'] = $bios.ReleaseDate
        $hardwareInfo['bios_serial_number'] = $bios.SerialNumber

        # Secure Boot Status
        Write-Host $L['hw_secure_boot'] -ForegroundColor Gray
        try {
            $secureBootEnabled = Confirm-SecureBootUEFI -ErrorAction Stop
            $hardwareInfo['secure_boot_enabled'] = if ($secureBootEnabled) { 1 } else { 0 }
        } catch {
            $hardwareInfo['secure_boot_enabled'] = $null
            Write-Host ($L['hw_unavailable'] -f "Secure Boot status") -ForegroundColor DarkGray
        }

        # TPM Information
        Write-Host $L['hw_tpm'] -ForegroundColor Gray
        try {
            $tpm = Get-WmiObject -Namespace "root\cimv2\Security\MicrosoftTpm" -Class Win32_Tpm -ErrorAction Stop
            $hardwareInfo['tpm_present'] = 1
            $hardwareInfo['tpm_version'] = $tpm.SpecVersion
            $hardwareInfo['tpm_manufacturer'] = $tpm.ManufacturerIdTxt
        } catch {
            $hardwareInfo['tpm_present'] = 0
            Write-Host ($L['hw_unavailable'] -f "TPM info") -ForegroundColor DarkGray
        }

        # CPU Information
        Write-Host $L['hw_cpu'] -ForegroundColor Gray
        $cpu = Get-WmiObject Win32_Processor -ErrorAction Stop | Select-Object -First 1
        $hardwareInfo['cpu_name'] = $cpu.Name.Trim()
        $hardwareInfo['cpu_manufacturer'] = $cpu.Manufacturer
        $hardwareInfo['cpu_cores'] = $cpu.NumberOfCores
        $hardwareInfo['cpu_logical_processors'] = $cpu.NumberOfLogicalProcessors
        $hardwareInfo['cpu_max_clock_speed'] = $cpu.MaxClockSpeed
        $hardwareInfo['cpu_serial'] = $cpu.ProcessorId

        # RAM Information
        Write-Host $L['hw_ram'] -ForegroundColor Gray
        $ramModules = Get-WmiObject Win32_PhysicalMemory -ErrorAction Stop
        $totalRamBytes = ($ramModules | Measure-Object -Property Capacity -Sum).Sum
        $hardwareInfo['ram_total_capacity_gb'] = [math]::Round($totalRamBytes / 1GB, 2)
        $hardwareInfo['ram_slots_used'] = $ramModules.Count

        try {
            $ramSlots = Get-WmiObject Win32_PhysicalMemoryArray -ErrorAction Stop
            $hardwareInfo['ram_slots_total'] = ($ramSlots | Measure-Object -Property MemoryDevices -Sum).Sum
        } catch {
            $hardwareInfo['ram_slots_total'] = $ramModules.Count
        }

        $ramDetails = @()
        foreach ($module in $ramModules) {
            $ramDetails += @{
                manufacturer = $module.Manufacturer
                capacity_gb = [math]::Round($module.Capacity / 1GB, 2)
                speed_mhz = $module.Speed
                part_number = if ($module.PartNumber) { $module.PartNumber.Trim() } else { $null }
                serial_number = if ($module.SerialNumber) { $module.SerialNumber.Trim() } else { $null }
                bank_label = $module.BankLabel
                device_locator = $module.DeviceLocator
            }
        }
        $hardwareInfo['ram_modules'] = ($ramDetails | ConvertTo-Json -Compress)

        # Video Cards Information
        Write-Host $L['hw_video'] -ForegroundColor Gray
        $videoCards = Get-WmiObject Win32_VideoController -ErrorAction Stop
        $videoDetails = @()
        foreach ($card in $videoCards) {
            $videoDetails += @{
                name = $card.Name
                driver_version = $card.DriverVersion
                video_processor = $card.VideoProcessor
                adapter_ram_mb = if ($card.AdapterRAM) { [math]::Round($card.AdapterRAM / 1MB, 0) } else { $null }
                resolution = "$($card.CurrentHorizontalResolution)x$($card.CurrentVerticalResolution)"
                pnp_device_id = $card.PNPDeviceID
            }
        }
        $hardwareInfo['video_cards'] = ($videoDetails | ConvertTo-Json -Compress)

        # Storage Devices Information
        Write-Host $L['hw_storage'] -ForegroundColor Gray
        $disks = Get-WmiObject Win32_DiskDrive -ErrorAction Stop
        $storageDetails = @()
        foreach ($disk in $disks) {
            # Extract real serial from PNPDeviceID (more reliable)
            $diskSerial = $disk.SerialNumber
            if ($disk.PNPDeviceID -match '\\([^\\]+)$') {
                $extracted = $matches[1] -replace '&\d+$', ''
                if ($extracted.Length -gt 2) { $diskSerial = $extracted }
            }
            $storageDetails += @{
                model = $disk.Model
                interface_type = $disk.InterfaceType
                size_gb = [math]::Round($disk.Size / 1GB, 2)
                serial_number = if ($diskSerial) { $diskSerial.Trim() } else { $null }
                media_type = $disk.MediaType
                firmware_revision = $disk.FirmwareRevision
                pnp_device_id = $disk.PNPDeviceID
            }
        }
        $hardwareInfo['storage_devices'] = ($storageDetails | ConvertTo-Json -Compress)

        # Disk Partitions Layout
        Write-Host $L['hw_partitions'] -ForegroundColor Gray
        $partitions = Get-WmiObject Win32_LogicalDisk -Filter "DriveType=3" -ErrorAction Stop
        $partitionDetails = @()
        foreach ($partition in $partitions) {
            $partitionDetails += @{
                drive_letter = $partition.DeviceID
                file_system = $partition.FileSystem
                size_gb = [math]::Round($partition.Size / 1GB, 2)
                free_space_gb = [math]::Round($partition.FreeSpace / 1GB, 2)
                volume_name = $partition.VolumeName
                volume_serial = $partition.VolumeSerialNumber
            }
        }
        $hardwareInfo['disk_partitions'] = ($partitionDetails | ConvertTo-Json -Compress)

        # Network Adapters Information
        Write-Host $L['hw_network'] -ForegroundColor Gray
        try {
            $networkAdapters = Get-WmiObject Win32_NetworkAdapterConfiguration -Filter "MACAddress IS NOT NULL" -ErrorAction Stop
            $networkDetails = @()
            foreach ($adapter in $networkAdapters) {
                $adapterInfo = @{
                    description = $adapter.Description
                    mac_address = $adapter.MACAddress
                    dhcp_enabled = $adapter.DHCPEnabled
                }
                if ($adapter.IPAddress) {
                    $adapterInfo['ip_addresses'] = ($adapter.IPAddress -join ', ')
                    $adapterInfo['subnet_masks'] = if ($adapter.IPSubnet) { ($adapter.IPSubnet -join ', ') } else { $null }
                    $adapterInfo['default_gateway'] = if ($adapter.DefaultIPGateway) { ($adapter.DefaultIPGateway -join ', ') } else { $null }
                    $adapterInfo['dns_servers'] = if ($adapter.DNSServerSearchOrder) { ($adapter.DNSServerSearchOrder -join ', ') } else { $null }
                }
                $networkDetails += $adapterInfo
            }
            $hardwareInfo['network_adapters'] = ($networkDetails | ConvertTo-Json -Compress)

            # Primary MAC address (first physical adapter with IP)
            $primaryAdapter = $networkAdapters | Where-Object { $_.IPAddress } | Select-Object -First 1
            if ($primaryAdapter) {
                $hardwareInfo['primary_mac_address'] = $primaryAdapter.MACAddress
                $ipv4 = $primaryAdapter.IPAddress | Where-Object { $_ -match '^\d+\.\d+\.\d+\.\d+$' } | Select-Object -First 1
                $hardwareInfo['local_ip'] = $ipv4
            }
        } catch {
            Write-Host ($L['hw_unavailable'] -f "Network adapter info") -ForegroundColor DarkGray
        }

        # Public IP Address
        Write-Host $L['hw_public_ip'] -ForegroundColor Gray
        try {
            $publicIpResponse = Invoke-RestMethod -Uri "https://api.ipify.org?format=json" -TimeoutSec 5 -ErrorAction Stop
            $hardwareInfo['public_ip'] = $publicIpResponse.ip
        } catch {
            try {
                $publicIpResponse = Invoke-RestMethod -Uri "https://ifconfig.me/ip" -TimeoutSec 5 -ErrorAction Stop
                $hardwareInfo['public_ip'] = $publicIpResponse.Trim()
            } catch {
                $hardwareInfo['public_ip'] = $null
                Write-Host ($L['hw_unavailable'] -f "Public IP") -ForegroundColor DarkGray
            }
        }

        # Sound Devices
        Write-Host $L['hw_audio'] -ForegroundColor Gray
        try {
            $soundDevices = Get-WmiObject Win32_SoundDevice -ErrorAction Stop
            $audioDetails = @()
            foreach ($device in $soundDevices) {
                $audioDetails += @{
                    name = $device.Name
                    manufacturer = $device.Manufacturer
                    pnp_device_id = $device.PNPDeviceID
                }
            }
            $hardwareInfo['audio_devices'] = ($audioDetails | ConvertTo-Json -Compress)
        } catch {
            Write-Host ($L['hw_unavailable'] -f "Audio device info") -ForegroundColor DarkGray
        }

        # Monitor Information
        Write-Host $L['hw_monitors'] -ForegroundColor Gray
        try {
            $monitors = Get-WmiObject WmiMonitorID -Namespace root\wmi -ErrorAction Stop
            $monitorDetails = @()
            foreach ($monitor in $monitors) {
                $monitorName = if ($monitor.UserFriendlyName) { ($monitor.UserFriendlyName | Where-Object { $_ -ne 0 } | ForEach-Object { [char]$_ }) -join '' } else { 'Unknown' }
                $monitorSerial = if ($monitor.SerialNumberID) { ($monitor.SerialNumberID | Where-Object { $_ -ne 0 } | ForEach-Object { [char]$_ }) -join '' } else { 'N/A' }
                $monitorMfg = if ($monitor.ManufacturerName) { ($monitor.ManufacturerName | Where-Object { $_ -ne 0 } | ForEach-Object { [char]$_ }) -join '' } else { 'Unknown' }
                $monitorDetails += @{
                    name = $monitorName
                    manufacturer = $monitorMfg
                    serial_number = $monitorSerial
                    year_of_manufacture = $monitor.YearOfManufacture
                }
            }
            $hardwareInfo['monitors'] = ($monitorDetails | ConvertTo-Json -Compress)
        } catch {
            Write-Host ($L['hw_unavailable'] -f "Monitor info") -ForegroundColor DarkGray
        }

        # Operating System Information
        Write-Host $L['hw_os'] -ForegroundColor Gray
        $os = Get-WmiObject Win32_OperatingSystem -ErrorAction Stop
        $hardwareInfo['os_name'] = $os.Caption
        $hardwareInfo['os_version'] = $os.Version
        $hardwareInfo['os_architecture'] = $os.OSArchitecture
        $hardwareInfo['os_build_number'] = $os.BuildNumber
        $hardwareInfo['os_install_date'] = $os.InstallDate
        $hardwareInfo['os_serial_number'] = $os.SerialNumber
        $hardwareInfo['computer_name'] = $env:COMPUTERNAME

        # System Summary (Device Fingerprint)
        $hardwareInfo['device_fingerprint'] = "$($hardwareInfo['motherboard_serial'])|$($hardwareInfo['bios_serial_number'])|$($hardwareInfo['system_uuid'])|$($hardwareInfo['primary_mac_address'])"

        Write-Host $L['hw_collected'] -ForegroundColor Green
        return $hardwareInfo

    } catch {
        Write-Host ($L['hw_error'] -f $_.Exception.Message) -ForegroundColor Red
        Write-Host $L['hw_continuing'] -ForegroundColor Yellow
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
        Write-Host $L['hw_no_data'] -ForegroundColor Yellow
        return $false
    }

    Write-Host ("`n" + $L['hw_submitting']) -ForegroundColor Cyan

    try {
        $submitBody = @{
            session_token = $SessionToken
            order_number = $OrderNumber
        }

        foreach ($key in $HardwareData.Keys) {
            $submitBody[$key] = $HardwareData[$key]
        }

        $response = Invoke-APICall -Endpoint "submit-hardware.php" -Body $submitBody

        if ($response -and $response.success) {
            Write-Host $L['hw_submitted'] -ForegroundColor Green
            return $true
        } else {
            $errorMsg = if ($response -and $response.error) { $response.error } else { "Unknown error" }
            Write-Host ($L['hw_submit_failed'] -f $errorMsg) -ForegroundColor Red
            return $false
        }
    } catch {
        Write-Host ($L['hw_submit_error'] -f $_.Exception.Message) -ForegroundColor Red
        return $false
    }
}

function Main-ActivationLoop {
    param([string]$sessionToken, [string]$productKey, [string]$OrderNumber, [int]$currentAttempt = 1)

    while ($currentAttempt -le $MaxRetryAttempts) {
        Write-Host ("`n" + ($L['attempt_header'] -f $currentAttempt)) -ForegroundColor Magenta
        Write-Host ($L['attempt_using_key'] -f $productKey.Substring(0,5)) -ForegroundColor Gray

        $activationSuccess = Activate-Key -key $productKey
        $result = if ($activationSuccess) { "success" } else { "failed" }

        # Report result to API
        $reportBody = @{
            session_token = $sessionToken
            result = $result
            attempt_number = $currentAttempt
        }

        $reportResponse = Invoke-APICall -Endpoint "report-result.php" -Body $reportBody

        if ($reportResponse -and $reportResponse.success) {
            if ($activationSuccess) {
                Write-Host ("`n✅ " + $reportResponse.message) -ForegroundColor Green

                # Collect and submit hardware information
                $hardwareData = Collect-HardwareInfo
                if ($hardwareData) {
                    Submit-HardwareInfo -SessionToken $sessionToken -OrderNumber $OrderNumber -HardwareData $hardwareData | Out-Null
                }

                Write-Host ("`n" + $L['complete_success']) -ForegroundColor Green

                # Wait for user confirmation about sticker placement
                Write-Host ("`n" + $L['complete_sticker'])
                Read-Host $L['complete_press_enter']

                return $true
            } else {
                Write-Host ("`n❌ " + ($L['attempt_report_failed'] -replace '❌ ', '') + ": " + $reportResponse.message) -ForegroundColor Red

                if ($reportResponse.continue_session -and $currentAttempt -lt $MaxRetryAttempts) {
                    $retry = Read-Host ("`n" + $L['attempt_retry'])
                    if ($retry -in @('Y', 'y', 'yes', 'Yes', 'Д', 'д', 'да', 'Да')) {
                        $currentAttempt++
                        Write-Host $L['activation_retry_wait'] -ForegroundColor Yellow
                        Start-Sleep -Seconds 5
                        continue
                    } else {
                        Write-Host $L['key_getting_next'] -ForegroundColor Yellow
                        return $false  # Will trigger getting a new key
                    }
                } else {
                    Write-Host $L['attempt_max_reached'] -ForegroundColor Yellow
                    return $false
                }
            }
        } else {
            Write-Host $L['attempt_report_failed'] -ForegroundColor Red
            $currentAttempt++
        }
    }

    return $false
}

# === MAIN EXECUTION ===

# Override language if explicitly specified
if ($Language -ne "auto") {
    Load-Language -LangCode $Language | Out-Null
}

# Pre-check if Windows is already activated
try {
    $preStatus = (Get-CimInstance -Query "SELECT LicenseStatus FROM SoftwareLicensingProduct WHERE Name LIKE 'Windows%' AND PartialProductKey IS NOT NULL").LicenseStatus
    if ($preStatus -eq 1) {
        Write-Host ("`n" + $L['precheck_activated']) -ForegroundColor Green
        Start-Sleep -Seconds 3
        exit 0
    }
} catch {
    Write-Host $L['precheck_error'] -ForegroundColor Yellow
}

# Authenticate technician
$SessionInfo = Authenticate-Technician
if (-not $SessionInfo) {
    Write-Host ("`n" + $L['auth_failed_continue']) -ForegroundColor Red
    Start-Sleep -Seconds 3
    exit 1
}

# Get order number
$OrderNumber = Get-Valid-OrderNumber

# Main activation loop
$activationComplete = $false
$keyAttempts = 0
$maxKeyAttempts = 3  # Conservative: legacy timing gives more time per key, fewer keys needed

while (-not $activationComplete -and $keyAttempts -lt $maxKeyAttempts) {
    $keyAttempts++

    Write-Host ("`n" + ($L['key_getting'] -f $keyAttempts)) -ForegroundColor Cyan

    # Get key from API
    $keyBody = @{
        technician_id = $SessionInfo.technician_id
        order_number = $OrderNumber
    }

    $keyResponse = Invoke-APICall -Endpoint "get-key.php" -Body $keyBody

    if (-not $keyResponse -or -not $keyResponse.success) {
        Write-Host $L['key_get_failed'] -ForegroundColor Red
        if ($keyResponse -and $keyResponse.error) {
            Write-Host ($L['key_error'] -f $keyResponse.error) -ForegroundColor Red
        }
        Start-Sleep -Seconds 3
        exit 1
    }

    # Validate required properties
    if (-not $keyResponse.session_token -or -not $keyResponse.product_key) {
        Write-Host $L['key_invalid_response'] -ForegroundColor Red
        Start-Sleep -Seconds 3
        exit 1
    }

    Write-Host ($L['key_retrieved'] -f $keyResponse.oem_identifier) -ForegroundColor Green
    if ($keyResponse.key_status -eq 'retry' -and $keyResponse.fail_counter) {
        Write-Host ($L['key_previous_failures'] -f $keyResponse.fail_counter) -ForegroundColor Yellow
    }

    # Try to activate with this key
    $activationComplete = Main-ActivationLoop -sessionToken $keyResponse.session_token -productKey $keyResponse.product_key -OrderNumber $OrderNumber

    if (-not $activationComplete) {
        # Clean up failed key before requesting a new one
        Write-Host ("`n" + $L['key_cleanup_next']) -ForegroundColor Yellow
        & cscript.exe //B C:\Windows\System32\slmgr.vbs /upk 2>&1 | Out-Null
        & cscript.exe //B C:\Windows\System32\slmgr.vbs /cpky 2>&1 | Out-Null
        Write-Host ($L['key_trying_next']) -ForegroundColor Yellow
        Start-Sleep -Seconds 4
    }
}

if (-not $activationComplete) {
    Write-Host ("`n" + ($L['complete_failed_keys'] -f $keyAttempts)) -ForegroundColor Red
    Write-Host ("`n" + $L['complete_press_any']) -ForegroundColor Gray
    $null = $Host.UI.RawUI.ReadKey("NoEcho,IncludeKeyDown")
    exit 1
} else {
    Write-Host ("`n" + $L['complete_closing']) -ForegroundColor Gray
    Start-Sleep -Seconds 5
    exit 0
}
