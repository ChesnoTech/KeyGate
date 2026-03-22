@echo off
REM Quick restart script for KeyGate containers
REM Run this after code updates to ensure containers are running

echo ========================================
echo KeyGate - Container Restart
echo ========================================
echo.

echo Checking container status...
docker ps -a --format "table {{.Names}}\t{{.Status}}"
echo.

echo Starting stopped containers...
docker start oem-activation-db oem-activation-web oem-activation-redis
echo.

echo Waiting for containers to initialize...
timeout /t 5 /nobreak >nul
echo.

echo Current container status:
docker ps --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"
echo.

echo ========================================
echo Containers restarted successfully!
echo ========================================
echo.
echo You can now access:
echo - Admin Panel: http://localhost:8080/admin_v2.php
echo - PHPMyAdmin: http://localhost:8081
echo.

pause
