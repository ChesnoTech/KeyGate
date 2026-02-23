# 🔐 OEM Activation System v2.0

**Professional Windows OEM License Management Platform**

A secure, database-driven system for managing Windows OEM license keys with automated distribution, technician management, and comprehensive audit logging.

---

## 🚀 Quick Start

### Installation
1. Upload all files to your web server
2. Navigate to `/setup` to run the installation wizard
3. Follow the guided setup process
4. Access admin panel at `/secure-admin` (clean URL) or `/secure-admin.php`

### System URLs
- **Homepage**: `/` 
- **Admin Panel**: `/secure-admin` or `/secure-admin.php`
- **Setup Wizard**: `/setup`
- **API Endpoints**: `/api/`
- **Client Download**: `/client/`

---

## 📁 Directory Structure

```
OEM_Activation_System/
├── 📄 secure-admin.php          # Admin panel (main interface)
├── 📄 config.php                # Database configuration
├── 📄 index.html                # Landing page
├── 📄 .htaccess                 # URL rewriting & security
├── 📄 404.html                  # Custom error page
├── 📄 502.html                  # Server error page
├── 📄 composer.json             # PHP dependencies
├── 📄 install_phpmailer.php     # PHPMailer installer
├── 📄 verify_deployment.php     # Deployment verification
├── 📄 security-headers.php      # Security headers
├── 📄 config-production.php     # Production config template
├── 🗂️ api/                      # RESTful API endpoints
│   ├── login.php                # Technician authentication
│   ├── get-key.php              # Key distribution
│   ├── report-result.php        # Result reporting
│   ├── change-password.php      # Password management
│   └── import-csv.php           # CSV data migration
├── 🗂️ setup/                    # Installation wizard
│   ├── index.php                # Setup entry point
│   ├── diagnostics.php          # System diagnostics
│   └── steps/                   # Installation steps
├── 🗂️ activation/               # PowerShell scripts
│   └── main_v2.PS1              # Client activation logic
├── 🗂️ database/                 # Database files
│   ├── install.sql              # Main database schema
│   ├── database_setup.sql       # Core setup
│   ├── database_admin_security.sql  # Admin security
│   ├── database_concurrency_indexes.sql  # Performance
│   └── examples/                # Sample CSV files
├── 🗂️ client/                   # Client distribution
│   ├── OEM_Activator_v2.cmd     # Technician launcher
│   ├── CONFIG.txt               # Client configuration
│   └── README_TECHNICIAN.md     # Technician guide
├── 🗂️ logs/                     # Log directory
├── 🗂️ tmp/                      # Temporary files
└── 🗂️ uploads/                  # Upload directory
```

---

## ⚙️ System Requirements

### Server Requirements
- **PHP**: 8.0+ (8.3+ recommended)
- **Database**: MySQL 5.7+ or MariaDB 10.2+
- **Web Server**: Apache with mod_rewrite
- **Memory**: 256MB+ recommended
- **Storage**: 100MB+ available space

### Required PHP Extensions
- `pdo_mysql` - Database connectivity
- `curl` - HTTP requests
- `openssl` - Encryption and HTTPS
- `json` - API communication
- `mbstring` - String handling
- `zip` - PHPMailer installation

### Client Requirements
- **OS**: Windows 10/11
- **PowerShell**: 5.1+ (built-in)
- **Network**: HTTPS connectivity to server
- **Permissions**: Administrator rights for activation

---

## 🔧 Configuration

### Database Setup
1. Create MySQL database and user
2. Run installation wizard at `/setup`
3. Or manually import `database/install.sql`

### SMTP Configuration
Configure email notifications via admin panel:
- **Gmail**: smtp.gmail.com:587 (TLS)
- **Outlook**: smtp-mail.outlook.com:587 (STARTTLS)
- **Custom**: Your SMTP server settings

### Security Configuration
- Enable HTTPS in production
- Configure IP whitelist (optional)
- Set strong admin passwords
- Review file permissions
- Secure installation directory after setup

---

## 🔐 Security Features

### Authentication & Authorization
- bcrypt password hashing (cost factor 12)
- Account lockout protection (3 failed attempts)
- Session timeout (30 minutes default)
- Role-based access control
- Multi-factor authentication ready

### Data Protection
- Encrypted database connections
- SQL injection protection (prepared statements)
- XSS protection headers
- CSRF token validation
- Input sanitization and validation

### Audit & Monitoring
- Complete audit trail of all actions
- Real-time session monitoring
- Failed login attempt tracking
- Key distribution logging
- Admin activity logs

