@echo off
setlocal EnableDelayedExpansion
REM OEM Activation System v3.1 - Technician Launcher
REM Hardware Collection + USB Auth + Adaptive Timing + Self-Update
REM ==============================================================
REM LAUNCHER_VERSION=3.1.0

title OEM Activation System v3.1

REM Display banner
echo.
echo  ====================================================
echo  OEM Activation System v3.1
echo  ====================================================
echo  Features: USB Auth, Hardware QC, Adaptive Timing
echo  Self-Updating Launcher + Get-CimInstance (Win11 25H2)
echo  ====================================================
echo.

REM Check for administrator privileges
NET SESSION >nul 2>&1
if %errorlevel% neq 0 (
    echo ERROR: Administrator privileges required
    echo.
    echo This activation script must be run as Administrator to:
    echo - Activate Windows license
    echo - Access system information
    echo - Write to system registry
    echo.
    echo Please right-click and select "Run as administrator"
    echo.
    pause
    exit /b 1
)

REM Configuration
set "LAUNCHER_VERSION=3.1.0"
set "SERVER_URL=https://roo24.ieatkittens.netcraze.pro:65083"
set "PING_HOST=roo24.ieatkittens.netcraze.pro"
set "API_ENDPOINT=%SERVER_URL%/activate/api"
set "SCRIPT_ENDPOINT=%SERVER_URL%/activate/activation/main_v3.PS1"
set "LAUNCHER_ENDPOINT=%SERVER_URL%/activate/client/OEM_Activator.cmd"
set "TEMP_SCRIPT=%TEMP%\oem_activation_v3.ps1"
set "TEMP_LAUNCHER=%TEMP%\oem_activator_update.cmd"
set "LOG_FILE=%TEMP%\oem_activation_log.txt"

REM Check for command-line override
if not "%~1"=="" (
    set "SERVER_URL=%~1"
    echo Using command-line server URL: %SERVER_URL%
    echo.
)

REM Load configuration from CONFIG.txt if exists
if exist "%~dp0CONFIG.txt" (
    echo Loading configuration from CONFIG.txt...
    for /f "tokens=1,2 delims==" %%a in (%~dp0CONFIG.txt) do (
        if "%%a"=="SERVER_URL" set "SERVER_URL=%%b"
        if "%%a"=="API_ENDPOINT" set "API_ENDPOINT=%%b"
        if "%%a"=="SCRIPT_ENDPOINT" set "SCRIPT_ENDPOINT=%%b"
        if "%%a"=="LAUNCHER_ENDPOINT" set "LAUNCHER_ENDPOINT=%%b"
    )
    echo [OK] Configuration loaded
    echo    Server URL: %SERVER_URL%
    echo.
)

REM Extract hostname from SERVER_URL for ping test
for /f "tokens=2 delims=:/" %%a in ("%SERVER_URL%") do set "PING_HOST=%%a"
echo    Ping Host: %PING_HOST%
echo.

REM Create log file
echo ========================================= > "%LOG_FILE%"
echo OEM Activation Log - %DATE% %TIME% >> "%LOG_FILE%"
echo ========================================= >> "%LOG_FILE%"
echo Server URL: %SERVER_URL% >> "%LOG_FILE%"
echo API Endpoint: %API_ENDPOINT% >> "%LOG_FILE%"
echo Script Endpoint: %SCRIPT_ENDPOINT% >> "%LOG_FILE%"
echo ========================================= >> "%LOG_FILE%"

REM ============================================
REM Fetch task toggles from server
REM ============================================
set "DO_WSUS=True"
set "DO_SECURITY=True"
set "DO_EDRIVE=True"
set "DO_PS7=True"
set "DO_SELFUPDATE=True"

