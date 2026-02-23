@echo off
echo Building OEM Hardware Bridge Native Application...
echo.

REM Check if .NET SDK is installed
dotnet --version >nul 2>&1
if errorlevel 1 (
    echo Error: .NET SDK not found!
    echo Please install .NET 6.0 SDK or later from: https://dotnet.microsoft.com/download
    pause
    exit /b 1
)

echo Building self-contained executable...
dotnet publish -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true -p:PublishTrimmed=false

if errorlevel 1 (
    echo.
    echo Build failed!
    pause
    exit /b 1
)

echo.
echo Build successful!
echo Output: bin\Release\net6.0-windows\win-x64\publish\OEMHardwareBridge.exe
echo.

REM Copy to current directory for easy installation
copy bin\Release\net6.0-windows\win-x64\publish\OEMHardwareBridge.exe . >nul 2>&1

if exist OEMHardwareBridge.exe (
    echo Executable copied to current directory.
    echo.
    echo Next steps:
    echo 1. Run install.ps1 as Administrator to install the native app
    echo 2. Load the extension in Chrome/Edge from the 'extension' folder
    echo 3. Update the manifest with your extension ID
) else (
    echo Warning: Could not copy executable to current directory.
)

echo.
pause
