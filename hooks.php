<?php
/**
 * WHMCS Virtualizor NAT Port Forwarding Hook
 * 
 * This file handles automatic port forwarding for NAT VPS services
 * Created for Virtualizor module integration
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;
use WHMCS\Mail\Message;

require_once(dirname(__FILE__) . '/nat_helpers.php');
require_once(dirname(__FILE__) . '/nat_email_templates.php');

/**
 * Hook to handle automatic port forwarding after VPS creation
 */
add_hook('AfterModuleCreate', 1, function($vars) {
    
    // Only process Virtualizor module
    if ($vars['producttype'] !== 'server' || $vars['servertype'] !== 'virtualizor') {
        return;
    }
    
    try {
        // Get server configuration
        $server = Capsule::table('tblservers')->where('id', $vars['serverid'])->first();
        if (!$server) {
            logActivity('Virtualizor NAT Hook: Server not found for service ID ' . $vars['serviceid']);
            return;
        }
        
        // Parse server configuration
        $serverConfig = [];
        if (!empty($server->configoption1)) $serverConfig['nat_port_forwarding'] = $server->configoption1;
        if (!empty($server->configoption2)) $serverConfig['nat_public_ip'] = $server->configoption2;
        if (!empty($server->configoption3)) $serverConfig['nat_port_range'] = $server->configoption3;
        if (!empty($server->configoption4)) $serverConfig['nat_dest_port'] = $server->configoption4;
        if (!empty($server->configoption5)) $serverConfig['nat_email_template'] = $server->configoption5;
        if (!empty($server->configoption6)) $serverConfig['nat_ip_range'] = $server->configoption6;
        
        // Check if NAT port forwarding is enabled
        if (empty($serverConfig['nat_port_forwarding']) || $serverConfig['nat_port_forwarding'] !== 'on') {
            return;
        }
        
        // Get VPS details
        $service = Capsule::table('tblhosting')->where('id', $vars['serviceid'])->first();
        if (!$service) {
            logActivity('Virtualizor NAT Hook: Service not found for ID ' . $vars['serviceid']);
            return;
        }
        
        // Get VPS custom fields to find IP and UUID
        $customFields = Capsule::table('tblcustomfieldsvalues')
            ->join('tblcustomfields', 'tblcustomfieldsvalues.fieldid', '=', 'tblcustomfields.id')
            ->where('tblcustomfieldsvalues.relid', $vars['serviceid'])
            ->select('tblcustomfields.fieldname', 'tblcustomfieldsvalues.value')
            ->get();
        
        $vpsData = [];
        foreach ($customFields as $field) {
            $vpsData[strtolower($field->fieldname)] = $field->value;
        }
        
        // Get VPS IP address (try multiple possible field names)
        $vpsIp = null;
        $possibleIpFields = ['ip_address', 'ipaddress', 'vps_ip', 'primary_ip'];
        foreach ($possibleIpFields as $fieldName) {
            if (!empty($vpsData[$fieldName])) {
                $vpsIp = $vpsData[$fieldName];
                break;
            }
        }
        
        // If no IP in custom fields, try dedicated IP from service
        if (!$vpsIp && !empty($service->dedicatedip)) {
            $vpsIp = $service->dedicatedip;
        }
        
        if (!$vpsIp) {
            logActivity('Virtualizor NAT Hook: Could not determine VPS IP address for service ID ' . $vars['serviceid']);
            return;
        }
        
        // Check if this is a NAT VPS based on IP range
        if (!isNATVPS($vpsIp, $serverConfig['nat_ip_range'] ?? '192.168.100.0/24')) {
            return;
        }
        
        // Get VPS UUID
        $vpsUuid = $vpsData['vpsuuid'] ?? $vpsData['vps_uuid'] ?? null;
        if (!$vpsUuid) {
            logActivity('Virtualizor NAT Hook: Could not determine VPS UUID for service ID ' . $vars['serviceid']);
            return;
        }
        
        // Set up port forwarding
        $result = setupNATPortForwarding($vars, $serverConfig, $vpsIp, $vpsUuid, $server);
        
        if ($result['success']) {
            logActivity('Virtualizor NAT Hook: Successfully created port forwarding for service ID ' . $vars['serviceid'] . 
                       ' - Public Port: ' . $result['public_port']);
            
            // Send email notification using enhanced template
            sendEnhancedNATEmail($vars, $serverConfig, $result, $vpsIp);
        } else {
            logActivity('Virtualizor NAT Hook: Failed to create port forwarding for service ID ' . $vars['serviceid'] . 
                       ' - Error: ' . $result['error']);
            
            // Create support ticket for failed automation
            createNATSupportTicket($vars['serviceid'], $result['error']);
        }
        
    } catch (Exception $e) {
        logActivity('Virtualizor NAT Hook Error: ' . $e->getMessage());
    }
});

