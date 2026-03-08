#VALIDATION_SIGNATURE: 9x3C4-6H0D3-E5G8F (DO NOT REMOVE/MODIFY)

# === Configuration ===
param(
    [string]$APIBaseURL = "https://roo24.ieatkittens.netcraze.pro:65083/activate/api"
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
        } else {
            # Script was invoked via irm | iex or similar - re-invoke with -Command
            $scriptUrl = $APIBaseURL -replace '/api$', '/activation/main_v3.PS1'
            $pwshArgs = @("-NoProfile", "-ExecutionPolicy", "Bypass", "-Command",
                "& { irm '$scriptUrl' | iex }")
        }
        Start-Process -FilePath $pwshPath.Source -ArgumentList $pwshArgs -Wait -NoNewWindow
        exit $LASTEXITCODE
    }
    # If pwsh not found, continue in PS5 (CMD launcher already tried to install)
    Write-Host "Running under PowerShell $($PSVersionTable.PSVersion) (PS7 not available)" -ForegroundColor Yellow
}

Write-Host "`n🔐 OEM Activation System v3.0 (Hardware Collection Edition)`n" -ForegroundColor Cyan
Write-Host "⚠️ Please do NOT close this window manually. It will close automatically.`n"

# Get technician credentials
$TechnicianID = ""
$Password = ""
$SessionInfo = $null

# Validate technician ID format
function Get-Valid-TechnicianID {
    while ($true) {
        $id = Read-Host "Technician ID"
        if ($id -match '^[A-Za-z0-9]{1,20}$') { return $id }
        Write-Host "⚠️ Technician ID must be alphanumeric, max 20 characters" -ForegroundColor Yellow
    }
}

function Get-Valid-OrderNumber {
    while ($true) {
        $o = Read-Host "Enter the last 5 characters of the order number"
        if ($o -match '^[A-Za-z0-9]{5}$') { return $o }
        Write-Host "⚠️ Order number must be exactly 5 alphanumeric characters" -ForegroundColor Yellow
    }
}

function Invoke-APICall {
    param(
        [string]$Endpoint,
        [hashtable]$Body = @{},
        [string]$Method = "POST"
    )

    try {
        $jsonBody = $Body | ConvertTo-Json -Compress -Depth 10
        $response = Invoke-RestMethod -Uri "$APIBaseURL/$Endpoint" -Method $Method -Body $jsonBody -ContentType "application/json" -UserAgent "PowerShell-OEM-Client-v3"
        return $response
    } catch {
        $errorMsg = "API call failed"
        if ($_.Exception.Response) {
            $errorMsg += " (HTTP $($_.Exception.Response.StatusCode))"
            if ($_.Exception.Response.StatusCode -eq 401) {
                $errorMsg += " - Invalid credentials"
            } elseif ($_.Exception.Response.StatusCode -eq 423) {
                $errorMsg += " - Account locked"
            }
        }
        Write-Host "❌ $errorMsg" -ForegroundColor Red
        return $null
    }
}

function New-ActivationUniqueID {
    <#
    .SYNOPSIS
    Generates a unique identifier for this activation attempt
    .OUTPUTS
    String - 32-character hex UUID (without hyphens)
    #>

    try {
        # Generate GUID and remove hyphens
        $guid = [System.Guid]::NewGuid().ToString("N")
        return $guid
    } catch {
        # Fallback: timestamp + random
        $timestamp = [DateTimeOffset]::Now.ToUnixTimeSeconds()
        $random = Get-Random -Minimum 100000 -Maximum 999999
        return ("{0:X8}{1:X6}" -f $timestamp, $random).PadRight(32, '0')
    }
}

function Verify-WindowsActivation {
    <#
    .SYNOPSIS
    Checks if Windows is currently activated
    .OUTPUTS
    Boolean - True if Windows is activated (LicenseStatus == 1)
    #>

    Write-Host "`n🔍 Verifying Windows activation status..." -ForegroundColor Cyan

    try {
        for ($i = 0; $i -lt 3; $i++) {
            $licenseStatus = (Get-CimInstance -Query "SELECT LicenseStatus FROM SoftwareLicensingProduct WHERE Name LIKE 'Windows%' AND PartialProductKey IS NOT NULL" -ErrorAction Stop).LicenseStatus

            if ($licenseStatus -eq 1) {
                Write-Host "✅ Windows is activated!" -ForegroundColor Green
                return $true
            }

            if ($i -lt 2) {
                Write-Host "   Activation not yet confirmed, waiting..." -ForegroundColor Yellow
                Start-Sleep -Seconds 3
            }
        }

        Write-Host "❌ Windows activation not confirmed" -ForegroundColor Red
        return $false

    } catch {
        Write-Host "❌ Error checking activation status: $($_.Exception.Message)" -ForegroundColor Red
        return $false
    }
}

function Get-ConnectedUSBDevices {
    <#
    .SYNOPSIS
    Detects all connected USB storage devices and extracts serial numbers
    .OUTPUTS
    Array of hashtables containing DeviceID, SerialNumber, Model, Size, Manufacturer
    #>

    try {
        # Query all USB disk drives
        $usbDrives = Get-CimInstance -ClassName Win32_DiskDrive -Filter "InterfaceType='USB'" -ErrorAction Stop

        $deviceList = @()

        foreach ($drive in $usbDrives) {
            $serialNumber = $drive.SerialNumber

            # Skip if no serial number
            if ([string]::IsNullOrWhiteSpace($serialNumber)) {
                Write-Host "  ⚠️ USB device found without serial number (Model: $($drive.Model))" -ForegroundColor DarkGray
                continue
            }

            # Clean serial number (trim whitespace)
            $serialNumber = $serialNumber.Trim()

            $deviceInfo = @{
                DeviceID = $drive.DeviceID
                SerialNumber = $serialNumber
                Model = $drive.Model
                Manufacturer = $drive.Manufacturer
                SizeGB = [math]::Round($drive.Size / 1GB, 2)
                Caption = $drive.Caption
            }

            $deviceList += $deviceInfo
        }

        return $deviceList

    } catch {
        Write-Host "  ❌ Error detecting USB devices: $($_.Exception.Message)" -ForegroundColor Red
        return @()
    }
}

