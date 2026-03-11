# 🔐 OEM Activation System v3.0 - Technician Guide
**USB Auth, Hardware QC, Adaptive Network Timing**

## 📋 Quick Start

### **Step 1: Run the Activator**
1. **Right-click** on `OEM_Activator.cmd`
2. Select **"Run as administrator"**
3. Follow the on-screen prompts

### **Step 2: Enter Credentials**
- **Technician ID**: Your assigned technician identifier
- **Order Number**: Last 5 characters of the order number

### **Step 3: Wait for Completion**
- The system will automatically activate Windows
- **DO NOT close the window manually**
- Process typically takes 1-2 minutes

## 🆕 What's New in v2.0

### **Enhanced Reliability**
- ✅ **Database-Powered**: No more CSV file conflicts
- ✅ **Concurrent Support**: Multiple technicians can work simultaneously  
- ✅ **Automatic Recovery**: Smart retry logic for failed attempts
- ✅ **Real-time Updates**: Instant key allocation and status tracking

### **Improved User Experience**
- ✅ **Better Error Messages**: Clear, actionable feedback
- ✅ **Progress Indicators**: Visual feedback during activation
- ✅ **Automatic Logging**: Complete audit trail for troubleshooting
- ✅ **Network Diagnostics**: Built-in connectivity testing

### **Security Enhancements**
- ✅ **Secure Authentication**: Database-stored credentials with encryption
- ✅ **Session Management**: Automatic timeout and cleanup
- ✅ **Account Lockout**: Protection against unauthorized access
- ✅ **Audit Logging**: Complete tracking of all activation attempts

## 🔧 System Requirements

### **Supported Windows Versions**
- ✅ Windows 11 (All editions)
- ✅ Windows 10 (All editions)
- ✅ Windows Server 2019/2022

### **Technical Requirements**
- ✅ Administrator privileges required
- ✅ Network connection to activation server
- ✅ PowerShell 5.1+ (included with Windows)
- ✅ No additional software installation needed

## 🌐 Network Configuration

### **Default Server**
- **URL**: `http://activate.local`
- **Configured in**: `CONFIG.txt`

### **Custom Server Setup**
1. Edit `CONFIG.txt` file
2. Change `SERVER_URL=` to your server address
3. Save the file
4. Run the activator normally

**Examples**:
```
SERVER_URL=http://192.168.1.100
SERVER_URL=https://oem.company.com
SERVER_URL=http://oem-server:8080
```

## 🚨 Troubleshooting

### **Common Issues**

#### **❌ "Administrator privileges required"**
**Solution**: Right-click `OEM_Activator.cmd` → "Run as administrator"

#### **❌ "Could not download activation script"**  
**Solutions**:
1. Check network connection
2. Verify server URL in `CONFIG.txt`
3. Check firewall settings
4. Contact system administrator

#### **❌ "Invalid credentials"**
**Solutions**:
1. Verify technician ID spelling
2. Check if account is locked (contact administrator)  
3. Confirm order number format (exactly 5 characters)

#### **❌ "No available keys"**
**Solutions**:
1. Contact administrator to add more keys
2. Check if existing keys need recycling
3. Verify key import was successful

### **Error Logs**
- **Location**: `%TEMP%\oem_activation_log.txt`
- **Contains**: Detailed error information and network diagnostics
- **Usage**: Send this file to your system administrator for support

## 📞 Support

### **For Technicians**
1. **Check Error Log**: `%TEMP%\oem_activation_log.txt`
2. **Retry Activation**: Often resolves temporary network issues
3. **Contact Supervisor**: If problems persist

### **For Administrators**
1. **Check Server Status**: Verify web server and database are running
2. **Review Admin Panel**: Check for system alerts and key availability
3. **Check Network**: Ensure client workstations can reach server
4. **Review Logs**: Server logs contain detailed error information

## 📋 Best Practices

### **Before Activation**
- ✅ Ensure PC is connected to network
- ✅ Verify Windows installation is complete
- ✅ Check that order number is available
- ✅ Confirm technician credentials are working

### **During Activation**
- ✅ Do NOT close the activation window
- ✅ Wait for completion message
- ✅ Do NOT run multiple activations simultaneously on same PC
- ✅ Note any error messages for reporting

### **After Activation**
- ✅ Verify Windows shows "Activated" in Settings
- ✅ Apply OEM license sticker to PC case
- ✅ Document activation in work order
- ✅ Complete quality assurance checklist

## 🔄 Version History

### **v2.0.0 (2025-08-24) - Database Edition**
- 🚀 Complete rewrite with MySQL database backend
- 🚀 Full concurrency support for multiple technicians
- 🚀 Enhanced error handling and user feedback
- 🚀 Automatic session management and cleanup
- 🚀 Professional installation wizard
- 🚀 Real-time monitoring and audit logging

### **Migration from v1.x**
- ✅ **Same Workflow**: No changes to technician process
- ✅ **Better Performance**: Faster activation and fewer errors
- ✅ **Enhanced Security**: Database-stored credentials
- ✅ **Improved Reliability**: No more CSV file conflicts

---

**🔐 OEM Activation System v3.0** - Professional license management for the modern enterprise.

*For technical support, contact your system administrator.*