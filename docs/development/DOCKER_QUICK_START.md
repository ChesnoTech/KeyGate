> **⚠️ Historical Document** — References to `main_v2.PS1` and `OEM_Activator_v2.cmd` are outdated.
> Current files: `activation/main_v3.PS1` and `client/OEM_Activator.cmd`. v2 was retired March 2026.

# Docker Quick Start Guide - KeyGate v3.0

## Prerequisites
- ✅ Docker Desktop installed and running
- ✅ Windows 10/11 with WSL2 enabled

## Quick Start (3 Commands)

```bash
# 1. Navigate to project directory
cd C:\Users\ChesnoTechAdmin\OEM_Activation_System

# 2. Start all containers
docker-compose up -d

# 3. Check status
docker-compose ps
```

## Access Points

| Service | URL | Purpose |
|---------|-----|---------|
| **Web App** | http://localhost:8080/ | Main application |
| **Setup Wizard** | http://localhost:8080/setup/ | Initial setup |
| **Admin Panel** | http://localhost:8080/secure-admin.php | Administration |
| **PHPMyAdmin** | http://localhost:8081/ | Database management |
| **API Base** | http://localhost:8080/api/ | REST API endpoints |

## Database Credentials

**For Docker Environment:**
```
Host: db (from within containers) or localhost:3306 (from host)
Database: oem_activation
Username: oem_user
Password: oem_pass_456

Root Password: root_password_123
```

**For Setup Wizard:**
```
Database Host: db
Database Name: oem_activation
Database User: oem_user
Database Password: oem_pass_456
```

## Common Commands

### Container Management

```bash
# Start containers
docker-compose up -d

# Stop containers (preserves data)
docker-compose stop

# Restart containers
docker-compose restart

# View container status
docker-compose ps

# View logs
docker-compose logs -f

# Stop and remove containers (keeps volumes)
docker-compose down

# Complete cleanup (removes everything including database data)
docker-compose down -v
```

### Individual Container Commands

```bash
# View web server logs
docker logs -f oem-activation-web

# View database logs
docker logs -f oem-activation-db

# Access web container shell
docker exec -it oem-activation-web bash

# Access database
docker exec -it oem-activation-db mariadb -uroot -proot_password_123 oem_activation

# Check PHP version
docker exec oem-activation-web php -v

# Check installed PHP modules
docker exec oem-activation-web php -m
```

### Database Operations

```bash
# Access database directly
docker exec -it oem-activation-db mariadb -uroot -proot_password_123 oem_activation

# Export database
docker exec oem-activation-db mariadb-dump -uroot -proot_password_123 oem_activation > backup.sql

# Import database
docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation < backup.sql

# Reset database
docker exec oem-activation-db mariadb -uroot -proot_password_123 \
  -e "DROP DATABASE IF EXISTS oem_activation; CREATE DATABASE oem_activation CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation \
  < FINAL_PRODUCTION_SYSTEM/database/install.sql
```

### File Operations

```bash
# Copy file into container
docker cp localfile.php oem-activation-web:/var/www/html/activate/

# Copy file from container
docker cp oem-activation-web:/var/www/html/activate/config.php ./config_backup.php

# View file permissions
docker exec oem-activation-web ls -la /var/www/html/activate/

# Fix file permissions
docker exec oem-activation-web chown -R www-data:www-data /var/www/html/activate/
```

## Testing API Endpoints

```bash
# Test login
curl -X POST http://localhost:8080/api/login.php \
  -d "technician_id=TEST001&password=testpass"

# Test with verbose output
curl -v -X POST http://localhost:8080/api/login.php \
  -d "technician_id=TEST001&password=testpass"

# Test get-key (replace TOKEN with actual session token)
curl -X POST http://localhost:8080/api/get-key.php \
  -d "session_token=TOKEN&order_number=ORDER001"

# Test report-result
curl -X POST http://localhost:8080/api/report-result.php \
  -d "session_token=TOKEN&result=success&details=Test+activation"
```

## Testing Client Scripts

