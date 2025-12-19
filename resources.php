<?php
/**
 * Emergency Resources Status Management - UI Only
 * Comprehensive resource tracking and management system
 */

$pageTitle = 'Resources Status Management';
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
    <link rel="stylesheet" href="css/resources.css">
</head>
<body>
    <!-- Include Sidebar Component -->
    <?php include 'includes/sidebar.php'; ?>

    <!-- Include Admin Header Component -->
    <?php include 'includes/admin-header.php'; ?>

    <!-- ===================================
       MAIN CONTENT - Emergency Resources Status
       =================================== -->
    <div class="main-content">
        <div class="main-container">

            <h1 style="font-size: 2rem; font-weight: 700; color: #333; margin-bottom: 2rem; display: flex; align-items: center;">
                <i class="fas fa-truck" style="margin-right: 0.5rem; color: #dc3545;"></i>
                Emergency Resources Status
            </h1>

            <!-- Resource Overview -->
            <div class="resource-overview">
                <div class="overview-card">
                    <div class="overview-icon vehicles">
                        <i class="fas fa-ambulance"></i>
                    </div>
                    <div class="overview-value">24</div>
                    <div class="overview-label">Total Vehicles</div>
                </div>
                <div class="overview-card">
                    <div class="overview-icon personnel">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="overview-value">156</div>
                    <div class="overview-label">Active Personnel</div>
                </div>
                <div class="overview-card">
                    <div class="overview-icon equipment">
                        <i class="fas fa-toolbox"></i>
                    </div>
                    <div class="overview-value">89</div>
                    <div class="overview-label">Equipment Items</div>
                </div>
                <div class="overview-card">
                    <div class="overview-icon maintenance">
                        <i class="fas fa-wrench"></i>
                    </div>
                    <div class="overview-value">7</div>
                    <div class="overview-label">Under Maintenance</div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions">
                <button class="quick-action-btn" onclick="requestResource()">
                    <i class="fas fa-plus-circle"></i>
                    Request Resource
                </button>
                <button class="quick-action-btn" onclick="scheduleMaintenance()">
                    <i class="fas fa-calendar-check"></i>
                    Schedule Maintenance
                </button>
                <button class="quick-action-btn" onclick="emergencyAllocation()">
                    <i class="fas fa-exclamation-triangle"></i>
                    Emergency Allocation
                </button>
                <button class="quick-action-btn" onclick="resourceReport()">
                    <i class="fas fa-chart-bar"></i>
                    Generate Report
                </button>
            </div>

            <!-- Resource Filters -->
            <div class="resource-filters">
                <h2 style="font-size: 1.25rem; font-weight: 700; color: #333; margin-bottom: 1.5rem; display: flex; align-items: center;">
                    <i class="fas fa-filter" style="margin-right: 0.5rem; color: #007bff;"></i>
                    Resource Filters
                </h2>
                <div class="filter-grid">
                    <div class="filter-group">
                        <label for="resource-type">Resource Type</label>
                        <select id="resource-type">
                            <option value="">All Resources</option>
                            <option value="vehicles">Vehicles</option>
                            <option value="personnel">Personnel</option>
                            <option value="equipment">Equipment</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="status-filter">Status</label>
                        <select id="status-filter">
                            <option value="">All Status</option>
                            <option value="available">Available</option>
                            <option value="inuse">In Use</option>
                            <option value="maintenance">Maintenance</option>
                            <option value="offline">Offline</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="location-filter">Location</label>
                        <select id="location-filter">
                            <option value="">All Locations</option>
                            <option value="station-1">Station 1</option>
                            <option value="station-2">Station 2</option>
                            <option value="station-3">Station 3</option>
                            <option value="enroute">En Route</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="search-resource">Search</label>
                        <input type="text" id="search-resource" placeholder="Search resources...">
                    </div>
                </div>
            </div>

            <!-- Resource Tabs -->
            <div class="resource-tabs">
                <button class="resource-tab active" onclick="switchResourceTab('vehicles')">Vehicles</button>
                <button class="resource-tab" onclick="switchResourceTab('personnel')">Personnel</button>
                <button class="resource-tab" onclick="switchResourceTab('equipment')">Equipment</button>
            </div>

            <!-- Vehicles Tab -->
            <div id="vehicles" class="resource-tab-content active">
                <div class="resources-grid">
                    <div class="resource-card available" data-type="vehicles" data-status="available">
                        <div class="resource-header">
                            <h3 class="resource-title">Ambulance #5</h3>
                            <span class="resource-status status-available">Available</span>
                        </div>
                        <div class="resource-details">
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-map-marker-alt"></i></span>
                                <span class="detail-value">Station 1</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-gas-pump"></i></span>
                                <span class="detail-value">85% Fuel</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-tachometer-alt"></i></span>
                                <span class="detail-value">0 mph</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-clock"></i></span>
                                <span class="detail-value">15 min idle</span>
                            </div>
                        </div>
                        <div class="resource-metrics">
                            <div class="metric">
                                <span class="metric-value">12</span>
                                <span class="metric-label">Calls</span>
                            </div>
                            <div class="metric">
                                <span class="metric-value">98%</span>
                                <span class="metric-label">Uptime</span>
                            </div>
                            <div class="metric">
                                <span class="metric-value">15k</span>
                                <span class="metric-label">Mileage</span>
                            </div>
                        </div>
                        <div class="resource-actions">
                            <button class="btn-resource" onclick="deployResource(this)">
                                <i class="fas fa-play"></i> Deploy
                            </button>
                            <button class="btn-resource" onclick="trackResource(this)">
                                <i class="fas fa-location-arrow"></i> Track
                            </button>
                            <button class="btn-resource" onclick="serviceResource(this)">
                                <i class="fas fa-wrench"></i> Service
                            </button>
                            <button class="btn-resource" onclick="resourceDetails(this)">
                                <i class="fas fa-info-circle"></i> Details
                            </button>
                        </div>
                    </div>

                    <div class="resource-card inuse" data-type="vehicles" data-status="inuse">
                        <div class="resource-header">
                            <h3 class="resource-title">Police Unit #8</h3>
                            <span class="resource-status status-inuse">In Use</span>
                        </div>
                        <div class="resource-details">
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-map-marker-alt"></i></span>
                                <span class="detail-value">Downtown</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-gas-pump"></i></span>
                                <span class="detail-value">67% Fuel</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-tachometer-alt"></i></span>
                                <span class="detail-value">35 mph</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-clock"></i></span>
                                <span class="detail-value">En Route</span>
                            </div>
                        </div>
                        <div class="resource-metrics">
                            <div class="metric">
                                <span class="metric-value">8</span>
                                <span class="metric-label">Calls</span>
                            </div>
                            <div class="metric">
                                <span class="metric-value">95%</span>
                                <span class="metric-label">Uptime</span>
                            </div>
                            <div class="metric">
                                <span class="metric-value">22k</span>
                                <span class="metric-label">Mileage</span>
                            </div>
                        </div>
                        <div class="resource-actions">
                            <button class="btn-resource active" onclick="deployResource(this)">
                                <i class="fas fa-play"></i> Deploy
                            </button>
                            <button class="btn-resource" onclick="trackResource(this)">
                                <i class="fas fa-location-arrow"></i> Track
                            </button>
                            <button class="btn-resource" onclick="serviceResource(this)">
                                <i class="fas fa-wrench"></i> Service
                            </button>
                            <button class="btn-resource" onclick="resourceDetails(this)">
                                <i class="fas fa-info-circle"></i> Details
                            </button>
                        </div>
                    </div>

                    <div class="resource-card maintenance" data-type="vehicles" data-status="maintenance">
                        <div class="resource-header">
                            <h3 class="resource-title">Engine #12</h3>
                            <span class="resource-status status-maintenance">Maintenance</span>
                        </div>
                        <div class="resource-details">
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-map-marker-alt"></i></span>
                                <span class="detail-value">Service Bay</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-gas-pump"></i></span>
                                <span class="detail-value">Full Tank</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-tachometer-alt"></i></span>
                                <span class="detail-value">Stationary</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-clock"></i></span>
                                <span class="detail-value">2 days</span>
                            </div>
                        </div>
                        <div class="resource-metrics">
                            <div class="metric">
                                <span class="metric-value">15</span>
                                <span class="metric-label">Calls</span>
                            </div>
                            <div class="metric">
                                <span class="metric-value">89%</span>
                                <span class="metric-label">Uptime</span>
                            </div>
                            <div class="metric">
                                <span class="metric-value">45k</span>
                                <span class="metric-label">Mileage</span>
                            </div>
                        </div>
                        <div class="resource-actions">
                            <button class="btn-resource" onclick="completeMaintenance(this)">
                                <i class="fas fa-check"></i> Complete
                            </button>
                            <button class="btn-resource" onclick="trackResource(this)">
                                <i class="fas fa-location-arrow"></i> Track
                            </button>
                            <button class="btn-resource" onclick="serviceResource(this)">
                                <i class="fas fa-wrench"></i> Service
                            </button>
                            <button class="btn-resource" onclick="resourceDetails(this)">
                                <i class="fas fa-info-circle"></i> Details
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Personnel Tab -->
            <div id="personnel" class="resource-tab-content">
                <div class="resources-grid">
                    <div class="resource-card available" data-type="personnel" data-status="available">
                        <div class="resource-header">
                            <h3 class="resource-title">Dr. Sarah Johnson</h3>
                            <span class="resource-status status-available">Available</span>
                        </div>
                        <div class="resource-details">
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-user-md"></i></span>
                                <span class="detail-value">Paramedic</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-map-marker-alt"></i></span>
                                <span class="detail-value">Station 1</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-clock"></i></span>
                                <span class="detail-value">8 hrs shift</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-star"></i></span>
                                <span class="detail-value">Senior</span>
                            </div>
                        </div>
                        <div class="resource-metrics">
                            <div class="metric">
                                <span class="metric-value">45</span>
                                <span class="metric-label">Responses</span>
                            </div>
                            <div class="metric">
                                <span class="metric-value">96%</span>
                                <span class="metric-label">Success</span>
                            </div>
                            <div class="metric">
                                <span class="metric-value">3</span>
                                <span class="metric-label">Years</span>
                            </div>
                        </div>
                        <div class="resource-actions">
                            <button class="btn-resource" onclick="assignPersonnel(this)">
                                <i class="fas fa-user-plus"></i> Assign
                            </button>
                            <button class="btn-resource" onclick="contactPersonnel(this)">
                                <i class="fas fa-phone"></i> Contact
                            </button>
                            <button class="btn-resource" onclick="personnelSchedule(this)">
                                <i class="fas fa-calendar"></i> Schedule
                            </button>
                            <button class="btn-resource" onclick="resourceDetails(this)">
                                <i class="fas fa-info-circle"></i> Details
                            </button>
                        </div>
                    </div>

                    <div class="resource-card inuse" data-type="personnel" data-status="inuse">
                        <div class="resource-header">
                            <h3 class="resource-title">Officer Mike Davis</h3>
                            <span class="resource-status status-inuse">On Duty</span>
                        </div>
                        <div class="resource-details">
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-shield-alt"></i></span>
                                <span class="detail-value">Police Officer</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-map-marker-alt"></i></span>
                                <span class="detail-value">Downtown Patrol</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-clock"></i></span>
                                <span class="detail-value">6 hrs on duty</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-star"></i></span>
                                <span class="detail-value">Veteran</span>
                            </div>
                        </div>
                        <div class="resource-metrics">
                            <div class="metric">
                                <span class="metric-value">78</span>
                                <span class="metric-label">Incidents</span>
                            </div>
                            <div class="metric">
                                <span class="metric-value">92%</span>
                                <span class="metric-label">Resolution</span>
                            </div>
                            <div class="metric">
                                <span class="metric-value">8</span>
                                <span class="metric-label">Years</span>
                            </div>
                        </div>
                        <div class="resource-actions">
                            <button class="btn-resource active" onclick="assignPersonnel(this)">
                                <i class="fas fa-user-plus"></i> Assign
                            </button>
                            <button class="btn-resource" onclick="contactPersonnel(this)">
                                <i class="fas fa-phone"></i> Contact
                            </button>
                            <button class="btn-resource" onclick="personnelSchedule(this)">
                                <i class="fas fa-calendar"></i> Schedule
                            </button>
                            <button class="btn-resource" onclick="resourceDetails(this)">
                                <i class="fas fa-info-circle"></i> Details
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Equipment Tab -->
            <div id="equipment" class="resource-tab-content">
                <div class="resources-grid">
                    <div class="resource-card available" data-type="equipment" data-status="available">
                        <div class="resource-header">
                            <h3 class="resource-title">Defibrillator Unit</h3>
                            <span class="resource-status status-available">Available</span>
                        </div>
                        <div class="resource-details">
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-heartbeat"></i></span>
                                <span class="detail-value">Medical Equipment</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-map-marker-alt"></i></span>
                                <span class="detail-value">Ambulance #5</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-battery-full"></i></span>
                                <span class="detail-value">95% Charge</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-calendar"></i></span>
                                <span class="detail-value">Calibrated</span>
                            </div>
                        </div>
                        <div class="resource-metrics">
                            <div class="metric">
                                <span class="metric-value">23</span>
                                <span class="metric-label">Uses</span>
                            </div>
                            <div class="metric">
                                <span class="metric-value">100%</span>
                                <span class="metric-label">Reliable</span>
                            </div>
                            <div class="metric">
                                <span class="metric-value">6mo</span>
                                <span class="metric-label">Age</span>
                            </div>
                        </div>
                        <div class="resource-actions">
                            <button class="btn-resource" onclick="assignEquipment(this)">
                                <i class="fas fa-link"></i> Assign
                            </button>
                            <button class="btn-resource" onclick="checkEquipment(this)">
                                <i class="fas fa-check-circle"></i> Check
                            </button>
                            <button class="btn-resource" onclick="calibrateEquipment(this)">
                                <i class="fas fa-tools"></i> Calibrate
                            </button>
                            <button class="btn-resource" onclick="resourceDetails(this)">
                                <i class="fas fa-info-circle"></i> Details
                            </button>
                        </div>
                    </div>

                    <div class="resource-card maintenance" data-type="equipment" data-status="maintenance">
                        <div class="resource-header">
                            <h3 class="resource-title">Jaws of Life</h3>
                            <span class="resource-status status-maintenance">Maintenance</span>
                        </div>
                        <div class="resource-details">
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-cut"></i></span>
                                <span class="detail-value">Rescue Equipment</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-map-marker-alt"></i></span>
                                <span class="detail-value">Service Center</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-battery-half"></i></span>
                                <span class="detail-value">Hydraulics Check</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-calendar"></i></span>
                                <span class="detail-value">Due Soon</span>
                            </div>
                        </div>
                        <div class="resource-metrics">
                            <div class="metric">
                                <span class="metric-value">45</span>
                                <span class="metric-label">Uses</span>
                            </div>
                            <div class="metric">
                                <span class="metric-value">94%</span>
                                <span class="metric-label">Reliable</span>
                            </div>
                            <div class="metric">
                                <span class="metric-value">2yr</span>
                                <span class="metric-label">Age</span>
                            </div>
                        </div>
                        <div class="resource-actions">
                            <button class="btn-resource" onclick="completeMaintenance(this)">
                                <i class="fas fa-check"></i> Complete
                            </button>
                            <button class="btn-resource" onclick="checkEquipment(this)">
                                <i class="fas fa-check-circle"></i> Check
                            </button>
                            <button class="btn-resource" onclick="calibrateEquipment(this)">
                                <i class="fas fa-tools"></i> Calibrate
                            </button>
                            <button class="btn-resource" onclick="resourceDetails(this)">
                                <i class="fas fa-info-circle"></i> Details
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Maintenance Schedule -->
            <div class="maintenance-schedule">
                <h2 style="font-size: 1.25rem; font-weight: 700; color: #333; margin-bottom: 1.5rem; display: flex; align-items: center;">
                    <i class="fas fa-tools" style="margin-right: 0.5rem; color: #dc3545;"></i>
                    Maintenance Schedule
                </h2>

                <div class="maintenance-item urgent">
                    <div class="maintenance-info">
                        <div class="maintenance-title">Engine #12 - Hydraulic System Service</div>
                        <div class="maintenance-details">Due: Today • Priority: High • Estimated: 4 hours</div>
                    </div>
                    <div class="maintenance-actions">
                        <button class="btn-resource" onclick="scheduleMaintenanceItem(this)">
                            <i class="fas fa-calendar-plus"></i> Schedule
                        </button>
                        <button class="btn-resource" onclick="viewMaintenanceDetails(this)">
                            <i class="fas fa-eye"></i> Details
                        </button>
                    </div>
                </div>

                <div class="maintenance-item">
                    <div class="maintenance-info">
                        <div class="maintenance-title">Ambulance #3 - Oil Change</div>
                        <div class="maintenance-details">Due: Tomorrow • Priority: Medium • Estimated: 2 hours</div>
                    </div>
                    <div class="maintenance-actions">
                        <button class="btn-resource" onclick="scheduleMaintenanceItem(this)">
                            <i class="fas fa-calendar-plus"></i> Schedule
                        </button>
                        <button class="btn-resource" onclick="viewMaintenanceDetails(this)">
                            <i class="fas fa-eye"></i> Details
                        </button>
                    </div>
                </div>

                <div class="maintenance-item">
                    <div class="maintenance-info">
                        <div class="maintenance-title">Defibrillator Units - Calibration</div>
                        <div class="maintenance-details">Due: This Week • Priority: Low • Estimated: 1 hour</div>
                    </div>
                    <div class="maintenance-actions">
                        <button class="btn-resource" onclick="scheduleMaintenanceItem(this)">
                            <i class="fas fa-calendar-plus"></i> Schedule
                        </button>
                        <button class="btn-resource" onclick="viewMaintenanceDetails(this)">
                            <i class="fas fa-eye"></i> Details
                        </button>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Uncomment if already have content -->
    <?php /* include('includes/admin-footer.php') */ ?>

    <script>
        // Emergency Resources Management Functionality

        let currentTab = 'vehicles';

        // Tab switching functionality
        function switchResourceTab(tabName) {
            // Update tab buttons
            document.querySelectorAll('.resource-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            event.target.classList.add('active');

            // Update content
            document.querySelectorAll('.resource-tab-content').forEach(content => {
                content.classList.remove('active');
            });
            document.getElementById(tabName).classList.add('active');

            currentTab = tabName;
            showNotification(`${tabName.charAt(0).toUpperCase() + tabName.slice(1)} resources loaded`, 'info');
        }

        // Resource deployment functionality
        function deployResource(button) {
            const resourceCard = button.closest('.resource-card');
            const resourceName = resourceCard.querySelector('.resource-title').textContent;

            if (confirm(`Deploy ${resourceName} to emergency response?`)) {
                // Update status
                resourceCard.classList.remove('available', 'inuse', 'maintenance', 'offline');
                resourceCard.classList.add('inuse');

                const statusBadge = resourceCard.querySelector('.resource-status');
                statusBadge.className = 'resource-status status-inuse';
                statusBadge.textContent = 'In Use';

                // Update button
                button.classList.add('active');

                showNotification(`${resourceName} deployed successfully`, 'success');
            }
        }

        // Resource tracking functionality
        function trackResource(button) {
            const resourceCard = button.closest('.resource-card');
            const resourceName = resourceCard.querySelector('.resource-title').textContent;

            showNotification(`Tracking ${resourceName}... GPS coordinates: 40.7128° N, 74.0060° W`, 'info');
        }

        // Resource service functionality
        function serviceResource(button) {
            const resourceCard = button.closest('.resource-card');
            const resourceName = resourceCard.querySelector('.resource-title').textContent;

            if (confirm(`Schedule maintenance for ${resourceName}?`)) {
                // Update status
                resourceCard.classList.remove('available', 'inuse', 'maintenance', 'offline');
                resourceCard.classList.add('maintenance');

                const statusBadge = resourceCard.querySelector('.resource-status');
                statusBadge.className = 'resource-status status-maintenance';
                statusBadge.textContent = 'Maintenance';

                showNotification(`Maintenance scheduled for ${resourceName}`, 'info');
            }
        }

        // Complete maintenance functionality
        function completeMaintenance(button) {
            const resourceCard = button.closest('.resource-card');
            const resourceName = resourceCard.querySelector('.resource-title').textContent;

            if (confirm(`Mark maintenance complete for ${resourceName}?`)) {
                // Update status
                resourceCard.classList.remove('available', 'inuse', 'maintenance', 'offline');
                resourceCard.classList.add('available');

                const statusBadge = resourceCard.querySelector('.resource-status');
                statusBadge.className = 'resource-status status-available';
                statusBadge.textContent = 'Available';

                showNotification(`Maintenance completed for ${resourceName}`, 'success');
            }
        }

        // Resource details functionality
        function resourceDetails(button) {
            const resourceCard = button.closest('.resource-card');
            const resourceName = resourceCard.querySelector('.resource-title').textContent;
            const resourceType = resourceCard.dataset.type;

            let details = `Resource: ${resourceName}\nType: ${resourceType}\n\n`;

            if (resourceType === 'vehicles') {
                details += '• Vehicle specifications\n• Maintenance history\n• Fuel efficiency\n• Usage statistics\n• GPS tracking data';
            } else if (resourceType === 'personnel') {
                details += '• Certification details\n• Training records\n• Performance metrics\n• Shift schedule\n• Contact information';
            } else if (resourceType === 'equipment') {
                details += '• Equipment specifications\n• Calibration records\n• Usage history\n• Maintenance schedule\n• Storage location';
            }

            alert(details);
        }

        // Personnel management functions
        function assignPersonnel(button) {
            const resourceCard = button.closest('.resource-card');
            const personnelName = resourceCard.querySelector('.resource-title').textContent;

            const assignment = prompt(`Assign ${personnelName} to which incident/unit?`);
            if (assignment) {
                button.classList.add('active');
                showNotification(`${personnelName} assigned to ${assignment}`, 'success');
            }
        }

        function contactPersonnel(button) {
            const resourceCard = button.closest('.resource-card');
            const personnelName = resourceCard.querySelector('.resource-title').textContent;

            if (confirm(`Call ${personnelName}?`)) {
                showNotification(`Calling ${personnelName}...`, 'info');
            }
        }

        function personnelSchedule(button) {
            const resourceCard = button.closest('.resource-card');
            const personnelName = resourceCard.querySelector('.resource-title').textContent;

            alert(`${personnelName} Schedule:\n\n• Monday-Friday: 8AM-6PM\n• Weekends: On-call rotation\n• Next shift: Tomorrow 8AM\n• Vacation: 2 weeks remaining`);
        }

        // Equipment management functions
        function assignEquipment(button) {
            const resourceCard = button.closest('.resource-card');
            const equipmentName = resourceCard.querySelector('.resource-title').textContent;

            const assignment = prompt(`Assign ${equipmentName} to which unit/personnel?`);
            if (assignment) {
                showNotification(`${equipmentName} assigned to ${assignment}`, 'success');
            }
        }

        function checkEquipment(button) {
            const resourceCard = button.closest('.resource-card');
            const equipmentName = resourceCard.querySelector('.resource-title').textContent;

            showNotification(`${equipmentName} status check: All systems operational`, 'success');
        }

        function calibrateEquipment(button) {
            const resourceCard = button.closest('.resource-card');
            const equipmentName = resourceCard.querySelector('.resource-title').textContent;

            if (confirm(`Calibrate ${equipmentName}? This may take several minutes.`)) {
                showNotification(`Calibration started for ${equipmentName}`, 'info');
            }
        }

        // Maintenance functions
        function scheduleMaintenanceItem(button) {
            const maintenanceItem = button.closest('.maintenance-item');
            const maintenanceTitle = maintenanceItem.querySelector('.maintenance-title').textContent;

            const date = prompt('Schedule maintenance for when? (MM/DD/YYYY)');
            if (date) {
                showNotification(`${maintenanceTitle} scheduled for ${date}`, 'success');
            }
        }

        function viewMaintenanceDetails(button) {
            const maintenanceItem = button.closest('.maintenance-item');
            const maintenanceTitle = maintenanceItem.querySelector('.maintenance-title').textContent;

            alert(`Maintenance Details: ${maintenanceTitle}\n\n• Required parts: Available\n• Technician: Assigned\n• Estimated cost: $450\n• Downtime: 4 hours\n• Priority: High`);
        }

        // Quick action functions
        function requestResource() {
            const resourceType = prompt('What type of resource do you need?\n• Vehicles\n• Personnel\n• Equipment');
            if (resourceType) {
                showNotification(`Resource request submitted: ${resourceType}`, 'info');
            }
        }

        function scheduleMaintenance() {
            const resource = prompt('Which resource needs maintenance?');
            if (resource) {
                showNotification(`Maintenance scheduled for: ${resource}`, 'info');
            }
        }

        function emergencyAllocation() {
            if (confirm('Activate emergency resource allocation protocol? This will override normal procedures.')) {
                showNotification('Emergency allocation protocol activated', 'error');
            }
        }

        function resourceReport() {
            showNotification('Generating comprehensive resource report...', 'info');
            setTimeout(() => {
                showNotification('Resource report generated and downloaded', 'success');
            }, 2000);
        }

        // Filter functionality
        document.getElementById('resource-type').addEventListener('change', applyFilters);
        document.getElementById('status-filter').addEventListener('change', applyFilters);
        document.getElementById('location-filter').addEventListener('change', applyFilters);
        document.getElementById('search-resource').addEventListener('input', applyFilters);

        function applyFilters() {
            const typeFilter = document.getElementById('resource-type').value;
            const statusFilter = document.getElementById('status-filter').value;
            const locationFilter = document.getElementById('location-filter').value;
            const searchFilter = document.getElementById('search-resource').value.toLowerCase();

            document.querySelectorAll('.resource-card').forEach(card => {
                let showCard = true;

                // Type filter
                if (typeFilter && card.dataset.type !== typeFilter) {
                    showCard = false;
                }

                // Status filter
                if (statusFilter && card.dataset.status !== statusFilter) {
                    showCard = false;
                }

                // Location filter (simplified - would need more complex logic in real system)
                if (locationFilter) {
                    const location = card.querySelector('.detail-value').textContent.toLowerCase();
                    if (!location.includes(locationFilter.replace('-', ' '))) {
                        showCard = false;
                    }
                }

                // Search filter
                if (searchFilter) {
                    const title = card.querySelector('.resource-title').textContent.toLowerCase();
                    const details = card.textContent.toLowerCase();
                    if (!title.includes(searchFilter) && !details.includes(searchFilter)) {
                        showCard = false;
                    }
                }

                card.style.display = showCard ? 'block' : 'none';
            });

            showNotification('Filters applied', 'info');
        }

        // Notification system
        function showNotification(message, type) {
            // Remove existing notifications
            const existingNotifications = document.querySelectorAll('.notification');
            existingNotifications.forEach(notification => notification.remove());

            // Create notification element
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 1rem 1.5rem;
                border-radius: 8px;
                color: white;
                font-weight: 600;
                z-index: 1000;
                animation: slideIn 0.3s ease-out;
                box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            `;

            // Set background color based on type
            if (type === 'success') {
                notification.style.backgroundColor = '#28a745';
            } else if (type === 'error') {
                notification.style.backgroundColor = '#dc3545';
            } else if (type === 'info') {
                notification.style.backgroundColor = '#17a2b8';
            }

            notification.textContent = message;
            document.body.appendChild(notification);

            // Auto remove after 3 seconds
            setTimeout(() => {
                notification.style.animation = 'slideOut 0.3s ease-in';
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }, 3000);
        }

        // Add CSS animations
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideIn {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }

            @keyframes slideOut {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }

            .resource-card, .btn-resource, .resource-tab {
                transition: all 0.3s ease;
            }

            .resource-card:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 16px rgba(0,0,0,0.15);
            }

            .btn-resource:hover {
                transform: translateY(-1px);
                box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            }

            .resource-tab:hover {
                background-color: #f8f9fa;
            }
        `;
        document.head.appendChild(style);

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-refresh resource status simulation
            setInterval(() => {
                // Simulate random status updates
                if (Math.random() < 0.1) {
                    const availableCards = document.querySelectorAll('.resource-card.available');
                    if (availableCards.length > 0) {
                        const randomCard = availableCards[Math.floor(Math.random() * availableCards.length)];
                        const resourceName = randomCard.querySelector('.resource-title').textContent;
                        // Simulate deployment
                        deployResource(randomCard.querySelector('.btn-resource'));
                    }
                }
            }, 30000); // Every 30 seconds
        });
    </script>
</body>
</html>