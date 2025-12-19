<?php
/**
 * Emergency Response System - Admin Dashboard (UI Only)
 * Comprehensive dashboard with static demo data and full functionality
 */
$pageTitle = 'ERS Admin Dashboard';
// Static demo data for UI-only dashboard
$activeIncidents = 0;
$availableResponders = 0;
$avgResponseTime = 0;
$pendingCalls = 0;
$totalIncidents = 0;
$resourceUtilization = 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - <?php echo date('M d, Y'); ?></title>
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/sidebar.css">
    <link rel="stylesheet" href="css/admin-header.css">
    <link rel="stylesheet" href="css/buttons.css">
    <link rel="stylesheet" href="css/sidebar-footer.css">
    <link rel="stylesheet" href="CSS/cards.css">
    <link rel="stylesheet" href="CSS/dashboard.css">
</head>
<body>
    <!-- Include Sidebar Component -->
    <?php include 'includes/sidebar.php'; ?>
    <!-- Include Admin Header Component -->
    <?php include 'includes/admin-header.php'; ?>
    <!-- ===================================
       MAIN CONTENT - Emergency Response System Dashboard (UI Only)
       =================================== -->
    <div class="main-content">
        <div class="main-container">
            <!-- Dashboard Header -->
            <div class="dashboard-header">
                <div>
                    <h1 class="dashboard-title">Emergency Response Dashboard</h1>
                    <p class="dashboard-subtitle">Real-time monitoring and system overview • <?php echo date('l, F j, Y \a\t g:i A'); ?></p>
                </div>
                <div class="dashboard-actions">
                    <button class="btn-dashboard" onclick="refreshDashboard()">
                        <i class="fas fa-sync"></i> Refresh
                    </button>
                    <button class="btn-dashboard" onclick="exportDashboard()">
                        <i class="fas fa-download"></i> Export
                    </button>
                    <button class="btn-dashboard" onclick="systemSettings()">
                        <i class="fas fa-cog"></i> Settings
                    </button>
                </div>
            </div>
            <!-- Key Metrics -->
            <div class="metrics-grid">
                <div class="metric-card critical">
                    <div class="metric-header">
                        <div>
                            <h3 class="metric-title">Active Incidents</h3>
                            <div class="metric-value"><?php echo $activeIncidents; ?></div>
                            <div class="metric-change positive">
                                <i class="fas fa-arrow-down"></i>
                                -8% from yesterday
                            </div>
                        </div>
                        <div class="metric-icon fire">
                            <i class="fas fa-fire"></i>
                        </div>
                    </div>
                    <div class="metric-actions">
                        <button class="btn-metric" onclick="viewIncidents()">
                            <i class="fas fa-eye"></i> View All
                        </button>
                        <button class="btn-metric" onclick="createIncident()">
                            <i class="fas fa-plus"></i> New Incident
                        </button>
                    </div>
                </div>
                <div class="metric-card success">
                    <div class="metric-header">
                        <div>
                            <h3 class="metric-title">Available Responders</h3>
                            <div class="metric-value"><?php echo $availableResponders; ?></div>
                            <div class="metric-change positive">
                                <i class="fas fa-arrow-up"></i>
                                +12% from yesterday
                            </div>
                        </div>
                        <div class="metric-icon medical">
                            <i class="fas fa-truck-medical"></i>
                        </div>
                    </div>
                    <div class="metric-actions">
                        <button class="btn-metric" onclick="viewResponders()">
                            <i class="fas fa-users"></i> Manage
                        </button>
                        <button class="btn-metric" onclick="deployResponder()">
                            <i class="fas fa-play"></i> Deploy
                        </button>
                    </div>
                </div>
                <div class="metric-card warning">
                    <div class="metric-header">
                        <div>
                            <h3 class="metric-title">Avg Response Time</h3>
                            <div class="metric-value"><?php echo number_format($avgResponseTime, 1); ?>m</div>
                            <div class="metric-change negative">
                                <i class="fas fa-arrow-up"></i>
                                +2.3m from target
                            </div>
                        </div>
                        <div class="metric-icon time">
                            <i class="fas fa-clock"></i>
                        </div>
                    </div>
                    <div class="metric-actions">
                        <button class="btn-metric" onclick="viewResponseTimes()">
                            <i class="fas fa-chart-line"></i> Analytics
                        </button>
                        <button class="btn-metric" onclick="optimizeRoutes()">
                            <i class="fas fa-route"></i> Optimize
                        </button>
                    </div>
                </div>
                <div class="metric-card info">
                    <div class="metric-header">
                        <div>
                            <h3 class="metric-title">Pending Calls</h3>
                            <div class="metric-value"><?php echo $pendingCalls; ?></div>
                            <div class="metric-change neutral">
                                <i class="fas fa-minus"></i>
                                Stable
                            </div>
                        </div>
                        <div class="metric-icon phone">
                            <i class="fas fa-phone-volume"></i>
                        </div>
                    </div>
                    <div class="metric-actions">
                        <button class="btn-metric" onclick="viewCalls()">
                            <i class="fas fa-phone"></i> Answer
                        </button>
                        <button class="btn-metric" onclick="callHistory()">
                            <i class="fas fa-history"></i> History
                        </button>
                    </div>
                </div>
                <div class="metric-card success">
                    <div class="metric-header">
                        <div>
                            <h3 class="metric-title">Total Incidents (Month)</h3>
                            <div class="metric-value"><?php echo $totalIncidents; ?></div>
                            <div class="metric-change positive">
                                <i class="fas fa-arrow-up"></i>
                                +15% from last month
                            </div>
                        </div>
                        <div class="metric-icon chart">
                            <i class="fas fa-chart-bar"></i>
                        </div>
                    </div>
                    <div class="metric-actions">
                        <button class="btn-metric" onclick="monthlyReport()">
                            <i class="fas fa-file-pdf"></i> Report
                        </button>
                        <button class="btn-metric" onclick="trendAnalysis()">
                            <i class="fas fa-chart-line"></i> Trends
                        </button>
                    </div>
                </div>
            </div>
            <!-- Main Dashboard Grid -->
            <div class="dashboard-grid">
                <!-- Main Panel -->
                <div class="main-panel">
                    <!-- Response Time Chart -->
                    <div class="chart-container">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                            <h3 style="margin: 0; font-size: 1.25rem; font-weight: 700; color: #333;">Response Time Trends</h3>
                            <div style="display: flex; gap: 0.5rem;">
                                <button class="btn-metric" onclick="refreshChart()">
                                    <i class="fas fa-sync"></i> Refresh
                                </button>
                                <button class="btn-metric" onclick="exportChart()">
                                    <i class="fas fa-download"></i> Export
                                </button>
                            </div>
                        </div>
                        <div class="chart-placeholder">
                            <i class="fas fa-chart-line" style="margin-right: 0.5rem;"></i>
                            Response Time Chart (Interactive Chart Would Load Here)
                        </div>
                    </div>
                    <!-- Incident Distribution Chart -->
                    <div class="chart-container">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                            <h3 style="margin: 0; font-size: 1.25rem; font-weight: 700; color: #333;">Incident Types Distribution</h3>
                            <div style="display: flex; gap: 0.5rem;">
                                <button class="btn-metric" onclick="toggleChartView()">
                                    <i class="fas fa-pie-chart"></i> Toggle View
                                </button>
                                <button class="btn-metric" onclick="filterChart()">
                                    <i class="fas fa-filter"></i> Filter
                                </button>
                            </div>
                        </div>
                        <div class="chart-placeholder">
                            <i class="fas fa-chart-pie" style="margin-right: 0.5rem;"></i>
                            Incident Distribution Chart (Interactive Chart Would Load Here)
                        </div>
                    </div>
                </div>
                <!-- Side Panel -->
                <div class="side-panel">
                    <!-- Quick Actions -->
                    <div class="quick-actions">
                        <h3 class="quick-actions-title">Quick Actions</h3>
                        <div class="action-grid">
                            <button class="action-btn" onclick="emergencyCall()">
                                <i class="fas fa-phone-volume"></i>
                                <span>Emergency Call</span>
                            </button>
                            <button class="action-btn" onclick="dispatchUnit()">
                                <i class="fas fa-truck-medical"></i>
                                <span>Dispatch Unit</span>
                            </button>
                            <button class="action-btn" onclick="alertAllUnits()">
                                <i class="fas fa-bullhorn"></i>
                                <span>Alert All Units</span>
                            </button>
                            <button class="action-btn" onclick="resourceCheck()">
                                <i class="fas fa-clipboard-check"></i>
                                <span>Resource Check</span>
                            </button>
                            <button class="action-btn" onclick="generateReport()">
                                <i class="fas fa-file-pdf"></i>
                                <span>Generate Report</span>
                            </button>
                        </div>
                    </div>
                    <!-- System Status -->
                    <div class="system-status">
                        <h3 style="margin: 0 0 1.5rem 0; font-size: 1.25rem; font-weight: 700; color: #333;">System Status</h3>
                        <div class="status-item">
                            <span class="status-label">Communication System</span>
                            <div class="status-indicator">
                                <div class="status-dot online"></div>
                                <span class="status-text">Online</span>
                            </div>
                        </div>
                        <div class="status-item">
                            <span class="status-label">GPS Tracking</span>
                            <div class="status-indicator">
                                <div class="status-dot online"></div>
                                <span class="status-text">Online</span>
                            </div>
                        </div>
                        <div class="status-item">
                            <span class="status-label">Database</span>
                            <div class="status-indicator">
                                <div class="status-dot online"></div>
                                <span class="status-text">Demo Mode</span>
                            </div>
                        </div>
                        <div class="status-item">
                            <span class="status-label">Radio Network</span>
                            <div class="status-indicator">
                                <div class="status-dot warning"></div>
                                <span class="status-text">Intermittent</span>
                            </div>
                        </div>
                        <div class="status-item">
                            <span class="status-label">Backup Power</span>
                            <div class="status-indicator">
                                <div class="status-dot online"></div>
                                <span class="status-text">Active</span>
                            </div>
                        </div>
                    </div>
                    <!-- Weather Widget -->
                    <div class="weather-widget">
                        <div class="weather-header">
                            <div>
                                <div class="weather-location">City Center</div>
                                <div class="weather-condition">Partly Cloudy</div>
                            </div>
                            <div class="weather-temp">72°F</div>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-top: 1rem;">
                            <div style="text-align: center;">
                                <div style="font-weight: 600; color: #333;">Humidity</div>
                                <div style="color: #666;">65%</div>
                            </div>
                            <div style="text-align: center;">
                                <div style="font-weight: 600; color: #333;">Wind</div>
                                <div style="color: #666;">8 mph</div>
                            </div>
                            <div style="text-align: center;">
                                <div style="font-weight: 600; color: #333;">Visibility</div>
                                <div style="color: #666;">10 mi</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Bottom Section -->
            <div class="dashboard-grid">
                <!-- Activity Feed -->
                <div class="activity-feed">
                    <div class="activity-header">
                        <h3 class="activity-title">Recent Activity</h3>
                        <button class="btn-metric" onclick="viewAllActivity()">
                            <i class="fas fa-external-link-alt"></i> View All
                        </button>
                    </div>
                    <div class="activity-item">
                        <div class="activity-icon emergency">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div class="activity-content">
                            <div class="activity-text">Medical Emergency Reported</div>
                            <div class="activity-details">Downtown District • Priority: High</div>
                            <div class="activity-time">2 minutes ago</div>
                        </div>
                    </div>
                    <div class="activity-item">
                        <div class="activity-icon response">
                            <i class="fas fa-truck-medical"></i>
                        </div>
                        <div class="activity-content">
                            <div class="activity-text">Ambulance #5 Dispatched</div>
                            <div class="activity-details">Unit en route to incident • ETA: 4 minutes</div>
                            <div class="activity-time">5 minutes ago</div>
                        </div>
                    </div>
                    <div class="activity-item">
                        <div class="activity-icon maintenance">
                            <i class="fas fa-wrench"></i>
                        </div>
                        <div class="activity-content">
                            <div class="activity-text">Equipment Maintenance Completed</div>
                            <div class="activity-details">Defibrillator Unit #12 • All systems operational</div>
                            <div class="activity-time">12 minutes ago</div>
                        </div>
                    </div>
                    <div class="activity-item">
                        <div class="activity-icon system">
                            <i class="fas fa-server"></i>
                        </div>
                        <div class="activity-content">
                            <div class="activity-text">System Backup Completed</div>
                            <div class="activity-details">Daily backup successful • 99.8% uptime maintained</div>
                            <div class="activity-time">1 hour ago</div>
                        </div>
                    </div>
                </div>
                <!-- Alerts Panel -->
                <div class="alerts-panel">
                    <div class="alerts-header">
                        <h3 class="alerts-title">Active Alerts</h3>
                        <button class="btn-metric" onclick="viewAllAlerts()">
                            <i class="fas fa-external-link-alt"></i> View All
                        </button>
                    </div>
                    <div class="alert-item">
                        <div class="alert-icon critical">
                            <i class="fas fa-exclamation-circle"></i>
                        </div>
                        <div class="alert-content">
                            <div class="alert-text">High Response Time Alert</div>
                            <div class="alert-details">Zone 3 average exceeds 10 minutes</div>
                        </div>
                    </div>
                    <div class="alert-item warning">
                        <div class="alert-icon warning">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div class="alert-content">
                            <div class="alert-text">Resource Utilization Warning</div>
                            <div class="alert-details">Ambulance fleet at 85% capacity</div>
                        </div>
                    </div>
                    <div class="alert-item info">
                        <div class="alert-icon info">
                            <i class="fas fa-info-circle"></i>
                        </div>
                        <div class="alert-content">
                            <div class="alert-text">Weather Alert</div>
                            <div class="alert-details">Heavy rain expected in 2 hours</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Uncomment if already have content -->
    <?php /* include('includes/admin-footer.php') */ ?>
    <script>
        // Emergency Response System Dashboard Functionality (UI Only)
        // Dashboard action functions
        function refreshDashboard() {
            showNotification('Refreshing dashboard data...', 'info');
            setTimeout(() => {
                // Simulate data refresh with random updates
                const metrics = document.querySelectorAll('.metric-value');
                metrics.forEach(metric => {
                    const currentValue = parseFloat(metric.textContent.replace(/[^\d.]/g, ''));
                    if (!isNaN(currentValue)) {
                        const change = (Math.random() - 0.5) * 0.05; // ±2.5% change
                        const newValue = Math.max(0, currentValue * (1 + change));
                        if (metric.textContent.includes('m')) {
                            metric.textContent = newValue.toFixed(1) + 'm';
                        } else if (metric.textContent.includes('%')) {
                            metric.textContent = newValue.toFixed(1) + '%';
                        } else {
                            metric.textContent = Math.round(newValue);
                        }
                    }
                });
                showNotification('Dashboard refreshed successfully', 'success');
            }, 1500);
        }
        function exportDashboard() {
            showNotification('Generating dashboard report...', 'info');
            setTimeout(() => {
                showNotification('Dashboard report downloaded successfully', 'success');
            }, 2000);
        }
        function systemSettings() {
            showNotification('Opening system settings...', 'info');
            setTimeout(() => {
                showNotification('System settings panel loaded', 'success');
            }, 800);
        }
        // Metric action functions
        function viewIncidents() {
            window.location.href = 'incident.php';
        }
        function createIncident() {
            showNotification('Opening incident creation form...', 'info');
            setTimeout(() => {
                showNotification('Incident creation form loaded', 'success');
            }, 500);
        }
        function viewResponders() {
            window.location.href = 'resources.php';
        }
        function deployResponder() {
            showNotification('Opening deployment interface...', 'info');
            setTimeout(() => {
                showNotification('Deployment interface loaded', 'success');
            }, 600);
        }
        function viewResponseTimes() {
            window.location.href = 'report.php';
        }
        function optimizeRoutes() {
            window.location.href = 'gps.php';
        }
        function viewCalls() {
            window.location.href = 'call.php';
        }
        function callHistory() {
            showNotification('Opening call history...', 'info');
            setTimeout(() => {
                showNotification('Call history loaded', 'success');
            }, 700);
        }
        function monthlyReport() {
            window.location.href = 'report.php';
        }
        function trendAnalysis() {
            showNotification('Opening trend analysis...', 'info');
            setTimeout(() => {
                showNotification('Trend analysis loaded', 'success');
            }, 800);
        }
        function systemHealth() {
            showNotification('Running system health check...', 'info');
            setTimeout(() => {
                showNotification('All systems operational', 'success');
            }, 1000);
        }
        function systemLogs() {
            showNotification('Opening system logs...', 'info');
            setTimeout(() => {
                showNotification('System logs loaded', 'success');
            }, 600);
        }
        // Chart functions
        function refreshChart() {
            showNotification('Refreshing chart data...', 'info');
            setTimeout(() => {
                showNotification('Chart data updated', 'success');
            }, 1000);
        }
        function exportChart() {
            showNotification('Exporting chart...', 'info');
            setTimeout(() => {
                showNotification('Chart exported successfully', 'success');
            }, 1500);
        }
        function toggleChartView() {
            showNotification('Switching chart view...', 'info');
            setTimeout(() => {
                showNotification('Chart view updated', 'success');
            }, 500);
        }
        function filterChart() {
            showNotification('Opening chart filters...', 'info');
            setTimeout(() => {
                showNotification('Chart filters applied', 'success');
            }, 400);
        }
        // Quick action functions
        function emergencyCall() {
            window.location.href = 'call.php';
        }
        function dispatchUnit() {
            window.location.href = 'dispatch.php';
        }
        function alertAllUnits() {
            if (confirm('Send emergency alert to all units? This will interrupt current operations.')) {
                showNotification('Emergency alert sent to all units', 'error');
            }
        }
        function systemTest() {
            showNotification('Running system diagnostic test...', 'info');
            setTimeout(() => {
                showNotification('System test completed successfully', 'success');
            }, 3000);
        }
        function resourceCheck() {
            window.location.href = 'resources.php';
        }
        function generateReport() {
            window.location.href = 'report.php';
        }
        // Activity and alert functions
        function viewAllActivity() {
            showNotification('Opening full activity log...', 'info');
            setTimeout(() => {
                showNotification('Activity log loaded', 'success');
            }, 600);
        }
        function viewAllAlerts() {
            showNotification('Opening alerts management...', 'info');
            setTimeout(() => {
                showNotification('Alerts panel loaded', 'success');
            }, 500);
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
            } else if (type === 'warning') {
                notification.style.backgroundColor = '#ffc107';
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
            .metric-card, .action-btn, .btn-metric, .btn-dashboard {
                transition: all 0.3s ease;
            }
            .metric-card:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 16px rgba(0,0,0,0.15);
            }
            .action-btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(102,126,234,0.3);
            }
            .btn-metric:hover, .btn-dashboard:hover {
                transform: translateY(-1px);
                box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            }
            .activity-item:hover, .alert-item:hover {
                background-color: #f8f9fa;
            }
        `;
        document.head.appendChild(style);
        // Auto-refresh dashboard every 5 minutes
        setInterval(() => {
            refreshDashboard();
        }, 300000);
        // Initialize dashboard
        document.addEventListener('DOMContentLoaded', function() {
            showNotification('Dashboard loaded successfully', 'success');
        });
    </script>
</body>
</html>
