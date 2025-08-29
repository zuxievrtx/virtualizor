<?php
/**
 * WHMCS Virtualizor NAT Port Forwarding Helper Functions
 * 
 * Additional helper functions for NAT VPS port forwarding management
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

/**
 * Get NAT port forwarding configuration for a server
 */
function getNATConfig($serverId) {
    $server = Capsule::table('tblservers')->where('id', $serverId)->first();
    if (!$server) {
        return null;
    }
    
    return [
        'enabled' => $server->configoption1 === 'on',
        'public_ip' => $server->configoption2,
        'port_range' => $server->configoption3 ?: '20000-30000',
        'dest_port' => $server->configoption4 ?: '22',
        'email_template' => $server->configoption5 ?: 'NAT VPS Connection Details',
        'ip_range' => $server->configoption6 ?: '192.168.100.0/24'
    ];
}

/**
 * Get port mapping for a service
 */
function getPortMapping($serviceId) {
    return Capsule::table('mod_virtualizor_port_mappings')
        ->where('service_id', $serviceId)
        ->first();
}

/**
 * Remove port mapping for a service
 */
function removePortMapping($serviceId) {
    return Capsule::table('mod_virtualizor_port_mappings')
        ->where('service_id', $serviceId)
        ->delete();
}

/**
 * Get all port mappings for a server
 */
function getServerPortMappings($serverId) {
    return Capsule::table('mod_virtualizor_port_mappings as pm')
        ->join('tblhosting as h', 'pm.service_id', '=', 'h.id')
        ->where('h.server', $serverId)
        ->select('pm.*', 'h.domain', 'h.userid')
        ->get();
}

/**
 * Validate port range format
 */
function validatePortRange($range) {
    if (!preg_match('/^\d+-\d+$/', $range)) {
        return false;
    }
    
    list($start, $end) = explode('-', $range);
    return (int)$start < (int)$end && (int)$start > 0 && (int)$end <= 65535;
}

/**
 * Validate IP range (CIDR notation)
 */
function validateIPRange($range) {
    if (!preg_match('/^(\d{1,3}\.){3}\d{1,3}\/\d{1,2}$/', $range)) {
        return false;
    }
    
    list($ip, $mask) = explode('/', $range);
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && 
           (int)$mask >= 0 && (int)$mask <= 32;
}

/**
 * Get available ports count in range
 */
function getAvailablePortsCount($portRange) {
    list($start, $end) = explode('-', $portRange);
    $totalPorts = (int)$end - (int)$start + 1;
    
    $usedPorts = Capsule::table('mod_virtualizor_port_mappings')
        ->whereBetween('src_port', [(int)$start, (int)$end])
        ->count();
    
    return $totalPorts - $usedPorts;
}

/**
 * Check if service has NAT port forwarding
 */
function serviceHasNATForwarding($serviceId) {
    return Capsule::table('mod_virtualizor_port_mappings')
        ->where('service_id', $serviceId)
        ->exists();
}

/**
 * Get VPS custom field value by field name
 */
function getVPSCustomField($serviceId, $fieldName) {
    return Capsule::table('tblcustomfieldsvalues as cfv')
        ->join('tblcustomfields as cf', 'cfv.fieldid', '=', 'cf.id')
        ->where('cfv.relid', $serviceId)
        ->where('cf.fieldname', $fieldName)
        ->value('cfv.value');
}

/**
 * Log NAT activity with prefix
 */
function logNATActivity($message) {
    logActivity('Virtualizor NAT: ' . $message);
}

/**
 * Create admin ticket for failed NAT operations
 */
