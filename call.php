<?php
/**
 * Emergency Call Receiving and Logging System - UI Only
 * User interface for emergency call logging
 */

$pageTitle = 'Emergency Call Center';
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
    <link rel="stylesheet" href="css/sidebar-footer.css">
    <link rel="stylesheet" href="CSS/cards.css">
    <link rel="stylesheet" href="css/call.css">
  
</head>
<body>
    <!-- Include Sidebar Component -->
    <?php include 'includes/sidebar.php'; ?>

    <!-- Include Admin Header Component -->
    <?php include 'includes/admin-header.php'; ?>

    <!-- ===================================
       MAIN CONTENT - Call Receiving and Logging
       =================================== -->
    <div class="main-content">
        <div class="main-container">

            <!-- Call Receiving Form -->
            <div class="call-form-container">
                <h2 class="section-title">
                    <i class="fas fa-phone"></i>
                    Emergency Call Receiving
                </h2>

                <form method="POST" action="">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="caller_name">Caller Name *</label>
                            <input type="text" id="caller_name" name="caller_name" required>
                        </div>

                        <div class="form-group">
                            <label for="caller_phone">Caller Phone *</label>
                            <input type="tel" id="caller_phone" name="caller_phone" required>
                        </div>

                        <div class="form-group">
                            <label for="incident_type">Incident Type *</label>
                            <select id="incident_type" name="incident_type" required>
                                <option value="">Select Incident Type</option>
                                <option value="Medical Emergency">Medical Emergency</option>
                                <option value="Fire">Fire</option>
                                <option value="Police Emergency">Police Emergency</option>
                                <option value="Traffic Accident">Traffic Accident</option>
                                <option value="Natural Disaster">Natural Disaster</option>
                                <option value="Hazardous Material">Hazardous Material</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="priority">Priority Level</label>
                            <select id="priority" name="priority">
                                <option value="low">Low</option>
                                <option value="medium" selected>Medium</option>
                                <option value="high">High</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="location">Location *</label>
                            <input type="text" id="location" name="location" placeholder="Street address, landmarks, etc." required>
                        </div>

                        <div class="form-group">
                            <label for="description">Incident Description *</label>
                            <textarea id="description" name="description" placeholder="Describe the emergency situation..." required></textarea>
                        </div>

                        <div class="form-group" style="grid-column: span 2;">
                            <label for="dispatcher_notes">Dispatcher Notes</label>
                            <textarea id="dispatcher_notes" name="dispatcher_notes" placeholder="Additional notes from dispatcher..."></textarea>
                        </div>
                    </div>

                    <button type="submit" class="btn-submit">
                        <i class="fas fa-save"></i>
                        Log Emergency Call
                    </button>
                </form>
            </div>

            <!-- Recent Call Logs -->
            <div class="call-form-container">
                <h2 class="section-title">
                    <i class="fas fa-history"></i>
                    Recent Call Logs
                </h2>

                <table class="call-logs-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Caller</th>
                            <th>Incident</th>
                            <th>Location</th>
                            <th>Priority</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Sample data for demonstration -->
                        <tr>
                            <td>Dec 12, 14:30</td>
                            <td>John Smith<br><small>+1-555-0123</small></td>
                            <td>Medical Emergency</td>
                            <td>123 Main St, Downtown</td>
                            <td><span class="priority-high">High</span></td>
                            <td><span class="status-active">Active</span></td>
                        </tr>
                        <tr>
                            <td>Dec 12, 14:15</td>
                            <td>Sarah Johnson<br><small>+1-555-0456</small></td>
                            <td>Fire</td>
                            <td>456 Oak Ave, Residential</td>
                            <td><span class="priority-high">High</span></td>
                            <td><span class="status-dispatched">Dispatched</span></td>
                        </tr>
                        <tr>
                            <td>Dec 12, 14:00</td>
                            <td>Mike Davis<br><small>+1-555-0789</small></td>
                            <td>Traffic Accident</td>
                            <td>Highway 101, Mile 45</td>
                            <td><span class="priority-medium">Medium</span></td>
                            <td><span class="status-active">Active</span></td>
                        </tr>
                        <tr>
                            <td>Dec 12, 13:45</td>
                            <td>Lisa Brown<br><small>+1-555-0321</small></td>
                            <td>Police Emergency</td>
                            <td>789 Pine St, Commercial</td>
                            <td><span class="priority-low">Low</span></td>
                            <td><span class="status-resolved">Resolved</span></td>
                        </tr>
                    </tbody>
                </table>
            </div>

        </div>
    </div>

    <!-- Uncomment if already have content -->
    <?php /* include('includes/admin-footer.php') */ ?>
</body>
</html>