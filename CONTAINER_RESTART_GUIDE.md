# Container Restart Issue - Solution Guide

**Issue**: Docker containers stop after code updates
**Status**: Auto-restart policy configured ✅

---

## 🔍 WHY THIS HAPPENS

### Common Causes

1. **Docker Desktop Restarts**
   - Docker Desktop updates or restarts
   - System hibernation/sleep
   - Manual Docker service restart

2. **Code Changes Triggering Restart**
   - Volume-mounted files being modified
   - File watchers causing container reload
   - PHP-FPM restarts

3. **Resource Limits**
   - Containers hitting memory limits
   - Docker Desktop resource constraints
   - OOM (Out of Memory) killer

4. **Signal Handling**
   - SIGWINCH, SIGTERM signals
   - Docker Compose stop/restart
   - System shutdown signals

---

## ✅ SOLUTIONS IMPLEMENTED

### 1. Auto-Restart Policy

**Status**: ✅ Configured

Containers now auto-restart when Docker starts:

```bash
docker update --restart=unless-stopped oem-activation-web
docker update --restart=unless-stopped oem-activation-db
docker update --restart=unless-stopped oem-activation-redis
```

**Restart Policies**:
- `unless-stopped`: Restart unless manually stopped
- `always`: Always restart (even after manual stop)
- `on-failure`: Only restart on error
- `no`: Never auto-restart

### 2. Quick Restart Scripts

**Windows CMD**: `restart-containers.cmd`
```cmd
restart-containers.cmd
```

**PowerShell**: `restart-containers.ps1`
```powershell
.\restart-containers.ps1
```

**Git Bash/Linux**:
```bash
docker start oem-activation-db oem-activation-web oem-activation-redis
```

---

## 🚀 QUICK FIX

### When Containers Stop

**Option 1**: Double-click `restart-containers.cmd`

**Option 2**: Run PowerShell command:
```powershell
docker start oem-activation-db oem-activation-web oem-activation-redis
```

**Option 3**: Docker Desktop GUI:
1. Open Docker Desktop
2. Go to Containers tab
3. Click ▶️ Start button on stopped containers

---

## 🔧 PERMANENT SOLUTIONS

### Solution 1: Use Docker Compose with Auto-Restart

Update `docker-compose.yml`:

```yaml
services:
  oem-activation-web:
    restart: unless-stopped
    # ... rest of config

  oem-activation-db:
    restart: unless-stopped
    # ... rest of config

  oem-activation-redis:
    restart: unless-stopped
    # ... rest of config
```

**Apply changes**:
```bash
docker-compose down
docker-compose up -d
```

### Solution 2: Enable Docker Auto-Start on Windows Boot

1. Open Services (Win+R → `services.msc`)
2. Find "Docker Desktop Service"
3. Double-click → Set "Startup type" to "Automatic"
4. Click OK

### Solution 3: Create Windows Startup Task

Create a scheduled task that starts containers on login:

1. Open Task Scheduler
2. Create Basic Task
3. Trigger: "At log on"
4. Action: "Start a program"
5. Program: `powershell.exe`
6. Arguments: `-File "C:\Path\To\restart-containers.ps1"`

---

## 📊 MONITORING

### Check Container Status

**Quick Check**:
```bash
docker ps
```

**Detailed Status**:
```bash
docker ps -a --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"
```

**Check Logs**:
```bash
docker logs oem-activation-web --tail 50
docker logs oem-activation-db --tail 50
```

**Check Restart Count**:
```bash
docker inspect oem-activation-web | grep -i restart
```

### Health Checks

**Web Container**:
```bash
curl http://localhost:8080/admin_v2.php
# Should return 302 (redirect) or 200
```

**Database Container**:
```bash
docker exec oem-activation-db mariadb -uroot -proot_password_123 -e "SELECT 1;"
# Should return 1
```

**Redis Container**:
```bash
docker exec oem-activation-redis redis-cli -a redis_password_123 ping
# Should return PONG
```

---

## 🐛 TROUBLESHOOTING

### Issue: Containers Immediately Stop After Start

**Diagnosis**:
```bash
docker logs oem-activation-web --tail 100
docker inspect oem-activation-web
```