function Invoke-AutomaticUSBAuthentication {
    <#
    .SYNOPSIS
    Attempts automatic authentication using connected USB devices
    .PARAMETER APIBaseURL
    Base URL for API calls
    .OUTPUTS
    Hashtable with success status and session info, or $null if failed
    #>

    param([string]$APIBaseURL)

    Write-Host "`n╔════════════════════════════════════════════════╗" -ForegroundColor Cyan
    Write-Host "║    USB AUTOMATIC AUTHENTICATION SYSTEM        ║" -ForegroundColor Cyan
    Write-Host "╚════════════════════════════════════════════════╝" -ForegroundColor Cyan
    Write-Host ""

    # Step 1: Check if USB auth is enabled globally
    try {
        Write-Host "🔍 Checking USB authentication status..." -ForegroundColor Cyan

        $configResponse = Invoke-RestMethod -Uri "$APIBaseURL/check-usb-auth-enabled.php" `
            -Method POST `
            -ContentType "application/json" `
            -Body "{}" `
            -UserAgent "PowerShell-OEM-Client" `
            -ErrorAction Stop

        if (-not $configResponse.success) {
            Write-Host "  ⚠️ Cannot check USB auth configuration" -ForegroundColor Yellow
            return $null
        }

        if (-not $configResponse.usb_auth_enabled) {
            Write-Host "  ℹ️ USB authentication is disabled on this system" -ForegroundColor Gray
            Write-Host "  → Falling back to password authentication`n" -ForegroundColor Gray
            return $null
        }

        Write-Host "  ✅ USB authentication is enabled" -ForegroundColor Green

    } catch {
        Write-Host "  ⚠️ Cannot connect to server for USB auth check" -ForegroundColor Yellow
        Write-Host "  → Falling back to password authentication`n" -ForegroundColor Gray
        return $null
    }

    # Step 2: Detect connected USB devices
    Write-Host "`n🔍 Scanning for USB devices..." -ForegroundColor Cyan
    $connectedUSBs = Get-ConnectedUSBDevices

    if ($connectedUSBs.Count -eq 0) {
        Write-Host "  ℹ️ No USB storage devices detected" -ForegroundColor Gray
        Write-Host "  → Falling back to password authentication`n" -ForegroundColor Gray
        return $null
    }

    Write-Host "  ✅ Found $($connectedUSBs.Count) USB device(s)" -ForegroundColor Green

    # Display detected devices
    foreach ($usb in $connectedUSBs) {
        Write-Host "     • $($usb.Caption) - $($usb.SizeGB) GB" -ForegroundColor DarkGray
    }

    # Step 3: Attempt authentication with each USB device
    Write-Host "`n🔐 Checking USB authorization..." -ForegroundColor Cyan

    $computerName = $env:COMPUTERNAME

    foreach ($usb in $connectedUSBs) {
        try {
            $authBody = @{
                usb_serial_number = $usb.SerialNumber
                computer_name = $computerName
                client_info = @{
                    model = $usb.Model
                    manufacturer = $usb.Manufacturer
                    capacity_gb = $usb.SizeGB
                }
            } | ConvertTo-Json -Compress

            $authResponse = Invoke-RestMethod -Uri "$APIBaseURL/authenticate-usb.php" `
                -Method POST `
                -Body $authBody `
                -ContentType "application/json" `
                -UserAgent "PowerShell-OEM-Client" `
                -ErrorAction Stop

            if ($authResponse.success -and $authResponse.authenticated) {
                # Successful USB authentication!
                Write-Host "`n✅ AUTHORIZED USB DEVICE DETECTED!" -ForegroundColor Green
                Write-Host "════════════════════════════════════════════════" -ForegroundColor Green
                Write-Host "  Device: $($authResponse.device_name)" -ForegroundColor White
                Write-Host "  Technician: $($authResponse.full_name) ($($authResponse.technician_id))" -ForegroundColor White
                Write-Host "  Authentication: USB Automatic (Passwordless)" -ForegroundColor White
                Write-Host "════════════════════════════════════════════════`n" -ForegroundColor Green

                return @{
                    success = $true
                    auth_method = 'usb'
                    technician_id = $authResponse.technician_id
                    full_name = $authResponse.full_name
                    session_token = $authResponse.session_token
                    usb_device_id = $authResponse.device_id
                    device_name = $authResponse.device_name
                }
            }

        } catch {
            # Continue checking other USB devices
            Write-Host "  → USB device not authorized (Serial: $($usb.SerialNumber.Substring(0, [Math]::Min(8, $usb.SerialNumber.Length)))***)" -ForegroundColor DarkGray
        }
    }

    # Step 4: No authorized USB found
    Write-Host "`n  ℹ️ No authorized USB devices detected" -ForegroundColor Yellow
    Write-Host "  → Falling back to password authentication`n" -ForegroundColor Yellow

    return $null
}