/**
 * Hook to cleanup port forwarding when service is terminated
 */
add_hook('AfterModuleTerminate', 1, function($vars) {
    // Only process Virtualizor module
    if ($vars['producttype'] !== 'server' || $vars['servertype'] !== 'virtualizor') {
        return;
    }
    
    try {
        // Get port mapping
        $mapping = getPortMapping($vars['serviceid']);
        
        if ($mapping) {
            // Remove from Virtualizor via API
            $server = Capsule::table('tblservers')->where('id', $vars['serverid'])->first();
            if ($server) {
                require_once(dirname(__FILE__) . '/sdk/admin.php');
                
                $hostname = $server->hostname ?: $server->ipaddress;
                $username = $server->username;
                $password = decrypt($server->password);
                
                $admin = new Virtualizor_Admin_API($hostname, $username, $password);
                
                // Get VPS UUID from custom fields
                $customFields = Capsule::table('tblcustomfieldsvalues')
                    ->join('tblcustomfields', 'tblcustomfieldsvalues.fieldid', '=', 'tblcustomfields.id')
                    ->where('tblcustomfieldsvalues.relid', $vars['serviceid'])
                    ->where('tblcustomfields.fieldname', 'like', '%uuid%')
                    ->value('tblcustomfieldsvalues.value');
                
                if ($customFields) {
                    // Find the domain forwarding rule to delete
                    $haproxyList = $admin->listhaproxy();
                    if ($haproxyList) {
                        foreach ($haproxyList as $rule) {
                            if ($rule['src_port'] == $mapping->src_port && 
                                $rule['dest_ip'] == $mapping->dest_ip) {
                                
                                // Delete the rule
                                $post = [
                                    'delete' => $rule['id'],
                                    'action' => 'delvdf'
                                ];
                                
                                $result = $admin->haproxy($post);
                                
                                if (isset($result['done']) && $result['done']) {
                                    logNATActivity("Removed port forwarding rule for terminated service {$vars['serviceid']}");
                                } else {
                                    logNATActivity("Failed to remove port forwarding rule for service {$vars['serviceid']}");
                                }
                                break;
                            }
                        }
                    }
                }
            }
            
            // Remove from database
            removePortMapping($vars['serviceid']);
            logNATActivity("Cleaned up port mapping for terminated service {$vars['serviceid']}");
        }
        
    } catch (Exception $e) {
        logNATActivity("Error cleaning up port mapping for service {$vars['serviceid']}: " . $e->getMessage());
    }
});

/**
 * Hook to cleanup port forwarding when service is cancelled
 */
add_hook('AfterModuleSuspend', 1, function($vars) {
    // Only process Virtualizor module
    if ($vars['producttype'] !== 'server' || $vars['servertype'] !== 'virtualizor') {
        return;
    }
    
    // Log suspension - port forwarding remains active but logged
    $mapping = getPortMapping($vars['serviceid']);
    if ($mapping) {
        logNATActivity("Service {$vars['serviceid']} suspended - port mapping {$mapping->src_port} remains active");
    }
});

/**
 * Daily cleanup hook for orphaned port mappings
 */
add_hook('DailyCronJob', 1, function($vars) {
    try {
        $cleaned = cleanupOrphanedPortMappings();
        if ($cleaned > 0) {
            logNATActivity("Daily cleanup: Removed {$cleaned} orphaned port mappings");
        }
    } catch (Exception $e) {
        logNATActivity("Daily cleanup error: " . $e->getMessage());
    }
});

/**
 * Check if IP address is within NAT range
 */