**Common Causes**:
- Port already in use (8080, 3306, 6379)
- Configuration file errors
- Missing environment variables
- Volume mount issues

**Solution**:
```bash
# Check port conflicts
netstat -ano | findstr :8080
netstat -ano | findstr :3306

# Kill processes using ports (if needed)
taskkill /PID <PID> /F

# Restart containers
docker start oem-activation-db oem-activation-web
```

### Issue: Database Container Won't Start

**Symptoms**: MariaDB fails to start, exits immediately

**Check**:
```bash
docker logs oem-activation-db
```

**Common Errors**:
- Database corruption
- Insufficient disk space
- Port 3306 in use

**Solution**:
```bash
# Check disk space
df -h  # Linux/Mac
wmic logicaldisk get size,freespace,caption  # Windows

# Check port
netstat -ano | findstr :3306

# Force recreate
docker-compose up -d --force-recreate oem-activation-db
```

### Issue: Web Container PHP Errors

**Symptoms**: 500 errors, container restarts repeatedly

**Check Syntax**:
```bash
docker exec oem-activation-web php -l /var/www/html/activate/admin_v2.php
```

**Check Logs**:
```bash
docker logs oem-activation-web --tail 100 | grep -i error
```

**Solution**:
- Fix PHP syntax errors
- Check file permissions
- Verify config.php database credentials

---

## 📝 BEST PRACTICES

### 1. Before Code Changes

**Create checkpoint**:
```bash
docker commit oem-activation-web oem-web-backup
docker commit oem-activation-db oem-db-backup
```

### 2. After Code Changes

**Syntax check**:
```bash
docker exec oem-activation-web php -l /var/www/html/activate/admin_v2.php
```

**Restart containers**:
```bash
.\restart-containers.ps1
```

**Verify functionality**:
```bash
curl -I http://localhost:8080/admin_v2.php
```

### 3. Regular Maintenance

**Weekly**:
- Check container logs for errors
- Verify database backups exist
- Check disk space

**Monthly**:
- Update Docker images
- Review container resource usage
- Clean up old images/volumes

---

## 🎯 AUTOMATION OPTIONS

### Option 1: Windows Service Wrapper

Use NSSM (Non-Sucking Service Manager):

```powershell
# Install NSSM
choco install nssm

# Create service
nssm install DockerContainerStarter powershell.exe
nssm set DockerContainerStarter AppParameters "-File C:\Path\To\restart-containers.ps1"
nssm set DockerContainerStarter Start SERVICE_AUTO_START
```

### Option 2: Scheduled Task (Every 5 Minutes)

```powershell
# Create scheduled task
$action = New-ScheduledTaskAction -Execute "powershell.exe" -Argument "-File C:\Path\To\restart-containers.ps1"
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 5)
Register-ScheduledTask -TaskName "OEM-Container-Monitor" -Action $action -Trigger $trigger
```

### Option 3: Docker Compose Watch (Development)

For development with auto-reload:

```yaml
services:
  oem-activation-web:
    develop:
      watch:
        - action: sync
          path: ./FINAL_PRODUCTION_SYSTEM
          target: /var/www/html/activate
```

---

## 📞 QUICK REFERENCE

### One-Line Fixes

**Restart all containers**:
```bash
docker start oem-activation-db oem-activation-web oem-activation-redis
```

**Restart with logs**:
```bash
docker start oem-activation-web && docker logs -f oem-activation-web
```

**Force recreate**:
```bash
docker-compose up -d --force-recreate
```

**Check if running**:
```bash
docker ps | grep oem-activation
```

**Access admin panel**:
```bash
start http://localhost:8080/admin_v2.php
```

---

## ✅ VERIFICATION CHECKLIST

After restarting containers:

- [ ] Web container running (docker ps shows "Up")
- [ ] Database container healthy
- [ ] Redis container healthy
- [ ] Admin panel loads (http://localhost:8080/admin_v2.php)
- [ ] Can login to admin panel
- [ ] Dashboard shows statistics
- [ ] No JavaScript errors in browser console
- [ ] Database connection works
- [ ] API endpoints respond

---

**Status**: Auto-restart configured ✅
**Scripts**: Available in project root
**Next**: Containers should auto-restart after Docker restarts
