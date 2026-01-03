<?php
/**
 * Buttons Page with Sidebar Integration Only
 * Sample page demonstrating buttons with sidebar navigation (no header)
 */

$pageTitle = 'Buttons - Sidebar Demo';

// Database connection
$host = "localhost";
$user = "root";
$pass = "";
$db   = "ers_db";

$conn = mysqli_connect($host, $user, $pass, $db);

// Check connection
if (!$conn) {
    die("Database Connection Failed: " . mysqli_connect_error());
}

// Fetch Active Incidents
$result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM incidents WHERE status='active'");
$activeIncidents = mysqli_fetch_assoc($result)['total'];

// Fetch Available Responders
$result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM responders WHERE status='available'");
$availableResponders = mysqli_fetch_assoc($result)['total'];

// Fetch Average Time Response
$result = mysqli_query($conn, "SELECT AVG(TIMESTAMPDIFF(MINUTE, time_logged, response_time)) AS avg_time FROM incidents WHERE status='completed'");
$avgResponseTime = mysqli_fetch_assoc($result)['avg_time'] ?? 0;

// Fetch Pending Calls
$result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM calls WHERE status='pending'");
$pendingCalls = mysqli_fetch_assoc($result)['total'];

// Fetch Emergency Logs
$logs = mysqli_query($conn, "SELECT * FROM emergency_logs ORDER BY time_logged DESC");

mysqli_close($conn);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/sidebar.css">
    <link rel="stylesheet" href="css/admin-header.css">
    <link rel="stylesheet" href="css/buttons.css">
    <link rel="stylesheet" href="css/hero.css">
    <link rel="stylesheet" href="css/sidebar-footer.css">
    <link rel="stylesheet" href="CSS/cards.css">
</head>
<body>
    <!-- Include Sidebar Component -->
    <?php include 'includes/sidebar.php'; ?>

    <!-- Include Admin Header Component -->
    <?php include 'includes/admin-header.php'; ?>
    
    <!-- ===================================
       MAIN CONTENT - Button demonstrations and documentation
       =================================== -->
    <div class="main-content">
        <div class="main-container">

            <div class="card-grid" style="display:grid; grid-template-columns:repeat(4, 1fr); gap:1.5rem; padding: 2rem;">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Active Incidents: <?php echo $activeIncidents; ?></h5>
                        <i class="fa-solid fa-fire"></i>
                    </div>
                </div>
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Available Responders: <?php echo $availableResponders; ?></h5>
                        <i class="fa-solid fa-truck-medical"></i>
                    </div>
                </div>
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Avg Time Response: <?php echo round($avgResponseTime, 2); ?> min</h5>
                        <i class="fa-solid fa-clock"></i>
                    </div>
                </div>
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Pending Calls: <?php echo $pendingCalls; ?></h5>
                        <i class="fa-solid fa-phone-volume"></i>
                    </div>
                </div>
            </div>

            <!-- Emergency Logging Section -->
            <div class="logging-container" style="padding: 2rem; background-color: #fff; margin-top: 2rem; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                <div class="logging-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
                    <h2 style="margin: 0; font-size: 1.5rem; color: #333;">Emergency Logging</h2>
                    <div class="logging-actions">
                        <button style="padding: 0.5rem 1rem; margin-left: 1rem; background-color: #f5f5f5; border: 1px solid #ddd; border-radius: 4px; cursor: pointer; font-size: 0.9rem;">Filter</button>
                        <button style="padding: 0.5rem 1rem; margin-left: 0.5rem; background-color: #f5f5f5; border: 1px solid #ddd; border-radius: 4px; cursor: pointer; font-size: 0.9rem;">Refresh</button>
                    </div>
                </div>

                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="border-bottom: 2px solid #e0e0e0; background-color: #f9f9f9;">
                            <th style="text-align: left; padding: 1rem; color: #666; font-weight: 600; font-size: 0.85rem;">PRIORITY</th>
                            <th style="text-align: left; padding: 1rem; color: #666; font-weight: 600; font-size: 0.85rem;">INCIDENT TYPE</th>
                            <th style="text-align: left; padding: 1rem; color: #666; font-weight: 600; font-size: 0.85rem;">LOCATION</th>
                            <th style="text-align: left; padding: 1rem; color: #666; font-weight: 600; font-size: 0.85rem;">ASSIGNED UNIT</th>
                            <th style="text-align: left; padding: 1rem; color: #666; font-weight: 600; font-size: 0.85rem;">STATUS</th>
                            <th style="text-align: left; padding: 1rem; color: #666; font-weight: 600; font-size: 0.85rem;">TIME</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (mysqli_num_rows($logs) > 0): ?>
                            <?php while ($row = mysqli_fetch_assoc($logs)): ?>
                                <tr>
                                    <td style="text-align: left; padding: 1rem;"><?php echo htmlspecialchars($row['priority']); ?></td>
                                    <td style="text-align: left; padding: 1rem;"><?php echo htmlspecialchars($row['incident_type']); ?></td>
                                    <td style="text-align: left; padding: 1rem;"><?php echo htmlspecialchars($row['location']); ?></td>
                                    <td style="text-align: left; padding: 1rem;"><?php echo htmlspecialchars($row['assigned_unit']); ?></td>
                                    <td style="text-align: left; padding: 1rem;"><?php echo htmlspecialchars($row['status']); ?></td>
                                    <td style="text-align: left; padding: 1rem;"><?php echo htmlspecialchars($row['time_logged']); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr style="height: 200px; border-bottom: 1px solid #e0e0e0;">
                                <td colspan="6" style="text-align: center; color: #999; padding: 2rem;">
                                    <div style="font-size: 3rem; margin-bottom: 1rem;">📋</div>
                                    <p style="margin: 0; font-size: 1.1rem;">No active emergencies</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        
        </div>
    </div>

    <!-- Uncomment if already have content -->
    <?php /* include('includes/admin-footer.php') */ ?>
</body>
</html>