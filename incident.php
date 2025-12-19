<?php
/**
 * Incident Priority Management System - UI Only
 * User interface for managing emergency incident priorities
 */

$pageTitle = 'Incident Priority Management';
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
    <link rel="stylesheet" href="css/incident.css">

</head>
<body>
    <!-- Include Sidebar Component -->
    <?php include 'includes/sidebar.php'; ?>

    <!-- Include Admin Header Component -->
    <?php include 'includes/admin-header.php'; ?>

    <!-- ===================================
       MAIN CONTENT - Incident Priority Management
       =================================== -->
    <div class="main-content">
        <div class="main-container">

            <h1 class="section-title">
                <i class="fas fa-exclamation-triangle"></i>
                Incident Priority Management
            </h1>

            <!-- Statistics Cards -->
            <div class="stats-cards">
                <div class="stats-card">
                    <div class="stats-icon high">
                        <i class="fas fa-fire"></i>
                    </div>
                    <div class="stats-content">
                        <h3>12</h3>
                        <p>High Priority Incidents</p>
                    </div>
                </div>
                <div class="stats-card">
                    <div class="stats-icon medium">
                        <i class="fas fa-ambulance"></i>
                    </div>
                    <div class="stats-content">
                        <h3>8</h3>
                        <p>Medium Priority Incidents</p>
                    </div>
                </div>
                <div class="stats-card">
                    <div class="stats-icon low">
                        <i class="fas fa-info-circle"></i>
                    </div>
                    <div class="stats-content">
                        <h3>15</h3>
                        <p>Low Priority Incidents</p>
                    </div>
                </div>
            </div>

            <!-- Filters Section -->
            <div class="filters-section">
                <h2 class="section-title" style="font-size: 1.2rem; margin-bottom: 1rem;">
                    <i class="fas fa-filter"></i>
                    Filter Incidents
                </h2>
                <div class="filters-grid">
                    <div class="filter-group">
                        <label for="priority-filter">Priority Level</label>
                        <select id="priority-filter">
                            <option value="">All Priorities</option>
                            <option value="high">High Priority</option>
                            <option value="medium">Medium Priority</option>
                            <option value="low">Low Priority</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="status-filter">Status</label>
                        <select id="status-filter">
                            <option value="">All Status</option>
                            <option value="active">Active</option>
                            <option value="dispatched">Dispatched</option>
                            <option value="resolved">Resolved</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="type-filter">Incident Type</label>
                        <select id="type-filter">
                            <option value="">All Types</option>
                            <option value="medical">Medical Emergency</option>
                            <option value="fire">Fire</option>
                            <option value="police">Police Emergency</option>
                            <option value="traffic">Traffic Accident</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="search">Search</label>
                        <input type="text" id="search" placeholder="Search incidents...">
                    </div>
                </div>
            </div>

            <!-- High Priority Incidents -->
            <div class="priority-section">
                <h2 class="section-title" style="font-size: 1.2rem;">
                    <i class="fas fa-exclamation-circle"></i>
                    High Priority Incidents
                </h2>

                <div class="incident-card priority-high">
                    <div class="incident-header">
                        <div>
                            <h3 class="incident-title">Medical Emergency - Cardiac Arrest</h3>
                            <div class="incident-meta">
                                <span><i class="fas fa-clock"></i> 2 min ago</span>
                                <span><i class="fas fa-map-marker-alt"></i> Downtown Hospital</span>
                                <span class="status-badge status-active">Active</span>
                            </div>
                        </div>
                    </div>
                    <div class="incident-details">
                        <div class="detail-item">
                            <span class="detail-label">Caller</span>
                            <span class="detail-value">John Smith (+1-555-0123)</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Location</span>
                            <span class="detail-value">123 Emergency Ave, Downtown</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Assigned Unit</span>
                            <span class="detail-value">Ambulance #5</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Response Time</span>
                            <span class="detail-value">8 minutes</span>
                        </div>
                    </div>
                    <div class="incident-actions">
                        <button class="btn-priority btn-high">High Priority</button>
                        <button class="btn-action"><i class="fas fa-edit"></i> Update</button>
                        <button class="btn-action"><i class="fas fa-phone"></i> Contact</button>
                        <button class="btn-action"><i class="fas fa-check"></i> Resolve</button>
                    </div>
                </div>

                <div class="incident-card priority-high">
                    <div class="incident-header">
                        <div>
                            <h3 class="incident-title">Structure Fire - Apartment Building</h3>
                            <div class="incident-meta">
                                <span><i class="fas fa-clock"></i> 5 min ago</span>
                                <span><i class="fas fa-map-marker-alt"></i> Residential District</span>
                                <span class="status-badge status-dispatched">Dispatched</span>
                            </div>
                        </div>
                    </div>
                    <div class="incident-details">
                        <div class="detail-item">
                            <span class="detail-label">Caller</span>
                            <span class="detail-value">Sarah Johnson (+1-555-0456)</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Location</span>
                            <span class="detail-value">456 Oak Street, Apt 3B</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Assigned Unit</span>
                            <span class="detail-value">Engine #12, Ladder #7</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Response Time</span>
                            <span class="detail-value">12 minutes</span>
                        </div>
                    </div>
                    <div class="incident-actions">
                        <button class="btn-priority btn-high">High Priority</button>
                        <button class="btn-action"><i class="fas fa-edit"></i> Update</button>
                        <button class="btn-action"><i class="fas fa-phone"></i> Contact</button>
                        <button class="btn-action"><i class="fas fa-check"></i> Resolve</button>
                    </div>
                </div>
            </div>

            <!-- Medium Priority Incidents -->
            <div class="priority-section" style="margin-top: 2rem;">
                <h2 class="section-title" style="font-size: 1.2rem;">
                    <i class="fas fa-exclamation-triangle"></i>
                    Medium Priority Incidents
                </h2>

                <div class="incident-card priority-medium">
                    <div class="incident-header">
                        <div>
                            <h3 class="incident-title">Traffic Accident - Multiple Vehicles</h3>
                            <div class="incident-meta">
                                <span><i class="fas fa-clock"></i> 15 min ago</span>
                                <span><i class="fas fa-map-marker-alt"></i> Highway 101</span>
                                <span class="status-badge status-active">Active</span>
                            </div>
                        </div>
                    </div>
                    <div class="incident-details">
                        <div class="detail-item">
                            <span class="detail-label">Caller</span>
                            <span class="detail-value">Mike Davis (+1-555-0789)</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Location</span>
                            <span class="detail-value">Highway 101, Mile Marker 45</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Assigned Unit</span>
                            <span class="detail-value">Police Unit #8, Tow Truck</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Response Time</span>
                            <span class="detail-value">18 minutes</span>
                        </div>
                    </div>
                    <div class="incident-actions">
                        <button class="btn-priority btn-medium">Medium Priority</button>
                        <button class="btn-action"><i class="fas fa-edit"></i> Update</button>
                        <button class="btn-action"><i class="fas fa-phone"></i> Contact</button>
                        <button class="btn-action"><i class="fas fa-check"></i> Resolve</button>
                    </div>
                </div>
            </div>

            <!-- Low Priority Incidents -->
            <div class="priority-section" style="margin-top: 2rem;">
                <h2 class="section-title" style="font-size: 1.2rem;">
                    <i class="fas fa-info-circle"></i>
                    Low Priority Incidents
                </h2>

                <div class="incident-card priority-low">
                    <div class="incident-header">
                        <div>
                            <h3 class="incident-title">Suspicious Person Report</h3>
                            <div class="incident-meta">
                                <span><i class="fas fa-clock"></i> 1 hour ago</span>
                                <span><i class="fas fa-map-marker-alt"></i> Commercial District</span>
                                <span class="status-badge status-active">Active</span>
                            </div>
                        </div>
                    </div>
                    <div class="incident-details">
                        <div class="detail-item">
                            <span class="detail-label">Caller</span>
                            <span class="detail-value">Lisa Brown (+1-555-0321)</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Location</span>
                            <span class="detail-value">789 Pine Street, Bank Entrance</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Assigned Unit</span>
                            <span class="detail-value">Police Unit #15</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Response Time</span>
                            <span class="detail-value">45 minutes</span>
                        </div>
                    </div>
                    <div class="incident-actions">
                        <button class="btn-priority btn-low">Low Priority</button>
                        <button class="btn-action"><i class="fas fa-edit"></i> Update</button>
                        <button class="btn-action"><i class="fas fa-phone"></i> Contact</button>
                        <button class="btn-action"><i class="fas fa-check"></i> Resolve</button>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Uncomment if already have content -->
    <?php /* include('includes/admin-footer.php') */ ?>

    <script>
        // Incident Priority Management Functionality

        // Priority change functionality
        document.querySelectorAll('.btn-priority').forEach(button => {
            button.addEventListener('click', function() {
                const incidentCard = this.closest('.incident-card');
                const currentPriority = incidentCard.classList.contains('priority-high') ? 'high' :
                                      incidentCard.classList.contains('priority-medium') ? 'medium' : 'low';

                // Cycle through priorities: high -> medium -> low -> high
                let newPriority;
                if (currentPriority === 'high') {
                    newPriority = 'medium';
                } else if (currentPriority === 'medium') {
                    newPriority = 'low';
                } else {
                    newPriority = 'high';
                }

                // Update card styling
                incidentCard.classList.remove('priority-high', 'priority-medium', 'priority-low');
                incidentCard.classList.add(`priority-${newPriority}`);

                // Update button styling and text
                this.className = `btn-priority btn-${newPriority}`;
                this.textContent = `${newPriority.charAt(0).toUpperCase() + newPriority.slice(1)} Priority`;

                // Show confirmation
                showNotification(`Incident priority changed to ${newPriority.toUpperCase()}`, 'success');
            });
        });

        // Resolve incident functionality
        document.querySelectorAll('.btn-action .fa-check').forEach(icon => {
            icon.parentElement.addEventListener('click', function() {
                const incidentCard = this.closest('.incident-card');
                const incidentTitle = incidentCard.querySelector('.incident-title').textContent;

                if (confirm(`Are you sure you want to resolve the incident: "${incidentTitle}"?`)) {
                    // Change status to resolved
                    const statusBadge = incidentCard.querySelector('.status-badge');
                    statusBadge.className = 'status-badge status-resolved';
                    statusBadge.textContent = 'Resolved';

                    // Add resolved styling
                    incidentCard.style.opacity = '0.7';
                    incidentCard.style.borderLeftColor = '#28a745';

                    showNotification('Incident marked as resolved', 'success');
                }
            });
        });

        // Contact functionality
        document.querySelectorAll('.btn-action .fa-phone').forEach(icon => {
            icon.parentElement.addEventListener('click', function() {
                const incidentCard = this.closest('.incident-card');
                const callerInfo = incidentCard.querySelector('.detail-value').textContent;
                const phoneMatch = callerInfo.match(/(\+?\d{1,3}[-.\s]?\d{1,4}[-.\s]?\d{1,4}[-.\s]?\d{1,4})/);

                if (phoneMatch) {
                    const phoneNumber = phoneMatch[1];
                    if (confirm(`Call ${phoneNumber}?`)) {
                        // In a real system, this would initiate a phone call
                        showNotification(`Initiating call to ${phoneNumber}`, 'info');
                    }
                } else {
                    showNotification('Phone number not found', 'error');
                }
            });
        });

        // Update incident functionality
        document.querySelectorAll('.btn-action .fa-edit').forEach(icon => {
            icon.parentElement.addEventListener('click', function() {
                const incidentCard = this.closest('.incident-card');
                const incidentTitle = incidentCard.querySelector('.incident-title').textContent;

                // Simple update dialog (in a real system, this would open a modal)
                const newDescription = prompt('Update incident description:', incidentTitle);
                if (newDescription && newDescription !== incidentTitle) {
                    incidentCard.querySelector('.incident-title').textContent = newDescription;
                    showNotification('Incident updated successfully', 'success');
                }
            });
        });

        // Filter functionality
        const priorityFilter = document.getElementById('priority-filter');
        const statusFilter = document.getElementById('status-filter');
        const typeFilter = document.getElementById('type-filter');
        const searchInput = document.getElementById('search');

        function applyFilters() {
            const priorityValue = priorityFilter.value.toLowerCase();
            const statusValue = statusFilter.value.toLowerCase();
            const typeValue = typeFilter.value.toLowerCase();
            const searchValue = searchInput.value.toLowerCase();

            document.querySelectorAll('.incident-card').forEach(card => {
                let showCard = true;

                // Priority filter
                if (priorityValue && !card.classList.contains(`priority-${priorityValue}`)) {
                    showCard = false;
                }

                // Status filter
                if (statusValue) {
                    const statusBadge = card.querySelector('.status-badge');
                    const cardStatus = statusBadge.textContent.toLowerCase();
                    if (cardStatus !== statusValue) {
                        showCard = false;
                    }
                }

                // Type filter
                if (typeValue) {
                    const title = card.querySelector('.incident-title').textContent.toLowerCase();
                    const typeMap = {
                        'medical': 'medical',
                        'fire': 'fire',
                        'police': 'police',
                        'traffic': 'traffic'
                    };

                    if (!title.includes(typeMap[typeValue])) {
                        showCard = false;
                    }
                }

                // Search filter
                if (searchValue) {
                    const title = card.querySelector('.incident-title').textContent.toLowerCase();
                    const location = card.querySelector('.fa-map-marker-alt').parentElement.textContent.toLowerCase();
                    const caller = card.querySelector('.detail-value').textContent.toLowerCase();

                    if (!title.includes(searchValue) && !location.includes(searchValue) && !caller.includes(searchValue)) {
                        showCard = false;
                    }
                }

                // Show/hide card with animation
                if (showCard) {
                    card.style.display = 'block';
                    card.style.animation = 'fadeIn 0.3s ease-in';
                } else {
                    card.style.display = 'none';
                }
            });

            updateStats();
        }

        // Add event listeners to filters
        priorityFilter.addEventListener('change', applyFilters);
        statusFilter.addEventListener('change', applyFilters);
        typeFilter.addEventListener('change', applyFilters);
        searchInput.addEventListener('input', applyFilters);

        // Update statistics
        function updateStats() {
            const visibleCards = document.querySelectorAll('.incident-card[style*="display: block"], .incident-card:not([style*="display"])');
            let highCount = 0, mediumCount = 0, lowCount = 0;

            visibleCards.forEach(card => {
                if (card.classList.contains('priority-high')) highCount++;
                else if (card.classList.contains('priority-medium')) mediumCount++;
                else if (card.classList.contains('priority-low')) lowCount++;
            });

            // Update stats cards
            document.querySelector('.stats-content h3').textContent = highCount;
            document.querySelectorAll('.stats-content h3')[1].textContent = mediumCount;
            document.querySelectorAll('.stats-content h3')[2].textContent = lowCount;
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
            @keyframes fadeIn {
                from { opacity: 0; transform: translateY(-10px); }
                to { opacity: 1; transform: translateY(0); }
            }

            @keyframes slideIn {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }

            @keyframes slideOut {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }

            .notification {
                font-family: inherit;
            }

            .btn-priority, .btn-action {
                transition: all 0.3s ease;
            }

            .btn-priority:hover, .btn-action:hover {
                transform: translateY(-1px);
                box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            }
        `;
        document.head.appendChild(style);

        // Initialize stats on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateStats();
        });
    </script>
</body>
</html>