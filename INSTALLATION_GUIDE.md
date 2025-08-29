# WHMCS Virtualizor NAT Port Forwarding Module

## Overview

This module automatically creates port forwarding rules for NAT VPS services in WHMCS using the Virtualizor module. When a new VPS with a private IP address is provisioned, the module automatically:

1. Detects if the VPS is using a NAT IP address
2. Creates a port forwarding rule via Virtualizor API
3. Assigns a unique public port
4. Sends connection details to the client via email
5. Manages cleanup when services are terminated

## Features

- ✅ Automatic NAT VPS detection based on IP range
- ✅ Dynamic port assignment from configurable ranges
- ✅ Virtualizor API integration for port forwarding
- ✅ Professional email templates with connection details
- ✅ Administrative interface for monitoring and management
- ✅ Automatic cleanup of terminated services
- ✅ Support ticket creation for failed automations
- ✅ Comprehensive error handling and logging
- ✅ Daily cleanup of orphaned mappings

## Installation

### 1. File Placement

Copy all files to your WHMCS Virtualizor module directory:
```
/modules/servers/virtualizor/
├── hooks.php                    # Main hook file
├── nat_helpers.php             # Helper functions
├── nat_email_templates.php     # Email template management
├── nat_admin.php              # Administrative interface
└── INSTALLATION_GUIDE.md      # This file
```

### 2. Database Setup

The module will automatically create the required database table when first used:
- `mod_virtualizor_port_mappings` - Stores port forwarding mappings

### 3. WHMCS Configuration

#### Server Configuration

1. Go to **Setup > Products/Services > Servers**
2. Edit your Virtualizor server
3. Configure the additional fields:

| Field | Description | Example |
|-------|-------------|---------|
| Enable NAT Port Forwarding | Enable/disable automatic port forwarding | ✓ Enabled |
| NAT Public IP | Public IP for port forwarding (leave empty to use server IP) | 203.0.113.10 |
| NAT Port Range | Port range for assignments | 20000-30000 |
| NAT Default Dest Port | Internal port to forward to | 22 |
| NAT Email Template | Email template name | NAT VPS Connection Details |
| NAT IP Range | Private IP range to detect NAT VPS | 192.168.100.0/24 |

#### Hook Registration

Add the following line to your WHMCS configuration file or create an `includes/hooks/virtualizor_nat.php` file:

```php
<?php
require_once ROOTDIR . '/modules/servers/virtualizor/hooks.php';
```

### 4. Email Template Setup

The module will automatically create email templates, but you can customize them:

1. Go to **Setup > Email Templates**
2. Find "NAT VPS Connection Details" template
3. Customize subject and content as needed

Available merge fields:
- `{$client_firstname}` - Client first name
- `{$client_lastname}` - Client last name
- `{$service_id}` - Service ID
- `{$domain}` - Service domain
- `{$vps_private_ip}` - VPS private IP
- `{$public_ip}` - Public IP address
- `{$public_port}` - Assigned public port
- `{$dest_port}` - Internal destination port
- `{$root_username}` - Root username
- `{$root_password}` - Root password
- `{$ssh_command}` - SSH connection command

## Usage

### Automatic Operation

Once configured, the module works automatically:

1. **VPS Creation**: When a new Virtualizor VPS is created, the module checks if the IP is within the configured NAT range
2. **Port Assignment**: If it's a NAT VPS, a unique port is assigned from the configured range
3. **API Call**: Port forwarding rule is created via Virtualizor API
4. **Email Notification**: Client receives connection details
5. **Database Storage**: Mapping is stored for future reference

### Administrative Interface

Access the admin interface at:
```
/modules/servers/virtualizor/nat_admin.php
```

Features:
- View all port mappings
- Monitor port utilization
- Test Virtualizor API connections
- Create manual port mappings
- Cleanup orphaned mappings
- View statistics and reports

### Manual Operations

#### Create Manual Port Mapping
```php
storePortMapping($serviceId, $srcPort, $destIp, $destPort, $publicIp);
```

