<?php
/**
 * WHMCS Virtualizor NAT Port Forwarding Admin Page
 * 
 * Admin interface for managing NAT port forwarding
 * Access via: /modules/servers/virtualizor/nat_admin.php
 */

require_once '../../../init.php';
require_once 'nat_helpers.php';

use WHMCS\Database\Capsule;
use WHMCS\Auth;

// Check admin authentication
if (!Auth::user()) {
    header('Location: ../../../admin/login.php');
    exit;
}

$action = $_REQUEST['action'] ?? 'list';
$message = '';
$error = '';

// Handle actions
switch ($action) {
    case 'delete':
        if (isset($_GET['id'])) {
            $mapping = Capsule::table('mod_virtualizor_port_mappings')->where('id', $_GET['id'])->first();
            if ($mapping) {
                Capsule::table('mod_virtualizor_port_mappings')->where('id', $_GET['id'])->delete();
                $message = "Port mapping deleted successfully";
                logNATActivity("Admin deleted port mapping ID {$_GET['id']} for service {$mapping->service_id}");
            }
        }
        break;
        
    case 'cleanup':
        $cleaned = cleanupOrphanedPortMappings();
        $message = "Cleaned up {$cleaned} orphaned port mappings";
        break;
        
    case 'test_api':
        if (isset($_GET['server_id'])) {
            $result = testVirtualizorConnection($_GET['server_id']);
            if ($result['success']) {
                $message = $result['message'];
            } else {
                $error = $result['error'];
            }
        }
        break;
        
    case 'create_manual':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $serviceId = (int)$_POST['service_id'];
            $srcPort = (int)$_POST['src_port'];
            $destIp = $_POST['dest_ip'];
            $destPort = (int)$_POST['dest_port'];
            $publicIp = $_POST['public_ip'];
            
            // Validate inputs
            if ($serviceId && $srcPort && $destIp && $destPort && $publicIp) {
                // Check if port is already used
                $existing = Capsule::table('mod_virtualizor_port_mappings')
                    ->where('src_port', $srcPort)
                    ->first();
                
                if (!$existing) {
                    storePortMapping($serviceId, $srcPort, $destIp, $destPort, $publicIp);
                    $message = "Manual port mapping created successfully";
                } else {
                    $error = "Port {$srcPort} is already in use";
                }
            } else {
                $error = "All fields are required";
            }
        }
        break;
}

// Get statistics
$stats = getNATStatistics();

// Get all port mappings
$mappings = Capsule::table('mod_virtualizor_port_mappings as pm')
    ->leftJoin('tblhosting as h', 'pm.service_id', '=', 'h.id')
    ->leftJoin('tblclients as c', 'h.userid', '=', 'c.id')
    ->select('pm.*', 'h.domain', 'h.domainstatus', 'c.firstname', 'c.lastname', 'c.email')
    ->orderBy('pm.created_at', 'desc')
    ->get();

// Get Virtualizor servers
$servers = Capsule::table('tblservers')
    ->where('type', 'virtualizor')
    ->get();

?>
<!DOCTYPE html>
<html>
<head>
    <title>NAT Port Forwarding Management</title>
    <link href="../../../admin/templates/blend/css/all.min.css" rel="stylesheet">
    <style>
        .stats-card {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 0.25rem;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        .stats-number {
            font-size: 2rem;
            font-weight: bold;
            color: #007bff;
        }
        .table-responsive {
            margin-top: 1rem;
        }
        .btn-group {
            margin-bottom: 1rem;
        }
        .alert {
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <h1>NAT Port Forwarding Management</h1>
        
        <?php if ($message): ?>
            <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <!-- Statistics -->
        <div class="row">
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-number"><?= $stats['total_mappings'] ?></div>
                    <div>Total Mappings</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-number"><?= $stats['active_mappings'] ?></div>
                    <div>Active Mappings</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-number"><?= $stats['total_available_ports'] ?></div>
                    <div>Available Ports</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-number"><?= number_format($stats['port_utilization'], 1) ?>%</div>
                    <div>Port Utilization</div>
                </div>
            </div>
        </div>
        
        <!-- Action buttons -->
        <div class="btn-group">
            <a href="?action=cleanup" class="btn btn-warning" onclick="return confirm('Are you sure you want to cleanup orphaned mappings?')">
                Cleanup Orphaned
            </a>
            <button class="btn btn-primary" data-toggle="modal" data-target="#createManualModal">
                Create Manual Mapping
            </button>
        </div>
        
        <!-- Server API Test -->
        <div class="card">
            <div class="card-header">Test Virtualizor API Connection</div>
            <div class="card-body">
                <form method="get" class="form-inline">
                    <input type="hidden" name="action" value="test_api">
                    <select name="server_id" class="form-control mr-2" required>
                        <option value="">Select Server</option>
                        <?php foreach ($servers as $server): ?>
                            <option value="<?= $server->id ?>"><?= $server->name ?> (<?= $server->ipaddress ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-info">Test Connection</button>
                </form>
            </div>
        </div>
        
        <!-- Port Mappings Table -->
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Service</th>
                        <th>Client</th>
                        <th>Public IP:Port</th>
                        <th>Destination</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mappings as $mapping): ?>
                        <tr>
                            <td><?= $mapping->id ?></td>
                            <td>
                                #<?= $mapping->service_id ?><br>
                                <small><?= htmlspecialchars($mapping->domain) ?></small>
                            </td>
                            <td>
                                <?= htmlspecialchars($mapping->firstname . ' ' . $mapping->lastname) ?><br>
                                <small><?= htmlspecialchars($mapping->email) ?></small>
                            </td>
                            <td><?= $mapping->public_ip ?>:<?= $mapping->src_port ?></td>
                            <td><?= $mapping->dest_ip ?>:<?= $mapping->dest_port ?></td>
                            <td>
                                <span class="badge badge-<?= $mapping->domainstatus === 'Active' ? 'success' : 'secondary' ?>">
                                    <?= $mapping->domainstatus ?: 'Unknown' ?>
                                </span>
                            </td>
                            <td><?= date('Y-m-d H:i', strtotime($mapping->created_at)) ?></td>
                            <td>
                                <a href="?action=delete&id=<?= $mapping->id ?>" 
                                   class="btn btn-sm btn-danger"
                                   onclick="return confirm('Are you sure you want to delete this mapping?')">
                                    Delete
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Create Manual Mapping Modal -->
    <div class="modal fade" id="createManualModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post">
                    <input type="hidden" name="action" value="create_manual">
                    <div class="modal-header">
                        <h5 class="modal-title">Create Manual Port Mapping</h5>
                        <button type="button" class="close" data-dismiss="modal">
                            <span>&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="form-group">
                            <label>Service ID</label>
                            <input type="number" name="service_id" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Public IP</label>
                            <input type="text" name="public_ip" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Source Port (Public)</label>
                            <input type="number" name="src_port" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Destination IP (Private)</label>
                            <input type="text" name="dest_ip" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Destination Port</label>
                            <input type="number" name="dest_port" class="form-control" value="22" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Mapping</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="../../../admin/templates/blend/js/bootstrap.bundle.min.js"></script>
</body>
</html>