### Network Security
- HTTPS enforcement
- Security headers (HSTS, CSP, X-Frame-Options)
- IP whitelist support
- Request rate limiting
- Secure cookie settings

---

## 📊 Features

### Key Management
- **Bulk Import**: CSV file import with validation
- **Status Tracking**: unused, good, bad, retry states
- **Atomic Operations**: Concurrent access protection
- **Smart Distribution**: Single key per request
- **Recycling Rules**: Automatic key lifecycle management

### Technician Management
- **User Accounts**: Individual technician profiles
- **Password Policies**: Enforced complexity and rotation
- **Activity Tracking**: Complete usage history
- **Session Management**: Secure authentication tokens
- **Bulk Operations**: Mass user management

### Reporting & Analytics
- **Usage Statistics**: Key distribution metrics
- **Success Rates**: Activation success tracking
- **Technician Performance**: Individual statistics
- **Audit Reports**: Comprehensive activity logs
- **Export Capabilities**: CSV/PDF report generation

### API Integration
- **RESTful APIs**: Modern HTTP-based interface
- **JSON Responses**: Structured data exchange
- **Authentication**: Token-based security
- **Rate Limiting**: Request throttling
- **Documentation**: Complete API reference

---

## 🌐 Clean URLs

The system supports clean URLs without `.php` extensions:

### Available Clean URLs
- `/secure-admin` → `secure-admin.php`
- `/admin` → `secure-admin.php`
- `/setup` → `setup/index.php`
- `/verify` → `verify_deployment.php`
- `/install-phpmailer` → `install_phpmailer.php`

### API Endpoints (Keep .php)
- `/api/login.php`
- `/api/get-key.php`
- `/api/report-result.php`
- `/api/change-password.php`
- `/api/import-csv.php`

### Automatic Redirects
- Old `.php` URLs redirect to clean URLs (301)
- Common mistyped URLs auto-redirect
- Legacy `webroot/` paths redirect to new structure

---

## 🔄 Migration from v1.x

### Architecture Changes
- **Old**: PowerShell → SSH → CSV Files → Email
- **New**: PowerShell → HTTP APIs → MySQL → Email

### Migration Steps
1. **Fresh Installation**: Install v2.0 (no upgrade path)
2. **Data Import**: Use CSV import feature for existing keys
3. **User Creation**: Set up database-based accounts
4. **Client Update**: Deploy new client files
5. **Testing**: Verify all functionality works

### Client Updates Required
- Replace old client files with `OEM_Activator_v2.cmd`
- Update PowerShell script to `main_v2.PS1`
- Configure new server URL in client
- Test activation process with technicians

---

## 🛠️ Troubleshooting

### Common Issues

#### Installation Problems
- **Database Connection**: Check credentials in `config.php`
- **Permission Errors**: Ensure web server can write to directories
- **PHP Extensions**: Verify required extensions are loaded
- **Memory Limits**: Increase PHP memory_limit if needed

#### Admin Panel Issues
- **404 Errors**: Verify mod_rewrite is enabled
- **Login Problems**: Check database connection and user account
- **Permission Denied**: Verify file permissions and ownership
- **Session Issues**: Check session configuration and storage

#### Client Connection Issues
- **HTTPS Errors**: Verify SSL certificate is valid
- **Network Timeouts**: Check firewall and network connectivity
- **Authentication Failures**: Verify technician credentials
- **Key Distribution Problems**: Check database and API logs

### Log Locations
- **PHP Errors**: Check server error logs
- **Application Logs**: `/logs/` directory
- **Database Logs**: MySQL/MariaDB error logs
- **Web Server Logs**: Apache access/error logs

---

## 📞 Support

### Getting Help
- **Documentation**: Check included guides in project
- **System Diagnostics**: Run `/setup/diagnostics.php`
- **Verification**: Run `/verify_deployment.php`
- **Logs**: Check application and server logs

### System Information
- **Version**: 2.0.0
- **Architecture**: MySQL + PHP + PowerShell
- **License**: Internal/Proprietary Use
- **Compatibility**: Windows 10/11, PHP 8.0+

---

## 🔒 Important Security Notes

1. **Change Default Credentials** immediately after installation
2. **Enable HTTPS** for all production deployments
3. **Secure Installation Directory** after setup completion
4. **Regular Backups** of database and configuration
5. **Monitor Logs** for suspicious activity
6. **Keep System Updated** with security patches
7. **Use Strong Passwords** for all accounts
8. **Limit Admin Access** to authorized personnel only

---

*Last Updated: 2025-08-28*  
*System Version: 2.0.0*  
*Documentation Status: Current*