### Update CONFIG.txt for Docker

```cmd
# In FINAL_PRODUCTION_SYSTEM\client\CONFIG.txt:
SERVER_URL=http://localhost:8080
API_ENDPOINT=http://localhost:8080/api
SCRIPT_ENDPOINT=http://localhost:8080/activation/main_v2.PS1
```

### Run Client Scripts

```cmd
# Test with CONFIG.txt
FINAL_PRODUCTION_SYSTEM\client\OEM_Activator_v2.cmd

# Test with command-line override
FINAL_PRODUCTION_SYSTEM\client\OEM_Activator_v2.cmd http://localhost:8080

# Test PowerShell directly
powershell -ExecutionPolicy Bypass -Command "& 'FINAL_PRODUCTION_SYSTEM\activation\main_v2.PS1' -APIBaseURL 'http://localhost:8080/api'"
```

## Troubleshooting

### Containers won't start

```bash
# Check Docker Desktop is running
docker ps

# View detailed errors
docker-compose logs

# Rebuild containers
docker-compose down
docker-compose build --no-cache
docker-compose up -d
```

### Database connection errors

```bash
# Check if database is healthy
docker-compose ps

# Verify database is running
docker exec oem-activation-db mariadb -uroot -proot_password_123 -e "SELECT 1;"

# Check database exists
docker exec oem-activation-db mariadb -uroot -proot_password_123 -e "SHOW DATABASES;"

# Recreate database
docker-compose down
docker volume rm oem_activation_system_mariadb-data
docker-compose up -d
```

### Web server 404 errors

```bash
# Check if files are mounted correctly
docker exec oem-activation-web ls -la /var/www/html/activate/

# Check Apache configuration
docker exec oem-activation-web cat /etc/apache2/sites-available/000-default.conf

# Restart web server
docker-compose restart web
```

### File permission issues

```bash
# Fix permissions
docker exec oem-activation-web chown -R www-data:www-data /var/www/html/activate/
docker exec oem-activation-web chmod -R 755 /var/www/html/activate/
docker exec oem-activation-web chmod -R 775 /var/www/html/activate/logs/
```

## Performance Tuning

### View container resource usage

```bash
docker stats
```

### Increase MariaDB buffer pool

Edit `docker-compose.yml` and add to db service command:
```yaml
--innodb_buffer_pool_size=512M
```

### Enable PHP OPcache status

```bash
docker exec oem-activation-web php -r "print_r(opcache_get_status());"
```

## Development Workflow

### Make changes and reload

```bash
# PHP/HTML changes - no restart needed (files are mounted)
# Just refresh browser

# Apache config changes
docker-compose restart web

# Database schema changes
docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation < new_schema.sql

# Docker config changes (docker-compose.yml or Dockerfile)
docker-compose down
docker-compose build
docker-compose up -d
```

## Production Deployment Notes

**DO NOT use this Docker setup for production!**

This Docker environment is for:
- ✅ Development
- ✅ Testing
- ✅ Bug verification
- ❌ NOT for production

For production:
1. Use dedicated web server (Apache/Nginx) with PHP 8.3+
2. Use dedicated MariaDB 10.11 LTS server
3. Enable HTTPS with valid SSL certificates
4. Configure proper firewall rules
5. Set secure database passwords
6. Enable PHP opcache and other optimizations
7. Configure regular backups
8. Use environment variables for credentials (not hardcoded)

## Additional Resources

- **Project Documentation:** README.md
- **Testing Summary:** TESTING_SUMMARY.md
- **Database Schema:** FINAL_PRODUCTION_SYSTEM/database/install.sql
- **API Documentation:** (Create if needed)
- **Docker Logs:** `docker-compose logs -f`

## Support

For issues with:
- **Docker setup:** Check Docker Desktop status and logs
- **Bug fixes:** Review TESTING_SUMMARY.md
- **Database:** Access PHPMyAdmin at http://localhost:8081/
- **Application:** Check logs in FINAL_PRODUCTION_SYSTEM/logs/