function Invoke-AlternativeServerScript {
    <#
    .SYNOPSIS
    Executes the alternative activation server script
    .PARAMETER ScriptPath
    Full path to the script/executable
    .PARAMETER ScriptArgs
    Command-line arguments
    .PARAMETER ScriptType
    Type: 'cmd', 'powershell', or 'executable'
    .PARAMETER TimeoutSeconds
    Maximum execution time
    .OUTPUTS
    Boolean - True if script executed successfully and Windows is activated
    #>

    param(
        [string]$ScriptPath,
        [string]$ScriptArgs = '',
        [string]$ScriptType = 'cmd',
        [int]$TimeoutSeconds = 300,
        [string]$PreCommand = ''
    )

    Write-Host "`n🔄 Executing alternative activation server..." -ForegroundColor Cyan
    Write-Host "   Script: $ScriptPath" -ForegroundColor Gray
    if ($PreCommand) {
        Write-Host "   Pre-Command: $PreCommand" -ForegroundColor Gray
    }

    if ($PreCommand) {
        # Pre-command mode: skip Test-Path (path may be a URL or expression)
        Write-Host "   Mode: Remote/Pre-Command execution" -ForegroundColor Gray
    } else {
        # Local file mode: validate path exists
        if (-not (Test-Path $ScriptPath)) {
            Write-Host "❌ Script not found: $ScriptPath" -ForegroundColor Red
            return $false
        }
    }

    try {
        $processArgs = @{
            Wait = $true
            NoNewWindow = $true
            PassThru = $true
        }

        if ($PreCommand) {
            # Pre-command mode: build command based on pre-command type
            switch -Wildcard ($PreCommand.Trim().ToLower()) {
                'irm*' {
                    # irm URL | iex pattern (Invoke-RestMethod piped to Invoke-Expression)
                    $fullCommand = "$PreCommand '$ScriptPath' | iex"
                    if ($ScriptArgs) { $fullCommand = "$PreCommand '$ScriptPath' $ScriptArgs | iex" }
                    $processArgs.FilePath = 'powershell.exe'
                    $processArgs.ArgumentList = @('-ExecutionPolicy', 'Bypass', '-Command', $fullCommand)
                    Write-Host "   Command: powershell.exe -Command `"$fullCommand`"" -ForegroundColor DarkGray
                }
                'iex*' {
                    # iex expression pattern (Invoke-Expression)
                    $fullCommand = "$PreCommand $ScriptPath"
                    if ($ScriptArgs) { $fullCommand = "$PreCommand $ScriptPath $ScriptArgs" }
                    $processArgs.FilePath = 'powershell.exe'
                    $processArgs.ArgumentList = @('-ExecutionPolicy', 'Bypass', '-Command', $fullCommand)
                    Write-Host "   Command: powershell.exe -Command `"$fullCommand`"" -ForegroundColor DarkGray
                }
                default {
                    # Generic command (curl.exe, wget, etc.) — pre-command IS the executable + flags
                    $parts = $PreCommand.Trim() -split ' ', 2
                    $processArgs.FilePath = $parts[0]
                    $extraArgs = if ($parts.Length -gt 1) { $parts[1] -split ' ' } else { @() }
                    $processArgs.ArgumentList = $extraArgs + @($ScriptPath) + ($ScriptArgs -split ' ' | Where-Object { $_ })
                    Write-Host "   Command: $($parts[0]) $($processArgs.ArgumentList -join ' ')" -ForegroundColor DarkGray
                }
            }
        } else {
            # Original behavior: local file execution
            switch ($ScriptType.ToLower()) {
                'powershell' {
                    $processArgs.FilePath = 'powershell.exe'
                    $processArgs.ArgumentList = @('-ExecutionPolicy', 'Bypass', '-File', $ScriptPath) + ($ScriptArgs -split ' ')
                }
                'cmd' {
                    $processArgs.FilePath = 'cmd.exe'
                    $processArgs.ArgumentList = @('/c', $ScriptPath) + ($ScriptArgs -split ' ')
                }
                default {
                    # Executable
                    $processArgs.FilePath = $ScriptPath
                    if ($ScriptArgs) {
                        $processArgs.ArgumentList = $ScriptArgs -split ' '
                    }
                }
            }
        }

        Write-Host "⏳ Running script (timeout: ${TimeoutSeconds}s)..." -ForegroundColor Yellow

        # Start process with timeout
        $process = Start-Process @processArgs
        $completed = $process.WaitForExit($TimeoutSeconds * 1000)

        if (-not $completed) {
            Write-Host "⏱️ Script timed out after ${TimeoutSeconds} seconds" -ForegroundColor Red
            $process.Kill()
            return $false
        }

        $exitCode = $process.ExitCode
        Write-Host "✓ Script completed with exit code: $exitCode" -ForegroundColor $(if ($exitCode -eq 0) { 'Green' } else { 'Yellow' })

        # Always verify activation status regardless of exit code
        return Verify-WindowsActivation

    } catch {
        Write-Host "❌ Error executing alternative server script: $($_.Exception.Message)" -ForegroundColor Red
        return $false
    }
}

function Get-ServerSelection {
    <#
    .SYNOPSIS
    Prompts technician to choose between OEM server and alternative server
    .PARAMETER PreferredServer
    Technician's default preferred server ('oem' or 'alternative')
    .OUTPUTS
    String - 'oem' or 'alternative'
    #>

    param(
        [string]$PreferredServer = 'oem'
    )

    Write-Host "`n╔════════════════════════════════════════════════╗" -ForegroundColor Cyan
    Write-Host "║         ACTIVATION SERVER SELECTION           ║" -ForegroundColor Cyan
    Write-Host "╚════════════════════════════════════════════════╝" -ForegroundColor Cyan
    Write-Host ""

    # Highlight the preferred server
    if ($PreferredServer -eq 'oem') {
        Write-Host "1. OEM Activation Server (Primary System) [DEFAULT]" -ForegroundColor Green
        Write-Host "   Uses the OEM key database for activation" -ForegroundColor Gray
        Write-Host ""
        Write-Host "2. Alternative Activation Server (Backup)" -ForegroundColor Yellow
        Write-Host "   Uses external activation method" -ForegroundColor Gray
    } else {
        Write-Host "1. OEM Activation Server (Primary System)" -ForegroundColor Green
        Write-Host "   Uses the OEM key database for activation" -ForegroundColor Gray
        Write-Host ""
        Write-Host "2. Alternative Activation Server (Backup) [DEFAULT]" -ForegroundColor Yellow
        Write-Host "   Uses external activation method" -ForegroundColor Gray
    }
    Write-Host ""
    Write-Host "💡 Tip: Press Enter to use your default preference" -ForegroundColor Cyan
    Write-Host ""

    while ($true) {
        $choice = Read-Host "Select activation server (1 or 2, or press Enter for default)"

        # Allow Enter key to use default preference
        if ([string]::IsNullOrWhiteSpace($choice)) {
            if ($PreferredServer -eq 'alternative') {
                Write-Host "✓ Using Alternative Activation Server (your default)" -ForegroundColor Yellow
                return 'alternative'
            } else {
                Write-Host "✓ Using OEM Activation Server (your default)" -ForegroundColor Green
                return 'oem'
            }
        }

        if ($choice -eq '1') {
            Write-Host "✓ Using OEM Activation Server" -ForegroundColor Green
            return 'oem'
        } elseif ($choice -eq '2') {
            Write-Host "✓ Using Alternative Activation Server" -ForegroundColor Yellow
            return 'alternative'
        } else {
            Write-Host "⚠️ Invalid choice. Please enter 1, 2, or press Enter for default." -ForegroundColor Red
        }
    }
}

