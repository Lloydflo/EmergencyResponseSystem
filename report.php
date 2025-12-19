<?php
/**
 * Emergency Response Analytics & Reporting - UI Only
 * Comprehensive analytics dashboard and reporting system
 */

$pageTitle = 'Analytics & Reporting';
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
    <link rel="stylesheet" href="css/sidebar-footer.css">
    <link rel="stylesheet" href="css/report.css">
</head>
<body>
    <!-- Include Sidebar Component -->
    <?php include 'includes/sidebar.php'; ?>

    <!-- Include Admin Header Component -->
    <?php include 'includes/admin-header.php'; ?>

    <!-- ===================================
       MAIN CONTENT - Emergency Response Analytics & Reporting
       =================================== -->
    <div class="main-content">
        <div class="main-container">

            <h1 style="font-size: 2rem; font-weight: 700; color: #333; margin-bottom: 2rem; display: flex; align-items: center;">
                <i class="fas fa-chart-line" style="margin-right: 0.5rem; color: #007bff;"></i>
                Analytics & Reporting
            </h1>

            <!-- Key Metrics Overview -->
            <div class="analytics-grid">
                <div class="analytics-card response-time">
                    <div class="metric-label">Average Response Time</div>
                    <div class="metric-display">
                        <div class="metric-value">8.2m</div>
                        <div class="metric-change positive">
                            <i class="fas fa-arrow-down"></i>
                            -12%
                        </div>
                    </div>
                    <div style="color: #666; font-size: 0.9rem;">Target: < 10 minutes</div>
                </div>

                <div class="analytics-card incidents">
                    <div class="metric-label">Total Incidents (This Month)</div>
                    <div class="metric-display">
                        <div class="metric-value">247</div>
                        <div class="metric-change positive">
                            <i class="fas fa-arrow-up"></i>
                            +8%
                        </div>
                    </div>
                    <div style="color: #666; font-size: 0.9rem;">Last month: 228</div>
                </div>

                <div class="analytics-card resources">
                    <div class="metric-label">Resource Utilization</div>
                    <div class="metric-display">
                        <div class="metric-value">76%</div>
                        <div class="metric-change neutral">
                            <i class="fas fa-minus"></i>
                            0%
                        </div>
                    </div>
                    <div style="color: #666; font-size: 0.9rem;">Target: 70-80%</div>
                </div>

                <div class="analytics-card performance">
                    <div class="metric-label">Success Rate</div>
                    <div class="metric-display">
                        <div class="metric-value">94.3%</div>
                        <div class="metric-change positive">
                            <i class="fas fa-arrow-up"></i>
                            +2.1%
                        </div>
                    </div>
                    <div style="color: #666; font-size: 0.9rem;">Industry average: 89%</div>
                </div>
            </div>

            <!-- Report Filters -->
            <div class="report-filters">
                <h2 style="font-size: 1.25rem; font-weight: 700; color: #333; margin-bottom: 1.5rem; display: flex; align-items: center;">
                    <i class="fas fa-filter" style="margin-right: 0.5rem; color: #007bff;"></i>
                    Report Filters
                </h2>
                <div class="filter-row">
                    <div class="filter-group">
                        <label for="report-type">Report Type</label>
                        <select id="report-type">
                            <option value="">All Reports</option>
                            <option value="incident">Incident Reports</option>
                            <option value="performance">Performance Reports</option>
                            <option value="resource">Resource Reports</option>
                            <option value="trend">Trend Analysis</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="time-period">Time Period</label>
                        <select id="time-period">
                            <option value="today">Today</option>
                            <option value="week">This Week</option>
                            <option value="month" selected>This Month</option>
                            <option value="quarter">This Quarter</option>
                            <option value="year">This Year</option>
                            <option value="custom">Custom Range</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="incident-type">Incident Type</label>
                        <select id="incident-type">
                            <option value="">All Types</option>
                            <option value="medical">Medical Emergency</option>
                            <option value="fire">Fire</option>
                            <option value="accident">Traffic Accident</option>
                            <option value="crime">Crime</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="priority-level">Priority Level</label>
                        <select id="priority-level">
                            <option value="">All Priorities</option>
                            <option value="critical">Critical</option>
                            <option value="high">High</option>
                            <option value="medium">Medium</option>
                            <option value="low">Low</option>
                        </select>
                    </div>
                </div>
                <div class="filter-row">
                    <div class="date-range">
                        <div class="filter-group">
                            <label for="start-date">Start Date</label>
                            <input type="date" id="start-date" value="2024-01-01">
                        </div>
                        <div class="filter-group">
                            <label for="end-date">End Date</label>
                            <input type="date" id="end-date" value="2024-12-31">
                        </div>
                        <button class="btn-report primary" onclick="applyFilters()">
                            <i class="fas fa-search"></i> Apply Filters
                        </button>
                    </div>
                </div>
            </div>

            <!-- Quick Reports -->
            <div class="quick-reports">
                <div class="report-card" onclick="generateIncidentReport()">
                    <div class="report-icon incident">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="report-title">Incident Summary Report</div>
                    <div class="report-description">Comprehensive overview of all incidents with response times and outcomes</div>
                    <div class="report-actions">
                        <button class="btn-report primary" onclick="generateIncidentReport()">
                            <i class="fas fa-file-pdf"></i> Generate
                        </button>
                        <button class="btn-report" onclick="viewIncidentReport()">
                            <i class="fas fa-eye"></i> View
                        </button>
                    </div>
                </div>

                <div class="report-card" onclick="generatePerformanceReport()">
                    <div class="report-icon performance">
                        <i class="fas fa-trophy"></i>
                    </div>
                    <div class="report-title">Performance Analytics</div>
                    <div class="report-description">Team performance metrics, response times, and success rates</div>
                    <div class="report-actions">
                        <button class="btn-report primary" onclick="generatePerformanceReport()">
                            <i class="fas fa-file-pdf"></i> Generate
                        </button>
                        <button class="btn-report" onclick="viewPerformanceReport()">
                            <i class="fas fa-eye"></i> View
                        </button>
                    </div>
                </div>

                <div class="report-card" onclick="generateResourceReport()">
                    <div class="report-icon resource">
                        <i class="fas fa-truck"></i>
                    </div>
                    <div class="report-title">Resource Utilization Report</div>
                    <div class="report-description">Equipment and personnel usage statistics and efficiency metrics</div>
                    <div class="report-actions">
                        <button class="btn-report primary" onclick="generateResourceReport()">
                            <i class="fas fa-file-pdf"></i> Generate
                        </button>
                        <button class="btn-report" onclick="viewResourceReport()">
                            <i class="fas fa-eye"></i> View
                        </button>
                    </div>
                </div>

                <div class="report-card" onclick="generateTrendReport()">
                    <div class="report-icon trend">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="report-title">Trend Analysis Report</div>
                    <div class="report-description">Historical trends, seasonal patterns, and predictive analytics</div>
                    <div class="report-actions">
                        <button class="btn-report primary" onclick="generateTrendReport()">
                            <i class="fas fa-file-pdf"></i> Generate
                        </button>
                        <button class="btn-report" onclick="viewTrendReport()">
                            <i class="fas fa-eye"></i> View
                        </button>
                    </div>
                </div>
            </div>

            <!-- Charts Section -->
            <div class="chart-container">
                <div class="chart-header">
                    <h3 class="chart-title">Incident Response Times</h3>
                    <div class="chart-controls">
                        <button class="btn-report" onclick="refreshChart()">
                            <i class="fas fa-sync"></i> Refresh
                        </button>
                        <button class="btn-report" onclick="exportChart()">
                            <i class="fas fa-download"></i> Export
                        </button>
                    </div>
                </div>
                <div class="chart-placeholder">
                    <i class="fas fa-chart-bar" style="margin-right: 0.5rem;"></i>
                    Response Time Chart (Interactive Chart Would Load Here)
                </div>
            </div>

            <div class="chart-container">
                <div class="chart-header">
                    <h3 class="chart-title">Incident Types Distribution</h3>
                    <div class="chart-controls">
                        <button class="btn-report" onclick="toggleChartView()">
                            <i class="fas fa-pie-chart"></i> Toggle View
                        </button>
                        <button class="btn-report" onclick="exportChart()">
                            <i class="fas fa-download"></i> Export
                        </button>
                    </div>
                </div>
                <div class="chart-placeholder">
                    <i class="fas fa-chart-pie" style="margin-right: 0.5rem;"></i>
                    Incident Types Pie Chart (Interactive Chart Would Load Here)
                </div>
            </div>

            <!-- Recent Incidents Table -->
            <div class="data-table">
                <div class="table-header">
                    <h3 class="table-title">Recent Incidents</h3>
                </div>
                <div class="table-container">
                    <table class="analytics-table">
                        <thead>
                            <tr>
                                <th>Incident ID</th>
                                <th>Type</th>
                                <th>Location</th>
                                <th>Priority</th>
                                <th>Response Time</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>INC-2024-0123</td>
                                <td>Medical Emergency</td>
                                <td>Downtown District</td>
                                <td>High</td>
                                <td>6m 32s</td>
                                <td><span class="status-badge status-resolved">Resolved</span></td>
                                <td>
                                    <button class="btn-report" onclick="viewIncidentDetails('INC-2024-0123')">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <td>INC-2024-0122</td>
                                <td>Traffic Accident</td>
                                <td>Highway 101</td>
                                <td>Critical</td>
                                <td>4m 15s</td>
                                <td><span class="status-badge status-resolved">Resolved</span></td>
                                <td>
                                    <button class="btn-report" onclick="viewIncidentDetails('INC-2024-0122')">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <td>INC-2024-0121</td>
                                <td>Fire</td>
                                <td>Residential Area</td>
                                <td>Critical</td>
                                <td>8m 45s</td>
                                <td><span class="status-badge status-pending">In Progress</span></td>
                                <td>
                                    <button class="btn-report" onclick="viewIncidentDetails('INC-2024-0121')">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <td>INC-2024-0120</td>
                                <td>Medical Emergency</td>
                                <td>Shopping Center</td>
                                <td>Medium</td>
                                <td>12m 20s</td>
                                <td><span class="status-badge status-resolved">Resolved</span></td>
                                <td>
                                    <button class="btn-report" onclick="viewIncidentDetails('INC-2024-0120')">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <td>INC-2024-0119</td>
                                <td>Crime</td>
                                <td>City Center</td>
                                <td>High</td>
                                <td>15m 10s</td>
                                <td><span class="status-badge status-critical">Critical</span></td>
                                <td>
                                    <button class="btn-report" onclick="viewIncidentDetails('INC-2024-0119')">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Performance Metrics Table -->
            <div class="data-table">
                <div class="table-header">
                    <h3 class="table-title">Performance Metrics</h3>
                </div>
                <div class="table-container">
                    <table class="analytics-table">
                        <thead>
                            <tr>
                                <th>Metric</th>
                                <th>Current Value</th>
                                <th>Target</th>
                                <th>Trend</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Average Response Time</td>
                                <td>8.2 minutes</td>
                                <td>< 10 minutes</td>
                                <td><div class="trend-indicator trend-up"><i class="fas fa-arrow-up"></i> Improving</div></td>
                                <td><span class="status-badge status-resolved">On Target</span></td>
                            </tr>
                            <tr>
                                <td>Incident Resolution Rate</td>
                                <td>94.3%</td>
                                <td>> 90%</td>
                                <td><div class="trend-indicator trend-up"><i class="fas fa-arrow-up"></i> Improving</div></td>
                                <td><span class="status-badge status-resolved">Excellent</span></td>
                            </tr>
                            <tr>
                                <td>Resource Utilization</td>
                                <td>76%</td>
                                <td>70-80%</td>
                                <td><div class="trend-indicator trend-neutral"><i class="fas fa-minus"></i> Stable</div></td>
                                <td><span class="status-badge status-resolved">Optimal</span></td>
                            </tr>
                            <tr>
                                <td>Equipment Downtime</td>
                                <td>2.1%</td>
                                <td>< 5%</td>
                                <td><div class="trend-indicator trend-down"><i class="fas fa-arrow-down"></i> Improving</div></td>
                                <td><span class="status-badge status-resolved">Excellent</span></td>
                            </tr>
                            <tr>
                                <td>Personnel Overtime</td>
                                <td>12.5 hours/week</td>
                                <td>< 15 hours/week</td>
                                <td><div class="trend-indicator trend-neutral"><i class="fas fa-minus"></i> Stable</div></td>
                                <td><span class="status-badge status-resolved">Acceptable</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Export Options -->
            <div class="export-section">
                <h2 style="font-size: 1.25rem; font-weight: 700; color: #333; margin-bottom: 1.5rem; display: flex; align-items: center;">
                    <i class="fas fa-download" style="margin-right: 0.5rem; color: #28a745;"></i>
                    Export Reports
                </h2>
                <div class="export-options">
                    <button class="export-btn" onclick="exportPDF()">
                        <i class="fas fa-file-pdf"></i>
                        PDF Report
                    </button>
                    <button class="export-btn" onclick="exportExcel()">
                        <i class="fas fa-file-excel"></i>
                        Excel Data
                    </button>
                    <button class="export-btn" onclick="exportCSV()">
                        <i class="fas fa-file-csv"></i>
                        CSV Export
                    </button>
                    <button class="export-btn" onclick="exportJSON()">
                        <i class="fas fa-file-code"></i>
                        JSON Data
                    </button>
                    <button class="export-btn" onclick="scheduleReport()">
                        <i class="fas fa-calendar-plus"></i>
                        Schedule Report
                    </button>
                    <button class="export-btn" onclick="emailReport()">
                        <i class="fas fa-envelope"></i>
                        Email Report
                    </button>
                </div>
            </div>

            <!-- System Alerts -->
            <div class="alerts-section">
                <h2 style="font-size: 1.25rem; font-weight: 700; color: #333; margin-bottom: 1.5rem; display: flex; align-items: center;">
                    <i class="fas fa-bell" style="margin-right: 0.5rem; color: #ffc107;"></i>
                    System Alerts & Notifications
                </h2>

                <div class="alert-item critical">
                    <div class="alert-info">
                        <div class="alert-title">High Response Time Alert</div>
                        <div class="alert-details">Average response time exceeded 10 minutes in Zone 3 • 15 minutes ago</div>
                    </div>
                    <div class="alert-actions">
                        <button class="btn-report" onclick="investigateAlert()">
                            <i class="fas fa-search"></i> Investigate
                        </button>
                        <button class="btn-report" onclick="dismissAlert()">
                            <i class="fas fa-times"></i> Dismiss
                        </button>
                    </div>
                </div>

                <div class="alert-item warning">
                    <div class="alert-info">
                        <div class="alert-title">Resource Utilization Warning</div>
                        <div class="alert-details">Ambulance fleet utilization at 85% • Consider additional staffing</div>
                    </div>
                    <div class="alert-actions">
                        <button class="btn-report" onclick="viewResourceDetails()">
                            <i class="fas fa-eye"></i> View Details
                        </button>
                        <button class="btn-report" onclick="dismissAlert()">
                            <i class="fas fa-times"></i> Dismiss
                        </button>
                    </div>
                </div>

                <div class="alert-item">
                    <div class="alert-info">
                        <div class="alert-title">Monthly Report Generated</div>
                        <div class="alert-details">December performance report is ready for review • 2 hours ago</div>
                    </div>
                    <div class="alert-actions">
                        <button class="btn-report primary" onclick="viewMonthlyReport()">
                            <i class="fas fa-eye"></i> View Report
                        </button>
                        <button class="btn-report" onclick="dismissAlert()">
                            <i class="fas fa-times"></i> Dismiss
                        </button>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Uncomment if already have content -->
    <?php /* include('includes/admin-footer.php') */ ?>

    <script>
        // Emergency Response Analytics & Reporting Functionality

        // Report generation functions
        function generateIncidentReport() {
            showNotification('Generating incident summary report...', 'info');
            setTimeout(() => {
                showNotification('Incident report generated successfully', 'success');
            }, 2000);
        }

        function generatePerformanceReport() {
            showNotification('Generating performance analytics report...', 'info');
            setTimeout(() => {
                showNotification('Performance report generated successfully', 'success');
            }, 2500);
        }

        function generateResourceReport() {
            showNotification('Generating resource utilization report...', 'info');
            setTimeout(() => {
                showNotification('Resource report generated successfully', 'success');
            }, 1800);
        }

        function generateTrendReport() {
            showNotification('Generating trend analysis report...', 'info');
            setTimeout(() => {
                showNotification('Trend analysis report generated successfully', 'success');
            }, 3000);
        }

        // View report functions
        function viewIncidentReport() {
            window.open('#incident-report', '_blank');
            showNotification('Opening incident report viewer', 'info');
        }

        function viewPerformanceReport() {
            window.open('#performance-report', '_blank');
            showNotification('Opening performance report viewer', 'info');
        }

        function viewResourceReport() {
            window.open('#resource-report', '_blank');
            showNotification('Opening resource report viewer', 'info');
        }

        function viewTrendReport() {
            window.open('#trend-report', '_blank');
            showNotification('Opening trend analysis viewer', 'info');
        }

        // Chart functions
        function refreshChart() {
            showNotification('Refreshing chart data...', 'info');
            setTimeout(() => {
                showNotification('Chart data updated', 'success');
            }, 1000);
        }

        function exportChart() {
            showNotification('Exporting chart as image...', 'info');
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

        // Incident details
        function viewIncidentDetails(incidentId) {
            const details = `Incident Details: ${incidentId}\n\n` +
                          `• Type: Medical Emergency\n` +
                          `• Location: Downtown District\n` +
                          `• Priority: High\n` +
                          `• Response Time: 6m 32s\n` +
                          `• Units Dispatched: Ambulance #5, Police Unit #8\n` +
                          `• Status: Resolved\n` +
                          `• Outcome: Patient transported to hospital\n\n` +
                          `View full incident log and timeline?`;

            if (confirm(details)) {
                showNotification(`Opening detailed view for ${incidentId}`, 'info');
            }
        }

        // Filter functions
        function applyFilters() {
            const reportType = document.getElementById('report-type').value;
            const timePeriod = document.getElementById('time-period').value;
            const incidentType = document.getElementById('incident-type').value;
            const priorityLevel = document.getElementById('priority-level').value;
            const startDate = document.getElementById('start-date').value;
            const endDate = document.getElementById('end-date').value;

            showNotification('Applying filters to reports...', 'info');
            setTimeout(() => {
                showNotification(`Filters applied: ${timePeriod} | ${incidentType || 'All Types'} | ${priorityLevel || 'All Priorities'}`, 'success');
            }, 1000);
        }

        // Export functions
        function exportPDF() {
            showNotification('Generating PDF report...', 'info');
            setTimeout(() => {
                showNotification('PDF report downloaded successfully', 'success');
            }, 3000);
        }

        function exportExcel() {
            showNotification('Exporting data to Excel...', 'info');
            setTimeout(() => {
                showNotification('Excel file downloaded successfully', 'success');
            }, 2000);
        }

        function exportCSV() {
            showNotification('Exporting data to CSV...', 'info');
            setTimeout(() => {
                showNotification('CSV file downloaded successfully', 'success');
            }, 1500);
        }

        function exportJSON() {
            showNotification('Exporting data to JSON...', 'info');
            setTimeout(() => {
                showNotification('JSON file downloaded successfully', 'success');
            }, 1000);
        }

        function scheduleReport() {
            const frequency = prompt('How often should this report be generated?\n• Daily\n• Weekly\n• Monthly\n• Quarterly');
            if (frequency) {
                const email = prompt('Enter email address for report delivery:');
                if (email) {
                    showNotification(`Report scheduled for ${frequency.toLowerCase()} delivery to ${email}`, 'success');
                }
            }
        }

        function emailReport() {
            const email = prompt('Enter email address to send report to:');
            if (email) {
                showNotification(`Report sent to ${email}`, 'success');
            }
        }

        // Alert functions
        function investigateAlert() {
            showNotification('Opening investigation dashboard...', 'info');
            setTimeout(() => {
                showNotification('Investigation dashboard loaded', 'success');
            }, 1000);
        }

        function dismissAlert() {
            if (confirm('Dismiss this alert?')) {
                event.target.closest('.alert-item').style.display = 'none';
                showNotification('Alert dismissed', 'info');
            }
        }

        function viewResourceDetails() {
            showNotification('Opening resource utilization details...', 'info');
            setTimeout(() => {
                showNotification('Resource details loaded', 'success');
            }, 800);
        }

        function viewMonthlyReport() {
            showNotification('Opening monthly performance report...', 'info');
            setTimeout(() => {
                showNotification('Monthly report loaded', 'success');
            }, 1200);
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

            .report-card, .btn-report, .export-btn {
                transition: all 0.3s ease;
            }

            .report-card:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 16px rgba(0,0,0,0.15);
            }

            .btn-report:hover {
                transform: translateY(-1px);
                box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            }

            .export-btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(102,126,234,0.3);
            }

            .analytics-table tr:hover {
                background-color: #f8f9fa;
            }
        `;
        document.head.appendChild(style);

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-refresh metrics simulation
            setInterval(() => {
                // Simulate metric updates
                if (Math.random() < 0.3) {
                    const metrics = document.querySelectorAll('.metric-value');
                    metrics.forEach(metric => {
                        // Small random updates for demo
                        const currentValue = parseFloat(metric.textContent.replace(/[^\d.]/g, ''));
                        if (!isNaN(currentValue)) {
                            const change = (Math.random() - 0.5) * 0.1; // ±5% change
                            const newValue = Math.max(0, currentValue * (1 + change));
                            metric.textContent = newValue.toFixed(1) + (metric.textContent.match(/[^\d.]+$/) || '');
                        }
                    });
                }
            }, 60000); // Every minute
        });
    </script>
</body>
</html>