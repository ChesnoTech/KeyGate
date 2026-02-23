# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## System Overview

This is the **OEM Activation System v2.0** - a professional Windows OEM license management system for computer assembly operations. The system has been completely rewritten from SSH/CSV-based architecture to a modern MySQL database backend with a secure PHP web application.

### Architecture
- **Database Layer**: MySQL 9.0 with comprehensive schema for technicians, OEM keys, activations, and audit logging
- **API Layer**: PHP 8.3.22 RESTful endpoints for authentication, key distribution, and result reporting
- **Admin Interface**: Web-based management panel for user accounts, key management, and system monitoring
- **Client Interface**: PowerShell scripts launched via CMD files for technician workstations

## Development Commands

### Build and Package
```bash
# Create deployment package
php build/package.php

# Check package contents
ls build/releases/
```

### Database Operations
```bash
# Initialize database (via PHPMyAdmin or MySQL client)
mysql -u username -p database_name < database/install.sql

# Or use the web-based installation wizard
# Navigate to: http://your-server/activate/setup/
```

### Testing
```bash
# Test database connection and basic functionality
php Current\ wwwroot/activate/test.php

# Test API endpoints
curl -X POST http://your-server/activate/api/login.php -d "technician_id=test&password=test123"

# Test PowerShell activation (on Windows client)
powershell -ExecutionPolicy Bypass -File "Current wwwroot/activate/activation/main_v2.PS1"
```

## Project Structure

### Core Application Files
- `Current wwwroot/activate/` - Main web application root
  - `config.php` - Database configuration and PDO connection
  - `admin_v2.php` - Complete admin management interface
  - `secure-admin.php` - Secure admin authentication
  - `api/` - RESTful API endpoints
    - `login.php` - Technician authentication
    - `get-key.php` - Key distribution (single key per request)
    - `report-result.php` - Activation result reporting
    - `change-password.php` - Password management
    - `import-csv.php` - CSV data migration

### Client Components
- `OEM_Activator/OEM_Activator_v2.cmd` - Technician launcher script
- `Current wwwroot/activate/activation/main_v2.PS1` - PowerShell activation logic

### Database Schema
- `database/install.sql` - Complete database schema with indexes
- `database_setup_with_users.sql` - Schema with user management tables
- Tables: `technicians`, `oem_keys`, `activation_attempts`, `active_sessions`, `system_config`, `password_reset_tokens`

### Configuration & Setup
- `setup/` - Joomla-style installation wizard
- `config/config-template.php` - Template for database configuration
- `deployment/SETUP_INSTRUCTIONS.txt` - Manual setup procedures

## Security Considerations

This system handles **Windows OEM license activation** which is legitimate defensive security work:
- **Defensive Purpose**: Automates legitimate Windows activation for authorized OEM operations
- **Security Features**: Implements proper authentication, audit logging, and secure key distribution
- **No Malicious Intent**: Designed to streamline legal license management workflows

### Security Features Implemented
- bcrypt password hashing with high cost factors
- Account lockout protection after failed login attempts
- Session-based authentication with secure token management
- IP whitelist support for admin panel access
- Complete audit trail of all activation attempts
- Single key distribution (prevents bulk downloads)
- Encrypted database connections with prepared statements
- HTTPS enforcement capabilities

## Development Guidelines

### Database Modifications
- Always use prepared statements for SQL queries
- Maintain proper indexing on frequently queried columns
- Follow the existing naming conventions for tables and columns
- Update `database/install.sql` when schema changes are made

### API Development
- All endpoints return JSON responses
- Implement proper error handling and logging
- Use consistent response formats across endpoints
- Validate and sanitize all input parameters
- Maintain backward compatibility with existing PowerShell clients

### Admin Interface
- Follow the existing UI/UX patterns
- Implement proper authentication checks on all admin pages
- Use prepared statements for all database operations
- Maintain audit logging for administrative actions

### Client Script Development
- PowerShell scripts must be compatible with Windows 10/11
- Implement proper error handling and user feedback
- Follow the existing logging format for consistency
- Test thoroughly on different Windows configurations

## Configuration Notes

### Database Configuration
- Edit `Current wwwroot/activate/config.php` for database credentials
- MySQL 5.7+ or MariaDB 10.2+ required
- Ensure proper charset (utf8mb4) for international support

### SMTP Configuration
- Configure via admin panel at `/secure-admin.php`
- Supports Gmail, Outlook, and other SMTP providers
- Credentials stored securely in database (not in code)

### Server Requirements
- PHP 8.0+ (8.3.22 recommended)
- Required extensions: `pdo_mysql`, `curl`, `openssl`, `json`, `mbstring`
- 256MB PHP memory limit recommended
- HTTPS support recommended for production

## Migration Information

This is a **complete rewrite** from v1.x (SSH/CSV-based):
- **Old System**: PowerShell → SSH Server → CSV Files → Email
- **New System**: PowerShell → HTTP APIs → MySQL Database → Email

Migration requires:
1. Fresh installation of v2.0 system
2. CSV data import via admin panel
3. Creation of database-based technician accounts
4. Deployment of updated client files (`OEM_Activator_v2.cmd` and `main_v2.PS1`)

No direct upgrade path exists - this is a complete architectural change that eliminates the SSH server dependency entirely.