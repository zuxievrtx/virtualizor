<?php
/**
 * WHMCS Virtualizor NAT Email Template Management
 * 
 * Functions for managing NAT-specific email templates
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

/**
 * Create or update NAT email template
 */
function createOrUpdateNATEmailTemplate($templateName = 'NAT VPS Connection Details') {
    $existingTemplate = Capsule::table('tblemailtemplates')
        ->where('name', $templateName)
        ->first();
    
    $subject = 'NAT VPS Connection Details - Service #{$service_id}';
    $message = generateNATEmailTemplate();
    
    if ($existingTemplate) {
        // Update existing template
        Capsule::table('tblemailtemplates')
            ->where('id', $existingTemplate->id)
            ->update([
                'subject' => $subject,
                'message' => $message,
                'custom' => 1
            ]);
        
        logActivity("Updated NAT email template: {$templateName}");
    } else {
        // Create new template
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
        
        logActivity("Created NAT email template: {$templateName}");
    }
    
    return true;
}

/**
 * Generate professional NAT email template
 */
function generateNATEmailTemplate() {
    return '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>NAT VPS Connection Details</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #007bff; color: white; padding: 20px; text-align: center; }
        .content { background: #f8f9fa; padding: 20px; }
        .connection-box { background: white; border: 1px solid #dee2e6; padding: 15px; margin: 15px 0; border-radius: 5px; }
        .ssh-command { background: #343a40; color: #f8f9fa; padding: 10px; font-family: monospace; border-radius: 3px; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
        .important { background: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; border-radius: 5px; margin: 10px 0; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 8px; border-bottom: 1px solid #dee2e6; }
        .label { font-weight: bold; width: 30%; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>NAT VPS Connection Details</h1>
        </div>
        
        <div class="content">
            <p>Dear {$client_firstname} {$client_lastname},</p>
            
            <p>Your NAT VPS service has been successfully provisioned and is ready for use. Below are your connection details and important information.</p>
            
            <div class="connection-box">
                <h3>Service Information</h3>
                <table>
                    <tr>
                        <td class="label">Service ID:</td>
                        <td>#{$service_id}</td>
                    </tr>
                    <tr>
                        <td class="label">Domain:</td>
                        <td>{$domain}</td>
                    </tr>
                    <tr>
                        <td class="label">Private IP Address:</td>
                        <td>{$vps_private_ip}</td>
                    </tr>
                    <tr>
                        <td class="label">Status:</td>
                        <td>Active</td>
                    </tr>
                </table>
            </div>
            
            <div class="connection-box">
                <h3>Connection Details</h3>
                <table>
                    <tr>
                        <td class="label">Public IP Address:</td>
                        <td>{$public_ip}</td>
                    </tr>
                    <tr>
                        <td class="label">Public Port:</td>
                        <td>{$public_port}</td>
                    </tr>
                    <tr>
                        <td class="label">Internal Port:</td>
                        <td>{$dest_port}</td>
                    </tr>
                    <tr>
                        <td class="label">Protocol:</td>
                        <td>TCP</td>
                    </tr>
                </table>
            </div>
            
            <div class="connection-box">
                <h3>Login Credentials</h3>
                <table>
                    <tr>
                        <td class="label">Username:</td>
                        <td>{$root_username}</td>
                    </tr>
                    <tr>
                        <td class="label">Password:</td>
                        <td>{$root_password}</td>
                    </tr>
                </table>
            </div>
            
            <div class="important">
                <h4>🔒 Security Notice</h4>
                <p>Please change your root password immediately after your first login for security purposes.</p>
            </div>
            
            <div class="connection-box">
                <h3>SSH Connection</h3>
                <p>To connect to your VPS via SSH, use the following command:</p>
                <div class="ssh-command">{$ssh_command}</div>
                
                <p><strong>Alternative connection methods:</strong></p>
                <ul>
                    <li>PuTTY (Windows): Host: {$public_ip}, Port: {$public_port}</li>
                    <li>Terminal (Mac/Linux): Use the SSH command above</li>
                    <li>Mobile SSH clients: Use the IP and port information</li>
                </ul>
            </div>
            
            <div class="important">
                <h4>📋 Important Notes</h4>
                <ul>
                    <li>Your VPS uses Network Address Translation (NAT)</li>
                    <li>Always use the <strong>public IP and port</strong> to connect from the internet</li>
                    <li>The private IP is only accessible within the internal network</li>
                    <li>Port {$public_port} is exclusively assigned to your service</li>
                    <li>Save these connection details for future reference</li>
                </ul>
            </div>
            
            <div class="connection-box">
                <h3>Getting Started</h3>
                <ol>
                    <li>Connect to your VPS using the SSH command above</li>
                    <li>Change the root password: <code>passwd</code></li>
                    <li>Update your system: <code>apt update && apt upgrade</code> (Ubuntu/Debian) or <code>yum update</code> (CentOS/RHEL)</li>
                    <li>Configure your firewall and security settings</li>
                    <li>Install and configure your required software</li>
                </ol>
            </div>
            
            <p>If you experience any issues connecting to your VPS or have questions about your service, please don\'t hesitate to contact our support team.</p>
            
            <p>Welcome aboard, and thank you for choosing our services!</p>
        </div>
        
        <div class="footer">
            <p>This is an automated message. Please do not reply to this email.</p>
            <p>For support, please contact us through your client area or support portal.</p>
        </div>
    </div>
</body>
</html>';
}

/**
 * Send NAT connection details email with enhanced template
 */
function sendEnhancedNATEmail($vars, $config, $result, $vpsIp) {
    try {
        // Get client details
        $client = Capsule::table('tblclients')->where('id', $vars['userid'])->first();
        if (!$client) {
            logActivity('Virtualizor NAT Hook: Client not found for user ID ' . $vars['userid']);
            return false;
        }
        
        // Get service details
        $service = Capsule::table('tblhosting')->where('id', $vars['serviceid'])->first();
        
        // Ensure email template exists
        $templateName = $config['nat_email_template'] ?? 'NAT VPS Connection Details';
        createOrUpdateNATEmailTemplate($templateName);
        
        // Prepare email merge fields
        $mergeFields = [
            'client_firstname' => $client->firstname,
            'client_lastname' => $client->lastname,
            'client_email' => $client->email,
            'service_id' => $vars['serviceid'],
            'domain' => $vars['domain'],
            'vps_private_ip' => $vpsIp,
            'public_ip' => $result['public_ip'],
            'public_port' => $result['public_port'],
            'dest_port' => $result['dest_port'],
            'root_username' => 'root',
            'root_password' => $vars['password'],
            'ssh_command' => "ssh root@{$result['public_ip']} -p {$result['public_port']}",
            'connection_example' => "To connect via SSH:\\nssh root@{$result['public_ip']} -p {$result['public_port']}"
        ];
        
        // Send email using WHMCS template system
        sendMessage($templateName, $vars['userid'], $mergeFields);
        
        logActivity('Virtualizor NAT Hook: Enhanced connection details email sent to ' . $client->email . 
                   ' for service ID ' . $vars['serviceid']);
        
        return true;
        
    } catch (Exception $e) {
        logActivity('Virtualizor NAT Hook: Failed to send enhanced email - ' . $e->getMessage());
        return false;
    }
}

/**
 * Create welcome email template for NAT VPS
 */
function createNATWelcomeTemplate() {
    $templateName = 'NAT VPS Welcome';
    $subject = 'Welcome to Your NAT VPS Service - #{$service_id}';
    
    $message = '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Welcome to Your NAT VPS</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #28a745; color: white; padding: 20px; text-align: center; }
        .content { background: #f8f9fa; padding: 20px; }
        .welcome-box { background: white; border: 1px solid #dee2e6; padding: 15px; margin: 15px 0; border-radius: 5px; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎉 Welcome to Your NAT VPS!</h1>
        </div>
        
        <div class="content">
            <p>Dear {$client_firstname} {$client_lastname},</p>
            
            <p>Congratulations! Your NAT VPS service has been successfully set up and is now ready for use.</p>
            
            <div class="welcome-box">
                <h3>What\'s Next?</h3>
                <ul>
                    <li>Check your email for connection details</li>
                    <li>Connect to your VPS using SSH</li>
                    <li>Secure your server by changing default passwords</li>
                    <li>Install your required software and applications</li>
                </ul>
            </div>
            
            <div class="welcome-box">
                <h3>Need Help?</h3>
                <p>Our support team is here to help you get started:</p>
                <ul>
                    <li>📚 Check our knowledge base for tutorials</li>
                    <li>💬 Contact support through your client area</li>
                    <li>📧 Reply to this email with any questions</li>
                </ul>
            </div>
            
            <p>Thank you for choosing our services. We\'re excited to see what you\'ll build!</p>
        </div>
        
        <div class="footer">
            <p>This is an automated welcome message.</p>
        </div>
    </div>
</body>
</html>';

    // Check if template exists
    $existingTemplate = Capsule::table('tblemailtemplates')
        ->where('name', $templateName)
        ->first();
    
    if (!$existingTemplate) {
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
        
        logActivity("Created NAT welcome email template: {$templateName}");
    }
}