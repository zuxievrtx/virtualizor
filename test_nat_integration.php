<?php
/**
 * WHMCS Virtualizor NAT Port Forwarding Test Script
 * 
 * This script tests the NAT port forwarding integration
 * Run from WHMCS root directory: php modules/servers/virtualizor/test_nat_integration.php
 */

// Include WHMCS initialization
require_once(dirname(__FILE__) . '/../../../init.php');
require_once('nat_helpers.php');
require_once('nat_email_templates.php');

use WHMCS\Database\Capsule;

echo "WHMCS Virtualizor NAT Port Forwarding Integration Test\n";
echo "====================================================\n\n";

// Test 1: Database table creation
echo "Test 1: Database Table Creation\n";
echo "-------------------------------\n";
try {
    createPortMappingsTable();
    
    if (Capsule::schema()->hasTable('mod_virtualizor_port_mappings')) {
        echo "✅ Port mappings table exists or created successfully\n";
    } else {
        echo "❌ Failed to create port mappings table\n";
    }
} catch (Exception $e) {
    echo "❌ Error creating table: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 2: NAT IP Range Detection
echo "Test 2: NAT IP Range Detection\n";
echo "------------------------------\n";
$testIPs = [
    '192.168.100.50' => '192.168.100.0/24',
    '10.0.0.100' => '10.0.0.0/8',
    '203.0.113.10' => '192.168.100.0/24', // Should fail
    '172.16.1.50' => '172.16.0.0/12'
];

foreach ($testIPs as $ip => $cidr) {
    $result = isNATVPS($ip, $cidr);
    $status = $result ? "✅" : "❌";
    echo "{$status} IP {$ip} in range {$cidr}: " . ($result ? "YES" : "NO") . "\n";
}
echo "\n";

// Test 3: Port Range Validation
echo "Test 3: Port Range Validation\n";
echo "-----------------------------\n";
$testRanges = [
    '20000-30000',
    '1000-2000',
    '80-443',
    'invalid-range',
    '30000-20000', // Invalid order
    '70000-80000'  // Invalid high ports
];

foreach ($testRanges as $range) {
    $result = validatePortRange($range);
    $status = $result ? "✅" : "❌";
    echo "{$status} Port range {$range}: " . ($result ? "VALID" : "INVALID") . "\n";
}
echo "\n";

// Test 4: IP Range Validation
echo "Test 4: IP Range Validation\n";
echo "---------------------------\n";
$testCIDRs = [
    '192.168.100.0/24',
    '10.0.0.0/8',
    '172.16.0.0/12',
    '203.0.113.0/24',
    '192.168.1.0/33', // Invalid mask
    'invalid.ip/24'
];

foreach ($testCIDRs as $cidr) {
    $result = validateIPRange($cidr);
    $status = $result ? "✅" : "❌";
    echo "{$status} CIDR {$cidr}: " . ($result ? "VALID" : "INVALID") . "\n";
}
echo "\n";

// Test 5: Virtualizor Server Configuration
echo "Test 5: Virtualizor Server Configuration\n";
echo "---------------------------------------\n";
$servers = Capsule::table('tblservers')
    ->where('type', 'virtualizor')
    ->get();

if ($servers->count() > 0) {
    echo "✅ Found " . $servers->count() . " Virtualizor server(s)\n";
    
    foreach ($servers as $server) {
        echo "\nServer: {$server->name} ({$server->ipaddress})\n";
        $config = getNATConfig($server->id);
        
        if ($config) {
            echo "  - NAT Enabled: " . ($config['enabled'] ? 'YES' : 'NO') . "\n";
            echo "  - Public IP: " . ($config['public_ip'] ?: 'Use server IP') . "\n";
            echo "  - Port Range: " . $config['port_range'] . "\n";
            echo "  - Dest Port: " . $config['dest_port'] . "\n";
            echo "  - IP Range: " . $config['ip_range'] . "\n";
            
            // Test available ports
            if (validatePortRange($config['port_range'])) {
                $available = getAvailablePortsCount($config['port_range']);
                echo "  - Available Ports: {$available}\n";
            }
        }
    }
} else {
    echo "❌ No Virtualizor servers found\n";
    echo "   Please add a Virtualizor server in WHMCS first\n";
}
echo "\n";

// Test 6: Email Template Creation
echo "Test 6: Email Template Creation\n";
echo "-------------------------------\n";
try {
    createOrUpdateNATEmailTemplate();
    createNATWelcomeTemplate();
    
    $natTemplate = Capsule::table('tblemailtemplates')
        ->where('name', 'NAT VPS Connection Details')
        ->first();
    
    $welcomeTemplate = Capsule::table('tblemailtemplates')
        ->where('name', 'NAT VPS Welcome')
        ->first();
    
    if ($natTemplate) {
        echo "✅ NAT connection details email template exists\n";
    } else {
        echo "❌ NAT connection details email template missing\n";
    }
    
    if ($welcomeTemplate) {
        echo "✅ NAT welcome email template exists\n";
    } else {
        echo "❌ NAT welcome email template missing\n";
    }
} catch (Exception $e) {
    echo "❌ Error with email templates: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 7: Statistics and Monitoring
echo "Test 7: Statistics and Monitoring\n";
echo "---------------------------------\n";
try {
    $stats = getNATStatistics();
    echo "✅ Statistics retrieved successfully:\n";
    echo "  - Total Mappings: {$stats['total_mappings']}\n";
    echo "  - Active Mappings: {$stats['active_mappings']}\n";
    echo "  - Available Ports: {$stats['total_available_ports']}\n";
    echo "  - Port Utilization: " . number_format($stats['port_utilization'], 2) . "%\n";
} catch (Exception $e) {
    echo "❌ Error retrieving statistics: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 8: Hook File Validation
echo "Test 8: Hook File Validation\n";
echo "----------------------------\n";
$hookFile = dirname(__FILE__) . '/hooks.php';
if (file_exists($hookFile)) {
    echo "✅ Hook file exists: {$hookFile}\n";
    
    // Check if hooks are properly registered
    $hookContent = file_get_contents($hookFile);
    if (strpos($hookContent, 'add_hook(\'AfterModuleCreate\'') !== false) {
        echo "✅ AfterModuleCreate hook found\n";
    } else {
        echo "❌ AfterModuleCreate hook missing\n";
    }
    
    if (strpos($hookContent, 'add_hook(\'AfterModuleTerminate\'') !== false) {
        echo "✅ AfterModuleTerminate hook found\n";
    } else {
        echo "❌ AfterModuleTerminate hook missing\n";
    }
    
    if (strpos($hookContent, 'add_hook(\'DailyCronJob\'') !== false) {
        echo "✅ DailyCronJob hook found\n";
    } else {
        echo "❌ DailyCronJob hook missing\n";
    }
} else {
    echo "❌ Hook file missing: {$hookFile}\n";
}
echo "\n";

// Test 9: Helper Functions
echo "Test 9: Helper Functions\n";
echo "------------------------\n";
$helperFile = dirname(__FILE__) . '/nat_helpers.php';
if (file_exists($helperFile)) {
    echo "✅ Helper functions file exists\n";
    
    // Test some helper functions
    if (function_exists('getNATConfig')) {
        echo "✅ getNATConfig function available\n";
    } else {
        echo "❌ getNATConfig function missing\n";
    }
    
    if (function_exists('getPortMapping')) {
        echo "✅ getPortMapping function available\n";
    } else {
        echo "❌ getPortMapping function missing\n";
    }
    
    if (function_exists('cleanupOrphanedPortMappings')) {
        echo "✅ cleanupOrphanedPortMappings function available\n";
    } else {
        echo "❌ cleanupOrphanedPortMappings function missing\n";
    }
} else {
    echo "❌ Helper functions file missing: {$helperFile}\n";
}
echo "\n";

// Test 10: Admin Interface
echo "Test 10: Admin Interface\n";
echo "------------------------\n";
$adminFile = dirname(__FILE__) . '/nat_admin.php';
if (file_exists($adminFile)) {
    echo "✅ Admin interface file exists: {$adminFile}\n";
    echo "   Access at: /modules/servers/virtualizor/nat_admin.php\n";
} else {
    echo "❌ Admin interface file missing: {$adminFile}\n";
}
echo "\n";

// Summary
echo "Integration Test Summary\n";
echo "=======================\n";
echo "The NAT port forwarding integration has been tested.\n";
echo "Please review any ❌ items above and address them before using in production.\n\n";

echo "Next Steps:\n";
echo "1. Configure your Virtualizor servers with NAT settings\n";
echo "2. Test with a NAT VPS creation\n";
echo "3. Monitor the Activity Log for any issues\n";
echo "4. Access the admin interface to monitor port usage\n\n";

echo "For detailed setup instructions, see INSTALLATION_GUIDE.md\n";