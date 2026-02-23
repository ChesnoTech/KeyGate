#!/usr/bin/env powershell
# Quick restart script for OEM Activation System containers
# Run this after code updates to ensure containers are running

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "OEM Activation System - Container Restart" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

Write-Host "Checking container status..." -ForegroundColor Yellow
docker ps -a --format "table {{.Names}}\t{{.Status}}"
Write-Host ""

Write-Host "Starting stopped containers..." -ForegroundColor Yellow
docker start oem-activation-db oem-activation-web oem-activation-redis
Write-Host ""

Write-Host "Waiting for containers to initialize..." -ForegroundColor Yellow
Start-Sleep -Seconds 5
Write-Host ""

Write-Host "Current container status:" -ForegroundColor Green
docker ps --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"
Write-Host ""

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Containers restarted successfully!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "You can now access:" -ForegroundColor White
Write-Host "- Admin Panel: http://localhost:8080/admin_v2.php" -ForegroundColor White
Write-Host "- PHPMyAdmin: http://localhost:8081" -ForegroundColor White
Write-Host ""