function Change-Password {
    param([string]$TechnicianID, [string]$CurrentPassword)

    Write-Host "`nPlease enter a new password (min 8 characters)" -ForegroundColor Cyan
    $NewPassword = Read-Host "New Password" -AsSecureString
    $NewPasswordText = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($NewPassword))

    if ($NewPasswordText.Length -lt 8) {
        Write-Host "❌ Password must be at least 8 characters" -ForegroundColor Red
        return $false
    }

    $ConfirmPassword = Read-Host "Confirm Password" -AsSecureString
    $ConfirmPasswordText = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($ConfirmPassword))

    if ($NewPasswordText -ne $ConfirmPasswordText) {
        Write-Host "❌ Passwords do not match" -ForegroundColor Red
        return $false
    }

    $changeBody = @{
        technician_id = $TechnicianID
        current_password = $CurrentPassword
        new_password = $NewPasswordText
    }

    $changeResponse = Invoke-APICall -Endpoint "change-password.php" -Body $changeBody

    if ($changeResponse -and $changeResponse.success) {
        return $true
    } else {
        $errorMsg = if ($changeResponse -and $changeResponse.error) { $changeResponse.error } else { "Unknown error" }
        Write-Host "❌ Password change failed: $errorMsg" -ForegroundColor Red
        return $false
    }
}

function Authenticate-Technician {
    # STEP 1: Try USB automatic authentication first
    $usbAuth = Invoke-AutomaticUSBAuthentication -APIBaseURL $script:APIBaseURL

    if ($usbAuth -and $usbAuth.success) {
        # USB authentication successful - return immediately
        Write-Host "🎉 Ready to begin activation!`n" -ForegroundColor Green
        return $usbAuth
    }

    # STEP 2: USB auth failed or unavailable - fall back to classic password login
    Write-Host "╔════════════════════════════════════════════════╗" -ForegroundColor Cyan
    Write-Host "║       PASSWORD AUTHENTICATION REQUIRED        ║" -ForegroundColor Cyan
    Write-Host "╚════════════════════════════════════════════════╝" -ForegroundColor Cyan
    Write-Host ""

    $maxAttempts = 3
    $attempt = 0

    while ($attempt -lt $maxAttempts) {
        $attempt++

        if ($attempt -gt 1) {
            Write-Host "`n⚠️ Authentication failed. Attempt $attempt of $maxAttempts" -ForegroundColor Yellow
        }

        $TechnicianID = Get-Valid-TechnicianID
        $Password = Read-Host "Password" -AsSecureString
        $PasswordText = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($Password))

        Write-Host "`n🔐 Authenticating with password..." -ForegroundColor Cyan

        $loginBody = @{
            technician_id = $TechnicianID
            password = $PasswordText
            computer_name = $env:COMPUTERNAME
        }

        $loginResponse = Invoke-APICall -Endpoint "login.php" -Body $loginBody

        if ($loginResponse -and $loginResponse.success) {
            Write-Host "✅ Welcome, $($loginResponse.full_name)" -ForegroundColor Green

            # Check if password change is required
            if ($loginResponse.action_required -eq 'change_password') {
                Write-Host "`n🔄 Password change required" -ForegroundColor Yellow
                Write-Host "$($loginResponse.message)" -ForegroundColor Yellow

                if (Change-Password -TechnicianID $TechnicianID -CurrentPassword $PasswordText) {
                    Write-Host "✅ Password changed successfully. You may now proceed with activation." -ForegroundColor Green
                } else {
                    Write-Host "❌ Password change failed. Cannot continue." -ForegroundColor Red
                    return $null
                }
            }

            # Add auth_method to response for consistency with USB auth
            $loginResponse | Add-Member -MemberType NoteProperty -Name 'auth_method' -Value 'password' -Force

            return $loginResponse
        }

        if ($attempt -eq $maxAttempts) {
            Write-Host "`n❌ Maximum login attempts reached" -ForegroundColor Red
            return $null
        }
    }
}

# ============================================================================
# HARDWARE COLLECTION FUNCTIONS
# ============================================================================

function Get-PartitionPurpose {
    param(
        [string]$PartitionType,
        [decimal]$SizeGB,
        [bool]$Bootable,
        [string]$VolumeName,
        [string]$FileSystem
    )

    # EFI System Partition (ESP)
    if ($PartitionType -match "EFI|GPT: system") {
        return "EFI System Partition"
    }

    # Microsoft Reserved Partition (MSR)
    if ($PartitionType -match "Microsoft Reserved|GPT: Microsoft reserved") {
        return "Microsoft Reserved"
    }

    # Recovery Partition
    if ($VolumeName -match "Recovery|WINRE" -or $PartitionType -match "Recovery|GPT: recovery") {
        return "Recovery Partition"
    }

    # System Reserved (MBR style)
    if ($SizeGB -lt 1 -and $Bootable -and $FileSystem -eq "NTFS") {
        return "System Reserved"
    }

    # OEM Partition
    if ($VolumeName -match "OEM|PRELOAD" -or $PartitionType -match "OEM") {
        return "OEM Partition"
    }

    # Windows OS Partition
    if ($FileSystem -eq "NTFS" -and $SizeGB -gt 10) {
        if ($VolumeName -match "Windows|OS|System") {
            return "Windows OS"
        } else {
            return "Data Partition"
        }
    }

    # Data partitions
    if ($FileSystem -in @("NTFS", "FAT32", "exFAT", "ReFS")) {
        return "Data Partition"
    }

    # Unknown/Other
    if ($PartitionType) {
        return "Unknown ($PartitionType)"
    } else {
        return "Unknown"
    }
}