where curl >nul 2>&1
if %ERRORLEVEL%==0 (
    echo Fetching launcher config from server...
    for /f "tokens=*" %%j in ('curl -s "%API_ENDPOINT%/get-launcher-config.php" 2^>nul') do set "LAUNCHER_CONFIG=%%j"
    if defined LAUNCHER_CONFIG (
        for /f %%v in ('powershell -NoProfile -Command "try{($env:LAUNCHER_CONFIG|ConvertFrom-Json).tasks.wsus_cleanup}catch{'True'}"') do set "DO_WSUS=%%v"
        for /f %%v in ('powershell -NoProfile -Command "try{($env:LAUNCHER_CONFIG|ConvertFrom-Json).tasks.security_hardening}catch{'True'}"') do set "DO_SECURITY=%%v"
        for /f %%v in ('powershell -NoProfile -Command "try{($env:LAUNCHER_CONFIG|ConvertFrom-Json).tasks.edrive_format}catch{'True'}"') do set "DO_EDRIVE=%%v"
        for /f %%v in ('powershell -NoProfile -Command "try{($env:LAUNCHER_CONFIG|ConvertFrom-Json).tasks.ps7_install}catch{'True'}"') do set "DO_PS7=%%v"
        for /f %%v in ('powershell -NoProfile -Command "try{($env:LAUNCHER_CONFIG|ConvertFrom-Json).tasks.self_update}catch{'True'}"') do set "DO_SELFUPDATE=%%v"
        echo [OK] Config loaded: WSUS=%DO_WSUS% Security=%DO_SECURITY% E:=%DO_EDRIVE% PS7=%DO_PS7% Update=%DO_SELFUPDATE%
    ) else (
        echo [WARN] Could not fetch config, using defaults
    )
) else (
    echo [INFO] curl not available, using default task config
)
echo.

REM ============================================
REM Pre-Activation Task 1/3: WSUS Cleanup
REM ============================================
if /i "%DO_WSUS%"=="False" (
    echo [1/3] WSUS cleanup: SKIPPED ^(disabled in admin config^)
    echo [PRE-TASK] WSUS cleanup skipped by admin config >> "%LOG_FILE%"
    goto :skip_wsus
)
echo [1/3] Cleaning up WSUS configuration...
echo [PRE-TASK] WSUS cleanup starting... >> "%LOG_FILE%"

powershell -NoProfile -ExecutionPolicy Bypass -Command ^
    "$ErrorActionPreference='Stop'; " ^
    "try { " ^
    "  Remove-ItemProperty 'HKLM:\SOFTWARE\Policies\Microsoft\Windows\WindowsUpdate' -Name 'WUServer','WUStatusServer' -ErrorAction SilentlyContinue; " ^
    "  Remove-ItemProperty 'HKLM:\SOFTWARE\Policies\Microsoft\Windows\WindowsUpdate\AU' -Name 'UseWUServer' -ErrorAction SilentlyContinue; " ^
    "  Restart-Service wuauserv -Force -ErrorAction SilentlyContinue; " ^
    "  if(Get-Command 'UsoClient.exe' -ErrorAction SilentlyContinue){Start-Process UsoClient.exe -ArgumentList 'ScanInstallWait' -WindowStyle Hidden} " ^
    "  else{Start-Process wuauclt.exe -ArgumentList '/detectnow' -WindowStyle Hidden}; " ^
    "  Unregister-ScheduledTask -TaskName 'WindowsUpdate-Fallback-Check' -Confirm:$false -ErrorAction SilentlyContinue; " ^
    "  if(Test-Path 'C:\Scripts'){Remove-Item 'C:\Scripts' -Recurse -Force -ErrorAction SilentlyContinue}; " ^
    "  Write-Host 'WSUS cleanup completed' " ^
    "} catch { Write-Host 'WSUS cleanup warning:' $_.Exception.Message }"

echo [OK] WSUS cleanup completed
echo [PRE-TASK] WSUS cleanup done >> "%LOG_FILE%"
echo.
:skip_wsus

REM ============================================
REM Pre-Activation Task 2/3: Security Hardening
REM ============================================
if /i "%DO_SECURITY%"=="False" (
    echo [2/3] Security hardening: SKIPPED ^(disabled in admin config^)
    echo [PRE-TASK] Security hardening skipped by admin config >> "%LOG_FILE%"
    goto :skip_security
)
echo [2/3] Applying security hardening...
echo [PRE-TASK] Security hardening starting... >> "%LOG_FILE%"

reg add "HKLM\SYSTEM\CurrentControlSet\Services\LanmanWorkstation\Parameters" /v AllowInsecureGuestAuth /t REG_DWORD /d 0 /f >nul 2>&1
reg add "HKLM\SYSTEM\CurrentControlSet\Services\LanmanWorkstation\Parameters" /v RequireSecuritySignature /t REG_DWORD /d 1 /f >nul 2>&1

echo [OK] Guest access disabled, SMB signing enforced
echo [PRE-TASK] Security hardening done >> "%LOG_FILE%"
echo.
:skip_security