function isNATVPS($ip, $cidr) {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return false;
    }
    
    list($subnet, $mask) = explode('/', $cidr);
    $subnet = ip2long($subnet);
    $ip = ip2long($ip);
    $mask = ~((1 << (32 - $mask)) - 1);
    
    return ($ip & $mask) == ($subnet & $mask);
}

/**
 * Setup NAT port forwarding
 */
function setupNATPortForwarding($vars, $config, $vpsIp, $vpsUuid, $server) {
    try {
        // Include Virtualizor SDK
        require_once(dirname(__FILE__) . '/sdk/admin.php');
        
        // Get server credentials
        $hostname = $server->hostname ?: $server->ipaddress;
        $username = $server->username;
        $password = decrypt($server->password);
        
        // Initialize Virtualizor API
        $admin = new Virtualizor_Admin_API($hostname, $username, $password);
        
        // Determine public IP
        $publicIp = !empty($config['nat_public_ip']) ? $config['nat_public_ip'] : $hostname;
        
        // Parse port range
        $portRange = explode('-', $config['nat_port_range'] ?? '20000-30000');
        $startPort = (int)$portRange[0];
        $endPort = (int)($portRange[1] ?? $startPort + 10000);
        
        // Find available port
        $publicPort = findAvailablePort($startPort, $endPort, $vars['serviceid']);
        if (!$publicPort) {
            return ['success' => false, 'error' => 'No available ports in range'];
        }
        
        // Create port forwarding rule
        $post = [
            'serid' => 0,
            'vpsuuid' => $vpsUuid,
            'protocol' => 'TCP',
            'src_hostname' => $publicIp,
            'src_port' => $publicPort,
            'dest_ip' => $vpsIp,
            'dest_port' => $config['nat_dest_port'] ?? 22,
            'action' => 'addvdf'
        ];
        
        $output = $admin->haproxy($post);
        
        if (isset($output['done']) && $output['done']) {
            // Store port mapping in database
            storePortMapping($vars['serviceid'], $publicPort, $vpsIp, $config['nat_dest_port'] ?? 22, $publicIp);
            
            return [
                'success' => true,
                'public_port' => $publicPort,
                'public_ip' => $publicIp,
                'dest_port' => $config['nat_dest_port'] ?? 22
            ];
        } else {
            return [
                'success' => false,
                'error' => isset($output['error']) ? $output['error'] : 'Unknown API error'
            ];
        }
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Find available port in range
 */
function findAvailablePort($startPort, $endPort, $serviceId) {
    // Create port mappings table if it doesn't exist
    createPortMappingsTable();
    
    // Get used ports
    $usedPorts = Capsule::table('mod_virtualizor_port_mappings')
        ->pluck('src_port')
        ->toArray();
    
    // Find available port
    for ($port = $startPort; $port <= $endPort; $port++) {
        if (!in_array($port, $usedPorts)) {
            return $port;
        }
    }
    
    return null;
}

/**
 * Store port mapping in database
 */
function storePortMapping($serviceId, $srcPort, $destIp, $destPort, $publicIp) {
    createPortMappingsTable();
    
    Capsule::table('mod_virtualizor_port_mappings')->insert([
        'service_id' => $serviceId,
        'src_port' => $srcPort,
        'dest_ip' => $destIp,
        'dest_port' => $destPort,
        'public_ip' => $publicIp,
        'created_at' => date('Y-m-d H:i:s')
    ]);
}

/**
 * Create port mappings table
 */
function createPortMappingsTable() {
    if (!Capsule::schema()->hasTable('mod_virtualizor_port_mappings')) {
        Capsule::schema()->create('mod_virtualizor_port_mappings', function ($table) {
            $table->increments('id');
            $table->integer('service_id');
            $table->integer('src_port');
            $table->string('dest_ip', 15);
            $table->integer('dest_port');
            $table->string('public_ip', 15);
            $table->timestamp('created_at');
            $table->index('service_id');
            $table->index('src_port');
        });
    }
}

/**
 * Send NAT connection email to client
 */
function sendNATConnectionEmail($vars, $config, $result, $vpsIp) {
    try {
        // Get client details
        $client = Capsule::table('tblclients')->where('id', $vars['userid'])->first();
        if (!$client) {
            logActivity('Virtualizor NAT Hook: Client not found for user ID ' . $vars['userid']);
            return;
        }
        
        // Get service details
        $service = Capsule::table('tblhosting')->where('id', $vars['serviceid'])->first();
        
        // Prepare email variables
        $emailVars = [
            'client_name' => $client->firstname . ' ' . $client->lastname,
            'service_id' => $vars['serviceid'],
            'domain' => $vars['domain'],
            'vps_private_ip' => $vpsIp,
            'public_ip' => $result['public_ip'],
            'public_port' => $result['public_port'],
            'dest_port' => $result['dest_port'],
            'root_username' => 'root',
            'root_password' => $vars['password'],
            'ssh_command' => "ssh root@{$result['public_ip']} -p {$result['public_port']}",
            'connection_example' => "To connect via SSH:\nssh root@{$result['public_ip']} -p {$result['public_port']}"
        ];
        
        // Create and send email
        $templateName = $config['nat_email_template'] ?? 'NAT VPS Connection Details';
        
        // Try to find existing email template
        $template = Capsule::table('tblemailtemplates')
            ->where('name', $templateName)
            ->first();
        
        if (!$template) {
            // Create default template if it doesn't exist
            createDefaultNATEmailTemplate($templateName);
        }
        
        // Send email using WHMCS mail system
        $mail = new Message();
        $mail->setTo($client->email);
        $mail->setSubject('NAT VPS Connection Details - Service #' . $vars['serviceid']);
        
        $emailBody = generateNATEmailBody($emailVars);
        $mail->setBody($emailBody);
        $mail->send();
        
        logActivity('Virtualizor NAT Hook: Connection details email sent to ' . $client->email . 
                   ' for service ID ' . $vars['serviceid']);
        
    } catch (Exception $e) {
        logActivity('Virtualizor NAT Hook: Failed to send email - ' . $e->getMessage());
    }
}

/**
 * Create default NAT email template
 */
function createDefaultNATEmailTemplate($templateName) {
    $subject = 'NAT VPS Connection Details - Service #{$service_id}';
    $message = 'Dear {$client_name},

Your NAT VPS service has been successfully provisioned. Below are your connection details:

Service Details:
- Service ID: {$service_id}
- Domain: {$domain}
- Private IP Address: {$vps_private_ip}

Connection Information:
- Public IP Address: {$public_ip}
- Public Port: {$public_port}
- Internal Port: {$dest_port}

Login Credentials:
- Username: {$root_username}
- Password: {$root_password}

SSH Connection:
{$ssh_command}

Example Connection:
{$connection_example}

Please save these connection details for future reference. You will need to use the public IP and port to access your VPS from the internet.

If you have any questions, please don\'t hesitate to contact our support team.

Best regards,
Support Team';
    
    Capsule::table('tblemailtemplates')->insert([
        'type' => 'product',
        'name' => $templateName,
        'subject' => $subject,
        'message' => $message,
        'fromname' => '',
        'fromemail' => '',
        'disabled' => 0,
        'custom' => 1,
        'language' => '',
        'copyto' => '',
        'plaintext' => 0
    ]);
}

/**
 * Generate NAT email body
 */
function generateNATEmailBody($vars) {
    $body = "Dear {$vars['client_name']},

Your NAT VPS service has been successfully provisioned. Below are your connection details:

Service Details:
- Service ID: {$vars['service_id']}
- Domain: {$vars['domain']}
- Private IP Address: {$vars['vps_private_ip']}

Connection Information:
- Public IP Address: {$vars['public_ip']}
- Public Port: {$vars['public_port']}
- Internal Port: {$vars['dest_port']}

Login Credentials:
- Username: {$vars['root_username']}
- Password: {$vars['root_password']}

SSH Connection Command:
{$vars['ssh_command']}

Connection Example:
{$vars['connection_example']}

Please save these connection details for future reference. You will need to use the public IP and port to access your VPS from the internet.

If you have any questions, please don't hesitate to contact our support team.

Best regards,
Support Team";

    return $body;
}

/**
 * Helper function to decrypt WHMCS passwords
 */
function decrypt($encrypted) {
    // WHMCS password decryption
    if (function_exists('decrypt')) {
        return decrypt($encrypted);
    }
    
    // Fallback for older WHMCS versions
    if (function_exists('whmcs_decrypt')) {
        return whmcs_decrypt($encrypted);
    }
    
    return $encrypted;
}