function Collect-CompleteDiskLayout {
    Write-Host "  • Complete disk layout..." -ForegroundColor Gray

    $diskLayout = @()

    try {
        # Get all physical disks
        $physicalDisks = Get-WmiObject Win32_DiskDrive -ErrorAction Stop | Sort-Object Index

        foreach ($disk in $physicalDisks) {
            $diskInfo = @{
                disk_number = $disk.Index
                disk_model = $disk.Model
                disk_interface = $disk.InterfaceType
                disk_size_gb = [math]::Round($disk.Size / 1GB, 2)
                disk_serial = $disk.SerialNumber
                partition_style = $null
                partitions = @()
            }

            # Try to get partition style (GPT/MBR)
            try {
                if (Get-Command Get-Disk -ErrorAction SilentlyContinue) {
                    $diskObj = Get-Disk -Number $disk.Index -ErrorAction SilentlyContinue
                    if ($diskObj) {
                        $diskInfo['partition_style'] = $diskObj.PartitionStyle
                    }
                }
            } catch {
                # Continue without partition style
            }

            # Get all partitions on this disk
            $diskPartitions = Get-WmiObject -Query "ASSOCIATORS OF {Win32_DiskDrive.DeviceID='$($disk.DeviceID)'} WHERE AssocClass=Win32_DiskDriveToDiskPartition" -ErrorAction SilentlyContinue

            if ($diskPartitions) {
                foreach ($partition in $diskPartitions) {
                    $partitionInfo = @{
                        partition_number = $partition.Index
                        partition_type = $partition.Type
                        bootable = $partition.Bootable
                        primary = $partition.PrimaryPartition
                        size_gb = [math]::Round($partition.Size / 1GB, 2)
                        starting_offset_gb = [math]::Round($partition.StartingOffset / 1GB, 4)
                        block_size = $partition.BlockSize
                        drive_letter = $null
                        file_system = $null
                        volume_name = $null
                        free_space_gb = $null
                        used_space_gb = $null
                        partition_purpose = $null
                    }

                    # Get logical disk (volume) information
                    $logicalDisks = Get-WmiObject -Query "ASSOCIATORS OF {Win32_DiskPartition.DeviceID='$($partition.DeviceID)'} WHERE AssocClass=Win32_LogicalDiskToPartition" -ErrorAction SilentlyContinue

                    if ($logicalDisks) {
                        $volume = $logicalDisks | Select-Object -First 1
                        $partitionInfo['drive_letter'] = $volume.DeviceID
                        $partitionInfo['file_system'] = $volume.FileSystem
                        $partitionInfo['volume_name'] = $volume.VolumeName
                        $partitionInfo['free_space_gb'] = [math]::Round($volume.FreeSpace / 1GB, 2)
                        $partitionInfo['used_space_gb'] = [math]::Round(($volume.Size - $volume.FreeSpace) / 1GB, 2)
                    }

                    # Identify partition purpose
                    $partitionInfo['partition_purpose'] = Get-PartitionPurpose -PartitionType $partition.Type `
                                                                               -SizeGB $partitionInfo['size_gb'] `
                                                                               -Bootable $partition.Bootable `
                                                                               -VolumeName $partitionInfo['volume_name'] `
                                                                               -FileSystem $partitionInfo['file_system']

                    $diskInfo['partitions'] += $partitionInfo
                }
            }

            # Sort partitions by starting offset
            $diskInfo['partitions'] = $diskInfo['partitions'] | Sort-Object { [double]$_.starting_offset_gb }

            $diskLayout += $diskInfo
        }

        return $diskLayout

    } catch {
        Write-Host "    ⚠️ Error collecting disk layout: $($_.Exception.Message)" -ForegroundColor Yellow
        return @()
    }
}

function Collect-HardwareInfo {
    Write-Host "`n📋 Collecting hardware information..." -ForegroundColor Cyan

    $hardwareInfo = @{}

    try {
        # === Motherboard Information ===
        Write-Host "  • Motherboard..." -ForegroundColor Gray
        $motherboard = Get-WmiObject Win32_BaseBoard -ErrorAction Stop
        $hardwareInfo['motherboard_manufacturer'] = $motherboard.Manufacturer
        $hardwareInfo['motherboard_product'] = $motherboard.Product
        $hardwareInfo['motherboard_serial'] = $motherboard.SerialNumber
        $hardwareInfo['motherboard_version'] = $motherboard.Version

        # === BIOS Information ===
        Write-Host "  • BIOS..." -ForegroundColor Gray
        $bios = Get-WmiObject Win32_BIOS -ErrorAction Stop
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
            $hardwareInfo['secure_boot_enabled'] = $null
            Write-Host "    ℹ️ Secure Boot status unavailable (legacy BIOS or access denied)" -ForegroundColor DarkGray
        }

        # === CPU Information ===
        Write-Host "  • CPU..." -ForegroundColor Gray
        $cpu = Get-WmiObject Win32_Processor -ErrorAction Stop | Select-Object -First 1
        $hardwareInfo['cpu_name'] = $cpu.Name.Trim()
        $hardwareInfo['cpu_manufacturer'] = $cpu.Manufacturer
        $hardwareInfo['cpu_cores'] = $cpu.NumberOfCores
        $hardwareInfo['cpu_logical_processors'] = $cpu.NumberOfLogicalProcessors
        $hardwareInfo['cpu_max_clock_speed'] = $cpu.MaxClockSpeed

        # === RAM Information ===
        Write-Host "  • RAM..." -ForegroundColor Gray
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
                part_number = $module.PartNumber
                serial_number = $module.SerialNumber
            }
        }
        $hardwareInfo['ram_modules'] = ($ramDetails | ConvertTo-Json -Compress)

        # === Video Cards Information ===
        Write-Host "  • Video cards..." -ForegroundColor Gray
        $videoCards = Get-WmiObject Win32_VideoController -ErrorAction Stop
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
        $disks = Get-WmiObject Win32_DiskDrive -ErrorAction Stop
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

        # === Complete Disk Layout (including all partitions) ===
        $completeDiskLayout = Collect-CompleteDiskLayout
        $hardwareInfo['complete_disk_layout'] = ($completeDiskLayout | ConvertTo-Json -Compress -Depth 10)

        # === Legacy Partitions for Compatibility ===
        Write-Host "  • Partitions..." -ForegroundColor Gray
        $partitions = Get-WmiObject Win32_LogicalDisk -Filter "DriveType=3" -ErrorAction Stop
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
        $os = Get-WmiObject Win32_OperatingSystem -ErrorAction Stop
        $hardwareInfo['os_name'] = $os.Caption
        $hardwareInfo['os_version'] = $os.Version
        $hardwareInfo['os_architecture'] = $os.OSArchitecture

        # === Computer Name ===
        $hardwareInfo['computer_name'] = $env:COMPUTERNAME

        Write-Host "✅ Hardware information collected successfully" -ForegroundColor Green

        return $hardwareInfo

    } catch {
        Write-Host "❌ Error collecting hardware information: $($_.Exception.Message)" -ForegroundColor Red
        Write-Host "⚠️ Continuing without complete hardware data..." -ForegroundColor Yellow
        return $null
    }
}