#### Remove Port Mapping
```php
removePortMapping($serviceId);
```

#### Check if Service has NAT Forwarding
```php
$hasForwarding = serviceHasNATForwarding($serviceId);
```

## Configuration Examples

### Basic NAT Setup
```
Enable NAT Port Forwarding: Yes
NAT Public IP: (empty - use server IP)
NAT Port Range: 20000-30000
NAT Default Dest Port: 22
NAT Email Template: NAT VPS Connection Details
NAT IP Range: 192.168.100.0/24
```

### Advanced Multi-Server Setup
```
Server 1:
- NAT Port Range: 20000-25000
- NAT IP Range: 192.168.100.0/24

Server 2:
- NAT Port Range: 25001-30000
- NAT IP Range: 192.168.101.0/24
```

## Troubleshooting

### Common Issues

#### 1. Port Forwarding Not Created
**Symptoms**: VPS created but no port forwarding rule
**Solutions**:
- Check if VPS IP is within configured NAT range
- Verify Virtualizor API credentials
- Check WHMCS Module Log for errors
- Ensure hook file is properly loaded

#### 2. Email Not Sent
**Symptoms**: Port forwarding works but client doesn't receive email
**Solutions**:
- Verify email template exists
- Check WHMCS email settings
- Review Activity Log for email errors
- Confirm client email address is valid

#### 3. API Connection Failed
**Symptoms**: "API connection failed" errors
**Solutions**:
- Test API connection in admin interface
- Verify Virtualizor server credentials
- Check network connectivity
- Ensure Virtualizor API is enabled

#### 4. No Available Ports
**Symptoms**: "No available ports in range" error
**Solutions**:
- Expand port range in configuration
- Cleanup orphaned mappings
- Check port utilization statistics
- Consider multiple server setup

### Debug Mode

Enable debug logging by adding to your WHMCS configuration:
```php
$virtualizor_nat_debug = true;
```

### Log Files

Check these log files for troubleshooting:
- WHMCS Activity Log: `/admin/systemactivitylog.php`
- WHMCS Module Log: `/admin/systemmodulelog.php`
- Server error logs

## Security Considerations

1. **Port Range Security**: Use high port numbers (20000+) to avoid conflicts
2. **API Credentials**: Secure Virtualizor API credentials
3. **Email Content**: Avoid including sensitive information in email templates
4. **Access Control**: Restrict access to admin interface
5. **Regular Cleanup**: Monitor and cleanup unused port mappings

## Maintenance

### Regular Tasks

1. **Weekly**: Review port utilization statistics
2. **Monthly**: Cleanup orphaned port mappings
3. **Quarterly**: Review and update email templates
4. **As Needed**: Expand port ranges when utilization is high

### Database Maintenance

The module includes automatic cleanup, but you can manually run:
```php
cleanupOrphanedPortMappings();
```

## API Reference

### Available Functions

#### NAT Detection
```php
isNATVPS($ip, $cidr) // Check if IP is in NAT range
```

#### Port Management
```php
findAvailablePort($startPort, $endPort, $serviceId) // Find available port
getPortMapping($serviceId) // Get port mapping for service
removePortMapping($serviceId) // Remove port mapping
```

#### Configuration
```php
getNATConfig($serverId) // Get NAT configuration for server
validatePortRange($range) // Validate port range format
validateIPRange($range) // Validate IP range format
```

#### Statistics
```php
getNATStatistics() // Get usage statistics
getAvailablePortsCount($portRange) // Count available ports
```

## Support

For issues or questions:
1. Check the troubleshooting section above
2. Review WHMCS logs for error messages
3. Test API connectivity using the admin interface
4. Contact your system administrator

## Version History

- **v1.0**: Initial release with basic NAT port forwarding
- **v1.1**: Added email templates and admin interface
- **v1.2**: Enhanced error handling and cleanup features
- **v1.3**: Added statistics and monitoring capabilities

## License

This module is provided as-is for integration with WHMCS and Virtualizor systems. Please ensure compliance with your WHMCS and Virtualizor licensing terms.