function createNATSupportTicket($serviceId, $error) {
    try {
        $service = Capsule::table('tblhosting')->where('id', $serviceId)->first();
        if (!$service) {
            return false;
        }
        
        $subject = "NAT Port Forwarding Failed - Service #{$serviceId}";
        $message = "Automatic NAT port forwarding failed for service #{$serviceId}.\n\n";
        $message .= "Service Domain: {$service->domain}\n";
        $message .= "Client ID: {$service->userid}\n";
        $message .= "Error: {$error}\n\n";
        $message .= "Please manually configure port forwarding for this NAT VPS service.";
        
        // Create support ticket
        $ticketId = Capsule::table('tbltickets')->insertGetId([
            'tid' => generateTicketNumber(),
            'userid' => $service->userid,
            'deptid' => 1, // Default to first department
            'email' => '',
            'name' => 'System',
            'title' => $subject,
            'message' => $message,
            'status' => 'Open',
            'priority' => 'Medium',
            'admin' => 'System',
            'date' => date('Y-m-d H:i:s'),
            'lastreply' => date('Y-m-d H:i:s')
        ]);
        
        // Add ticket reply
        Capsule::table('tblticketreplies')->insert([
            'tid' => $ticketId,
            'userid' => $service->userid,
            'admin' => 'System',
            'date' => date('Y-m-d H:i:s'),
            'message' => $message
        ]);
        
        logNATActivity("Created support ticket #{$ticketId} for failed NAT setup on service #{$serviceId}");
        return $ticketId;
        
    } catch (Exception $e) {
        logNATActivity("Failed to create support ticket: " . $e->getMessage());
        return false;
    }
}

/**
 * Generate unique ticket number
 */
function generateTicketNumber() {
    do {
        $tid = mt_rand(100000, 999999);
    } while (Capsule::table('tbltickets')->where('tid', $tid)->exists());
    
    return $tid;
}

/**
 * Cleanup orphaned port mappings
 */
function cleanupOrphanedPortMappings() {
    $orphaned = Capsule::table('mod_virtualizor_port_mappings as pm')
        ->leftJoin('tblhosting as h', 'pm.service_id', '=', 'h.id')
        ->whereNull('h.id')
        ->orWhere('h.domainstatus', 'Cancelled')
        ->orWhere('h.domainstatus', 'Terminated')
        ->pluck('pm.id');
    
    if (!empty($orphaned)) {
        Capsule::table('mod_virtualizor_port_mappings')
            ->whereIn('id', $orphaned)
            ->delete();
        
        logNATActivity("Cleaned up " . count($orphaned) . " orphaned port mappings");
    }
    
    return count($orphaned);
}

/**
 * Get NAT statistics for admin dashboard
 */
function getNATStatistics() {
    $totalMappings = Capsule::table('mod_virtualizor_port_mappings')->count();
    $activeMappings = Capsule::table('mod_virtualizor_port_mappings as pm')
        ->join('tblhosting as h', 'pm.service_id', '=', 'h.id')
        ->where('h.domainstatus', 'Active')
        ->count();
    
    $portRanges = Capsule::table('tblservers')
        ->where('type', 'virtualizor')
        ->whereNotNull('configoption3')
        ->pluck('configoption3');
    
    $totalAvailablePorts = 0;
    foreach ($portRanges as $range) {
        if (validatePortRange($range)) {
            list($start, $end) = explode('-', $range);
            $totalAvailablePorts += (int)$end - (int)$start + 1;
        }
    }
    
    return [
        'total_mappings' => $totalMappings,
        'active_mappings' => $activeMappings,
        'total_available_ports' => $totalAvailablePorts,
        'port_utilization' => $totalAvailablePorts > 0 ? ($totalMappings / $totalAvailablePorts) * 100 : 0
    ];
}

/**
 * Test Virtualizor API connection
 */
function testVirtualizorConnection($serverId) {
    try {
        $server = Capsule::table('tblservers')->where('id', $serverId)->first();
        if (!$server) {
            return ['success' => false, 'error' => 'Server not found'];
        }
        
        require_once(dirname(__FILE__) . '/sdk/admin.php');
        
        $hostname = $server->hostname ?: $server->ipaddress;
        $username = $server->username;
        $password = decrypt($server->password);
        
        $admin = new Virtualizor_Admin_API($hostname, $username, $password);
        
        // Test API call
        $result = $admin->listhaproxy([], 1, 1);
        
        if ($result !== false) {
            return ['success' => true, 'message' => 'API connection successful'];
        } else {
            return ['success' => false, 'error' => 'API connection failed'];
        }
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}