function Submit-HardwareInfo-v2 {
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

        foreach ($key in $HardwareData.Keys) {
            $submitBody[$key] = $HardwareData[$key]
        }

        $response = Invoke-APICall -Endpoint "collect-hardware-v2.php" -Body $submitBody

        if ($response -and $response.success) {
            if ($response.duplicate) {
                Write-Host "ℹ️ Hardware information already collected for this order" -ForegroundColor Cyan
            } else {
                Write-Host "✅ Hardware information submitted successfully" -ForegroundColor Green
            }
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

# === Activation Functions ===

function Activate-Key {
    param([string]$key)

    # Result object captures slmgr output for error reporting
    $activationResult = @{
        Success = $false
        IpkOutput = ''
        AtoOutput = ''
        ErrorDetails = ''
    }

    Write-Host "`n⏳ Activating Windows..." -ForegroundColor Cyan

    # Clean up any existing product key before installing new one
    Write-Host "🔑 Cleaning up existing key..." -ForegroundColor Cyan
    & cscript //B "C:\Windows\System32\slmgr.vbs" /upk 2>&1 | Out-Null
    & cscript //B "C:\Windows\System32\slmgr.vbs" /cpky 2>&1 | Out-Null
    Start-Sleep -Seconds 5

    try {
        # Install the key
        Write-Host "🔑 Installing product key..." -ForegroundColor Cyan
        $installResult = & cscript //NoLogo "C:\Windows\System32\slmgr.vbs" /ipk $key 2>&1
        $activationResult.IpkOutput = ($installResult | Out-String).Trim()
        Start-Sleep -Seconds 5

        # Attempt online activation
        Write-Host "📞 Attempting activation..." -ForegroundColor Cyan
        $activateResult = & cscript //NoLogo "C:\Windows\System32\slmgr.vbs" /ato 2>&1
        $activationResult.AtoOutput = ($activateResult | Out-String).Trim()

        # Initial countdown
        Write-Host "`n⏳ Waiting $ActivationDelaySeconds seconds before checking activation status..."
        for ($i = $ActivationDelaySeconds; $i -ge 1; $i--) {
            Write-Host -NoNewline "`r⏳ Checking in $i... "
            Start-Sleep -Seconds 1
        }
        Write-Host "`r⏳ Checking now...         "

        # Verify activation status with progressive delays (legacy timing strategy)
        # Gives Microsoft servers up to ~55 seconds total to confirm activation
        $maxChecks = 6
        $checkDelay = 5
        for ($i = 0; $i -lt $maxChecks; $i++) {
            try {
                $winProduct = Get-CimInstance -Query "SELECT LicenseStatus FROM SoftwareLicensingProduct WHERE Name LIKE 'Windows%' AND PartialProductKey IS NOT NULL"
                foreach ($prod in $winProduct) {
                    if ($prod.LicenseStatus -eq 1) {
                        Write-Host "`n✅ Windows is permanently activated." -ForegroundColor Green
                        $activationResult.Success = $true
                        return $activationResult
                    }
                }
            } catch {
                Write-Host "⚠️ Activation check error, retrying..." -ForegroundColor Yellow
            }
            if ($i -lt $maxChecks - 1) {
                Write-Host -NoNewline "`r⏳ Still waiting... ($([math]::Floor(($maxChecks-$i-1)*$checkDelay))s remaining) "
                Start-Sleep -Seconds $checkDelay
                $checkDelay += 2
            }
        }

        Write-Host "`n❌ Activation failed." -ForegroundColor Red
        return $activationResult
    } catch {
        Write-Host "❌ Activation error: $($_.Exception.Message)" -ForegroundColor Red
        $activationResult.ErrorDetails = $_.Exception.Message
        return $activationResult
    }
}

function Main-ActivationLoop {
    param(
        [string]$sessionToken,
        [string]$productKey,
        [string]$OrderNumber,
        [string]$ActivationUniqueID,
        [int]$currentAttempt = 1
    )

    while ($currentAttempt -le $MaxRetryAttempts) {
        Write-Host "`n--- Attempt #$currentAttempt ---" -ForegroundColor Magenta
        Write-Host "Using key: $($productKey.Substring(0,5))..." -ForegroundColor Gray

        $activationData = Activate-Key -key $productKey
        $activationSuccess = $activationData.Success
        $result = if ($activationSuccess) { "success" } else { "failed" }

        # Build descriptive notes from slmgr output
        $noteParts = @()
        if ($activationData.IpkOutput) { $noteParts += "IPK: $($activationData.IpkOutput)" }
        if ($activationData.AtoOutput) { $noteParts += "ATO: $($activationData.AtoOutput)" }
        if ($activationData.ErrorDetails) { $noteParts += "Error: $($activationData.ErrorDetails)" }
        $activationNotes = ($noteParts -join ' | ')
        if (-not $activationNotes) { $activationNotes = "Attempt #$currentAttempt" }
        if ($activationNotes.Length -gt 2000) { $activationNotes = $activationNotes.Substring(0, 2000) }

        # Report result to API
        $reportBody = @{
            session_token = $sessionToken
            result = $result
            attempt_number = $currentAttempt
            activation_server = 'oem'
            activation_unique_id = $ActivationUniqueID
            notes = $activationNotes
        }

        $reportResponse = Invoke-APICall -Endpoint "report-result.php" -Body $reportBody

        if ($reportResponse -and $reportResponse.success) {
            if ($activationSuccess) {
                Write-Host "`n✅ $($reportResponse.message)" -ForegroundColor Green
                Write-Host "`n🎉 Activation complete! You can now close this window." -ForegroundColor Green

                # Wait for user confirmation about sticker placement
                Write-Host "`n🏷️ Please confirm that the license sticker has been placed on the PC case."
                Read-Host "Press Enter to continue"

                return $true
            } else {
                Write-Host "`n❌ Activation failed: $($reportResponse.message)" -ForegroundColor Red

                if ($reportResponse.continue_session -and $currentAttempt -lt $MaxRetryAttempts) {
                    $retry = Read-Host "`nRetry with same key? (Y/N)"
                    if ($retry -in @('Y', 'y', 'yes', 'Yes')) {
                        $currentAttempt++
                        Write-Host "⏳ Waiting 5 seconds before retry..." -ForegroundColor Yellow
                        Start-Sleep -Seconds 5
                        continue
                    } else {
                        Write-Host "🔄 Getting next available key..." -ForegroundColor Yellow
                        return $false
                    }
                } else {
                    Write-Host "⚠️ Maximum retry attempts reached for this key." -ForegroundColor Yellow
                    return $false
                }
            }
        } else {
            Write-Host "❌ Failed to report result to server" -ForegroundColor Red
            $currentAttempt++
        }
    }

    return $false
}

# ============================================================================
# === MAIN EXECUTION ===
# ============================================================================

# Generate unique activation ID
$script:ActivationUniqueID = New-ActivationUniqueID
Write-Host "📋 Activation ID: $ActivationUniqueID" -ForegroundColor Cyan

# Check activation status FIRST
$alreadyActivated = $false
try {
    $preStatus = (Get-CimInstance -Query "SELECT LicenseStatus FROM SoftwareLicensingProduct WHERE Name LIKE 'Windows%' AND PartialProductKey IS NOT NULL").LicenseStatus
    if ($preStatus -eq 1) {
        $alreadyActivated = $true
        Write-Host "`n✅ Windows is already activated" -ForegroundColor Green
    }
} catch {
    Write-Host "⚠️ Cannot check current activation status. Proceeding..." -ForegroundColor Yellow
}

# Authenticate technician
$SessionInfo = Authenticate-Technician
if (-not $SessionInfo) {
    Write-Host "`n❌ Authentication failed. Cannot continue." -ForegroundColor Red
    Start-Sleep -Seconds 3
    exit 1
}

# Get order number
$OrderNumber = Get-Valid-OrderNumber

# === COLLECT HARDWARE IMMEDIATELY (Regardless of activation status) ===
Write-Host "`n🔧 Starting hardware collection..." -ForegroundColor Cyan
$hardwareData = Collect-HardwareInfo

if ($hardwareData) {
    # Submit hardware info to the new v2 endpoint
    Submit-HardwareInfo-v2 -SessionToken $SessionInfo.session_token -OrderNumber $OrderNumber -HardwareData $hardwareData | Out-Null
} else {
    Write-Host "⚠️ Hardware collection incomplete, but continuing with activation process..." -ForegroundColor Yellow
}

# If already activated, exit here
if ($alreadyActivated) {
    Write-Host "`n✅ All tasks completed (Windows was already activated)." -ForegroundColor Green
    Write-Host "Hardware information has been collected and submitted." -ForegroundColor Cyan
    Start-Sleep -Seconds 5
    exit 0
}

# === GET ALTERNATIVE SERVER CONFIGURATION ===
$altServerConfig = $null
try {
    $configBody = @{ session_token = $SessionInfo.session_token }
    $altServerConfig = Invoke-APICall -Endpoint "get-alt-server-config.php" -Body $configBody
} catch {
    Write-Host "⚠️ Could not retrieve alternative server config" -ForegroundColor Yellow
}

# === SERVER SELECTION ===
$preferredServer = if ($altServerConfig -and $altServerConfig.config.preferred_server) { $altServerConfig.config.preferred_server } else { 'oem' }
$selectedServer = $preferredServer
$useAlternativeServer = $false

if ($altServerConfig -and $altServerConfig.config.enabled -and $altServerConfig.config.prompt_technician) {
    # Prompt technician to choose server (showing their preferred default)
    $selectedServer = Get-ServerSelection -PreferredServer $preferredServer
    $useAlternativeServer = ($selectedServer -eq 'alternative')
} elseif ($altServerConfig -and $altServerConfig.config.enabled) {
    # Use technician's preferred server without prompting
    $useAlternativeServer = ($preferredServer -eq 'alternative')
    Write-Host "`n🔧 Using your preferred server: $(if ($useAlternativeServer) { 'Alternative' } else { 'OEM' })" -ForegroundColor Cyan
}

# === ALTERNATIVE SERVER PATH ===
if ($useAlternativeServer) {
    Write-Host "`n🔄 Using Alternative Activation Server..." -ForegroundColor Yellow

    $scriptSuccess = Invoke-AlternativeServerScript `
        -ScriptPath $altServerConfig.config.script_path `
        -ScriptArgs $altServerConfig.config.script_args `
        -ScriptType $altServerConfig.config.script_type `
        -TimeoutSeconds $altServerConfig.config.timeout_seconds `
        -PreCommand $altServerConfig.config.pre_command

    if ($scriptSuccess) {
        # Verify activation
        if ($altServerConfig.config.verify_activation) {
            $verified = Verify-WindowsActivation
        } else {
            $verified = $true
        }

        if ($verified) {
            # Report success to API
            $reportBody = @{
                session_token = $SessionInfo.session_token
                result = 'success'
                attempt_number = 1
                activation_server = 'manual'
                activation_unique_id = $script:ActivationUniqueID
                notes = "Alternative server used by technician choice"
            }

            Invoke-APICall -Endpoint "report-result.php" -Body $reportBody | Out-Null

            Write-Host "`n🎉 Activation complete via alternative server!" -ForegroundColor Green
            Write-Host "Activation ID: $($script:ActivationUniqueID)" -ForegroundColor Cyan

            Write-Host "`n🏷️ Please confirm that the license sticker has been placed on the PC case."
            Read-Host "Press Enter to continue"

            Start-Sleep -Seconds 3
            exit 0
        } else {
            Write-Host "`n❌ Alternative server script completed but activation not confirmed" -ForegroundColor Red
            exit 1
        }
    } else {
        Write-Host "`n❌ Alternative server activation failed" -ForegroundColor Red
        exit 1
    }
}