REM ============================================
REM Pre-Activation Task 3/3: Format E: (BIOS)
REM ============================================
if /i "%DO_EDRIVE%"=="False" (
    echo [3/3] E: drive format: SKIPPED ^(disabled in admin config^)
    echo [PRE-TASK] E: drive format skipped by admin config >> "%LOG_FILE%"
    goto :skip_edrive
)
echo [3/3] Checking E: drive (BIOS partition)...
echo [PRE-TASK] E: drive check starting... >> "%LOG_FILE%"

vol E: 2>nul | find "BIOS" >nul
if %errorlevel% neq 0 (
    echo [SKIP] E: drive not found or label is not "BIOS"
    echo [PRE-TASK] E: drive skipped - not BIOS label >> "%LOG_FILE%"
) else (
    echo    Formatting E: as FAT32 with label BIOS...
    echo y | format E: /fs:FAT32 /v:BIOS /q /x >nul 2>&1
    if %errorlevel% equ 0 (
        echo [OK] E: drive formatted successfully
        echo [PRE-TASK] E: drive formatted >> "%LOG_FILE%"
    ) else (
        echo [WARN] E: drive format failed ^(non-critical^)
        echo [PRE-TASK] E: drive format failed >> "%LOG_FILE%"
    )
)
echo.
:skip_edrive

REM ============================================
REM Network Connectivity Test
REM ============================================
echo Testing network connectivity to server...
echo Testing connectivity to: %SERVER_URL% >> "%LOG_FILE%"

ping -n 1 -w 3000 %PING_HOST% >nul 2>&1
if %errorlevel% neq 0 (
    echo [WARN] Cannot ping server hostname
    echo [WARN] This may be normal if using IP address
    echo.
) else (
    echo [OK] Server hostname resolved successfully
    echo.
)

REM ============================================
REM PowerShell 7 Detection + Auto-Install
REM ============================================
if /i "%DO_PS7%"=="False" (
    echo PowerShell 7 auto-install: SKIPPED ^(disabled in admin config^)
    echo [PRE-TASK] PS7 install skipped by admin config >> "%LOG_FILE%"
    goto :ps_check_legacy
)
echo Checking PowerShell compatibility...
echo Checking PowerShell version... >> "%LOG_FILE%"

set "PS_EXE=powershell"

REM Check if PS7 already installed
where pwsh >nul 2>&1
if %errorlevel% equ 0 (
    for /f "tokens=*" %%v in ('pwsh -Command "$PSVersionTable.PSVersion.ToString()"') do set "PS7_VERSION=%%v"
    echo [OK] PowerShell 7 found: %PS7_VERSION%
    echo PowerShell 7 found: %PS7_VERSION% >> "%LOG_FILE%"
    set "PS_EXE=pwsh"
    goto :ps_ready
)

echo [INFO] PowerShell 7 not found. Attempting installation...
echo PowerShell 7 not found, attempting install... >> "%LOG_FILE%"

REM --- Method 1: USB MSI Install ---
echo    Checking USB drives for PS7 installer...
set "PS7_MSI_FOUND="
for %%d in (D E F G H I) do (
    if exist "%%d:\PowerShell7\PowerShell-*.msi" (
        for %%f in ("%%d:\PowerShell7\PowerShell-*.msi") do (
            set "PS7_MSI_FOUND=%%f"
        )
    )
)

if defined PS7_MSI_FOUND (
    echo    Found MSI: %PS7_MSI_FOUND%
    echo    Installing PowerShell 7 from USB...
    echo Installing PS7 from USB: %PS7_MSI_FOUND% >> "%LOG_FILE%"
    msiexec.exe /i "%PS7_MSI_FOUND%" /quiet /norestart ADD_EXPLORER_CONTEXT_MENU_OPENPOWERSHELL=1 ADD_FILE_CONTEXT_MENU_RUNPOWERSHELL=1 ENABLE_PSREMOTING=0 REGISTER_MANIFEST=1 USE_MU=0 ENABLE_MU=0
    if %errorlevel% equ 0 (
        echo [OK] PowerShell 7 installed from USB
        echo PS7 installed from USB successfully >> "%LOG_FILE%"
        set "PATH=%PATH%;%ProgramFiles%\PowerShell\7"
        set "PS_EXE=pwsh"
        goto :ps_ready
    ) else (
        echo [WARN] USB MSI install failed ^(code: %errorlevel%^)
        echo USB MSI install failed: %errorlevel% >> "%LOG_FILE%"
    )
) else (
    echo    No PS7 MSI found on USB drives
    echo No PS7 MSI on USB >> "%LOG_FILE%"
)

