# Security Policy

## Reporting a Vulnerability

If you discover a security vulnerability in this system, please report it responsibly.

**Do NOT open a public GitHub issue for security vulnerabilities.**

Instead, contact the development team directly via private channels.

## Supported Versions

| Version | Supported |
|---------|-----------|
| 3.x     | Yes       |
| 2.x     | No        |
| < 2.0   | No        |

## Security Features

This system includes multiple security layers:

- **Authentication**: bcrypt password hashing, account lockout, optional USB hardware-bound auth
- **Session Management**: secure token generation, session fixation prevention
- **API Security**: Redis-backed rate limiting, input validation, prepared SQL statements
- **Transport**: HTTPS enforcement with HSTS headers
- **Admin Panel**: RBAC with granular permissions, IP whitelist support, CSRF protection
- **Audit Trail**: complete logging of all activation attempts and admin actions