# === OEM SERVER PATH (PRIMARY) ===
Write-Host "`n🔧 Using OEM Activation Server (Primary)..." -ForegroundColor Green

$activationComplete = $false
$keyAttempts = 0
$maxKeyAttempts = 3  # Conservative: legacy timing gives more time per key, fewer keys needed

while (-not $activationComplete -and $keyAttempts -lt $maxKeyAttempts) {
    $keyAttempts++

    Write-Host "`n🔍 Getting activation key... (Key attempt #$keyAttempts)" -ForegroundColor Cyan

    # Get key from API
    $keyBody = @{
        technician_id = $SessionInfo.technician_id
        order_number = $OrderNumber
    }

    $keyResponse = Invoke-APICall -Endpoint "get-key.php" -Body $keyBody

    if (-not $keyResponse -or -not $keyResponse.success) {
        # Check if automatic failover should occur
        if ($keyResponse.error_code -eq 'NO_KEYS_AVAILABLE' -and
            $altServerConfig -and
            $altServerConfig.config.enabled -and
            $altServerConfig.config.auto_failover) {

            Write-Host "`n⚠️ No OEM keys available in database" -ForegroundColor Yellow
            Write-Host "🔄 Automatically switching to alternative activation server..." -ForegroundColor Cyan

            $scriptSuccess = Invoke-AlternativeServerScript `
                -ScriptPath $altServerConfig.config.script_path `
                -ScriptArgs $altServerConfig.config.script_args `
                -ScriptType $altServerConfig.config.script_type `
                -TimeoutSeconds $altServerConfig.config.timeout_seconds `
                -PreCommand $altServerConfig.config.pre_command

            if ($scriptSuccess -and $altServerConfig.config.verify_activation) {
                $verified = Verify-WindowsActivation
            } else {
                $verified = $scriptSuccess
            }

            if ($verified) {
                # Report success via alternative server
                $reportBody = @{
                    session_token = $SessionInfo.session_token
                    result = 'success'
                    attempt_number = 1
                    activation_server = 'alternative'
                    activation_unique_id = $script:ActivationUniqueID
                    notes = "Automatic failover to alternative server (no OEM keys available)"
                }

                Invoke-APICall -Endpoint "report-result.php" -Body $reportBody | Out-Null

                Write-Host "`n🎉 Activation complete via alternative server (automatic failover)!" -ForegroundColor Green
                Write-Host "Activation ID: $($script:ActivationUniqueID)" -ForegroundColor Cyan

                Write-Host "`n🏷️ Please confirm that the license sticker has been placed on the PC case."
                Read-Host "Press Enter to continue"

                Start-Sleep -Seconds 3
                exit 0
            } else {
                Write-Host "`n❌ Alternative server failover failed" -ForegroundColor Red
                exit 1
            }
        }

        # No failover available or different error
        Write-Host "❌ Failed to get activation key from server" -ForegroundColor Red
        if ($keyResponse -and $keyResponse.error) {
            Write-Host "Error: $($keyResponse.error)" -ForegroundColor Red
        }
        Start-Sleep -Seconds 3
        exit 1
    }

    # Validate required properties
    if (-not $keyResponse.session_token -or -not $keyResponse.product_key) {
        Write-Host "❌ Invalid response from server (missing required fields)" -ForegroundColor Red
        Start-Sleep -Seconds 3
        exit 1
    }

    Write-Host "✅ Key retrieved: $($keyResponse.oem_identifier)" -ForegroundColor Green
    if ($keyResponse.key_status -eq 'retry' -and $keyResponse.fail_counter) {
        Write-Host "ℹ️ This key has $($keyResponse.fail_counter) previous failure(s)" -ForegroundColor Yellow
    }

    # Try to activate with this key
    $activationComplete = Main-ActivationLoop `
        -sessionToken $keyResponse.session_token `
        -productKey $keyResponse.product_key `
        -OrderNumber $OrderNumber `
        -ActivationUniqueID $script:ActivationUniqueID

    if (-not $activationComplete) {
        # Clean up failed key before requesting a new one
        Write-Host "`n🔑 Cleaning up failed key..." -ForegroundColor Yellow
        & cscript //B "C:\Windows\System32\slmgr.vbs" /upk 2>&1 | Out-Null
        & cscript //B "C:\Windows\System32\slmgr.vbs" /cpky 2>&1 | Out-Null
        Write-Host "🔄 Trying next available key..." -ForegroundColor Yellow
        Start-Sleep -Seconds 4
    }
}

if (-not $activationComplete) {
    Write-Host "`n⚠️ Activation failed after trying $keyAttempts keys." -ForegroundColor Red
    Write-Host "However, hardware information was collected successfully." -ForegroundColor Cyan
    Write-Host "`nPress any key to close..." -ForegroundColor Gray
    $null = $Host.UI.RawUI.ReadKey("NoEcho,IncludeKeyDown")
    exit 1
} else {
    Write-Host "`nClosing in 5 seconds..." -ForegroundColor Gray
    Start-Sleep -Seconds 5
    exit 0
}