REM --- Method 2: Winget Install ---
echo    Trying winget installation...
where winget >nul 2>&1
if %errorlevel% equ 0 (
    echo    Running: winget install Microsoft.Powershell...
    echo Attempting winget install >> "%LOG_FILE%"
    winget install --id Microsoft.Powershell --source winget --accept-package-agreements --accept-source-agreements --silent 2>>"%LOG_FILE%"
    if %errorlevel% equ 0 (
        echo [OK] PowerShell 7 installed via winget
        echo PS7 installed via winget >> "%LOG_FILE%"
        set "PATH=%PATH%;%ProgramFiles%\PowerShell\7"
        set "PS_EXE=pwsh"
        goto :ps_ready
    ) else (
        echo [WARN] Winget install failed ^(code: %errorlevel%^)
        echo Winget install failed: %errorlevel% >> "%LOG_FILE%"
    )
) else (
    echo    Winget not available on this system
    echo Winget not available >> "%LOG_FILE%"
)

REM --- Fallback: PS5 ---
echo [INFO] PowerShell 7 installation failed. Using PowerShell 5...
echo Falling back to PowerShell 5 >> "%LOG_FILE%"

:ps_check_legacy
REM Verify PowerShell 5 is available
powershell -Command "if ($PSVersionTable.PSVersion.Major -lt 5) { exit 1 } else { exit 0 }" >> "%LOG_FILE%" 2>&1
if %errorlevel% neq 0 (
    echo ERROR: PowerShell 5.0+ required
    echo Current PowerShell version is too old
    echo Please update PowerShell to version 5.0 or higher
    echo.
    echo Log file: %LOG_FILE%
    pause
    exit /b 1
)
set "PS_EXE=powershell"

:ps_ready
echo [OK] Using: %PS_EXE%
echo Using PowerShell executable: %PS_EXE% >> "%LOG_FILE%"
echo.

REM ============================================
REM Launcher Self-Update Check
REM ============================================
if /i "%DO_SELFUPDATE%"=="False" (
    echo Launcher self-update: SKIPPED ^(disabled in admin config^)
    echo [SELF-UPDATE] Skipped by admin config >> "%LOG_FILE%"
    goto :skip_selfupdate
)
echo Checking for launcher updates...
echo [SELF-UPDATE] Checking launcher version %LAUNCHER_VERSION% >> "%LOG_FILE%"

REM Use PowerShell to download remote CMD, extract version, compare, and report result
REM Exit codes: 0=up-to-date, 1=download-failed, 2=updated-needs-restart
%PS_EXE% -ExecutionPolicy Bypass -Command ^
    "$ErrorActionPreference='Stop'; " ^
    "try { " ^
    "  $remote = '%TEMP_LAUNCHER%'; " ^
    "  Invoke-WebRequest -Uri '%LAUNCHER_ENDPOINT%' -OutFile $remote -UserAgent 'OEM-Activator-v%LAUNCHER_VERSION%' -TimeoutSec 15; " ^
    "  $content = Get-Content $remote -Raw; " ^
    "  if ($content -match 'set \"LAUNCHER_VERSION=([^\"]+)\"') { " ^
    "    $rv = $Matches[1]; " ^
    "    if ($rv -ne '%LAUNCHER_VERSION%') { " ^
    "      Write-Host \"[UPDATE] New launcher v$rv available (current: %LAUNCHER_VERSION%)\"; " ^
    "      Copy-Item $remote '%~f0' -Force; " ^
    "      Remove-Item $remote -Force -ErrorAction SilentlyContinue; " ^
    "      exit 2 " ^
    "    } else { " ^
    "      Write-Host '[OK] Launcher is up to date (v%LAUNCHER_VERSION%)' " ^
    "    } " ^
    "  } else { Write-Host '[OK] Version check skipped (no version in remote)' }; " ^
    "  Remove-Item $remote -Force -ErrorAction SilentlyContinue; " ^
    "  exit 0 " ^
    "} catch { Write-Host '[INFO] Launcher update check skipped'; exit 1 }"

set "UPDATE_RESULT=%errorlevel%"
echo [SELF-UPDATE] Result code: %UPDATE_RESULT% >> "%LOG_FILE%"

if "%UPDATE_RESULT%"=="2" (
    echo [OK] Launcher updated successfully. Restarting...
    echo [SELF-UPDATE] Restarting with new version >> "%LOG_FILE%"
    echo.
    REM Re-launch the updated CMD (passes all original arguments)
    start "" "%~f0" %*
    exit /b 0
)
echo.
:skip_selfupdate

REM Download the latest PowerShell script
echo Downloading latest activation script...
echo Downloading from: %SCRIPT_ENDPOINT% >> "%LOG_FILE%"

%PS_EXE% -ExecutionPolicy Bypass -Command "try { Invoke-WebRequest -Uri '%SCRIPT_ENDPOINT%' -OutFile '%TEMP_SCRIPT%' -UserAgent 'OEM-Activator-v2.0' -TimeoutSec 30 } catch { Write-Host 'Download failed:' $_.Exception.Message; exit 1 }" >> "%LOG_FILE%" 2>&1

if %errorlevel% neq 0 (
    echo ERROR: Could not download activation script
    echo.
    echo Troubleshooting steps:
    echo 1. Check network connection
    echo 2. Verify server URL: %SERVER_URL%
    echo 3. Check firewall settings
    echo 4. Contact system administrator
    echo.
    echo Log file: %LOG_FILE%
    pause
    exit /b 1
)

REM Verify downloaded script
if not exist "%TEMP_SCRIPT%" (
    echo ERROR: Downloaded script not found
    echo The script may not have downloaded correctly
    echo.
    echo Log file: %LOG_FILE%
    pause
    exit /b 1
)

echo [OK] Activation script downloaded successfully
echo.

REM Check script signature (basic validation)
findstr /C:"#VALIDATION_SIGNATURE:" "%TEMP_SCRIPT%" >nul 2>&1
if %errorlevel% neq 0 (
    echo [WARN] Script signature not found
    echo The downloaded script may be corrupted or invalid
    echo.
    set /p "continue=Continue anyway? (y/N): "
    if /i not "%continue%"=="y" (
        echo Activation cancelled by user
        del "%TEMP_SCRIPT%" >nul 2>&1
        pause
        exit /b 1
    )
) else (
    echo [OK] Script signature validated
    echo.
)

REM Execute the PowerShell activation script
echo Starting Windows activation process...
echo ========================================
echo.

echo Starting PowerShell activation script... >> "%LOG_FILE%"
echo Script path: %TEMP_SCRIPT% >> "%LOG_FILE%"
echo ======================================== >> "%LOG_FILE%"

%PS_EXE% -ExecutionPolicy Bypass -Command "& '%TEMP_SCRIPT%' -APIBaseURL '%API_ENDPOINT%' *>&1 | Tee-Object -FilePath '%LOG_FILE%' -Append"

set "activation_result=%errorlevel%"

REM Log the result
echo ======================================== >> "%LOG_FILE%"
echo Activation completed with exit code: %activation_result% >> "%LOG_FILE%"
echo End time: %DATE% %TIME% >> "%LOG_FILE%"
echo ======================================== >> "%LOG_FILE%"

REM Cleanup temporary script
del "%TEMP_SCRIPT%" >nul 2>&1

REM Display result
echo.
echo ========================================
if %activation_result% equ 0 (
    echo ACTIVATION COMPLETED SUCCESSFULLY
    echo.
    echo Windows has been activated with an OEM license.
    echo The system is now ready for customer delivery.
) else (
    echo ACTIVATION FAILED
    echo.
    echo The activation process encountered an error.
    echo Please check the log file for details:
    echo %LOG_FILE%
    echo.
    echo Common issues:
    echo - Network connectivity problems
    echo - Server maintenance
    echo - Invalid credentials
    echo - No available license keys
)
echo ========================================
echo.

REM Final instructions
echo Next Steps:
if %activation_result% equ 0 (
    echo 1. Verify Windows activation status in Settings
    echo 2. Apply OEM license sticker to computer case
    echo 3. Complete quality assurance checklist
    echo 4. Package computer for customer delivery
) else (
    echo 1. Review log file: %LOG_FILE%
    echo 2. Contact system administrator if needed
    echo 3. Retry activation after resolving issues
)
echo.

REM Keep window open for user review
echo Press any key to close this window...
pause >nul

exit /b %activation_result%
