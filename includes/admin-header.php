<?php
/**
 * Reusable Admin Header Component - Improved Design
 * Include this file in your pages: <?php include 'sidebar/admin-header.php'; ?>
 * 
 * Features:
 * - Responsive menu toggle
 * - Notification and message icons with badges (outlined style)
 * - User profile with avatar and info
 * - Dark mode support
 * - Clean, modern design
 */
?>

<link rel="stylesheet" href="css/notification-modal.css">;
<link rel="stylesheet" href="css/message-modal.css">;
<link rel="stylesheet" href="css/message-content-modal.css">;

<!-- Admin Header Component -->
<header class="admin-header">
    <div class="admin-header-left">
        <button class="menu-toggle" id="menuToggle" aria-label="Toggle menu">
            <i class="fas fa-bars"></i>
        </button>
    </div>
    
    <div class="admin-header-right">
        <div class="header-actions">
            <div class="notification-item">
                <button class="notification-btn" aria-label="Notifications">
                    <i class="fas fa-bell"></i>
                    <span class="notification-badge">3</span>
                </button>
            </div>
            
            <div class="notification-item">
                <button class="notification-btn" aria-label="Messages">
                    <i class="fas fa-envelope"></i>
                    <span class="notification-badge">5</span>
                </button>
            </div>
        </div>
        
        <div class="header-divider"></div>
        
        <div class="user-profile" id="userProfileBtn">
            <div class="user-info">
                <div class="user-name">Admin User</div>
                <div class="user-role">Administrator</div>
            </div>
            <div class="user-avatar">
                <img src="https://ui-avatars.com/api/?name=Admin+User&background=4c8a89&color=fff&size=128" alt="Admin User" class="avatar-img">
            </div>
            <i class="fas fa-chevron-down dropdown-icon"></i>
        </div>
    </div>
</header>

<!-- User Profile Dropdown -->
<div class="user-profile-dropdown" id="userProfileDropdown">
    <div class="dropdown-header">
        <div class="dropdown-user-info">
            <div class="dropdown-user-avatar">
                <img src="https://ui-avatars.com/api/?name=Admin+User&background=4c8a89&color=fff&size=128" alt="Admin User">
            </div>
            <div class="dropdown-user-details">
                <div class="dropdown-user-name">Admin User</div>
                <div class="dropdown-user-email">admin@example.com</div>
            </div>
        </div>
    </div>
    
    <div class="dropdown-body">
        <a href="#" class="dropdown-item">
            <i class="fas fa-user"></i>
            <span>Profile</span>
        </a>
        <a href="#" class="dropdown-item">
            <i class="fas fa-cog"></i>
            <span>Settings</span>
        </a>
    </div>
    
    <div class="dropdown-footer">
        <a href="#" class="dropdown-item logout-item">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>
    </div>
</div>

<!-- Notification Modal -->
<div class="notification-modal" id="notificationModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Notifications</h3>
            <button class="modal-close" onclick="closeModal('notificationModal')">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body">
            <div class="notification-item">
                <div class="notification-icon">
                    <i class="fas fa-info-circle"></i>
                </div>
                <div class="notification-details">
                    <div class="notification-title">System Update</div>
                    <div class="notification-text">System will be updated tonight at 11 PM</div>
                    <div class="notification-time">2 hours ago</div>
                </div>
            </div>
            <div class="notification-item">
                <div class="notification-icon">
                    <i class="fas fa-user-plus"></i>
                </div>
                <div class="notification-details">
                    <div class="notification-title">New User Registered</div>
                    <div class="notification-text">John Doe joined the platform</div>
                    <div class="notification-time">5 hours ago</div>
                </div>
            </div>
            <div class="notification-item">
                <div class="notification-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="notification-details">
                    <div class="notification-title">Storage Warning</div>
                    <div class="notification-text">Disk space is running low (85% used)</div>
                    <div class="notification-time">1 day ago</div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <a href="#" class="view-all-link">View All Notifications</a>
        </div>
    </div>
</div>

<!-- Message Modal -->
<div class="notification-modal" id="messageModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Messages</h3>
            <button class="modal-close" onclick="closeModal('messageModal')">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body">
            <div class="message-item">
                <div class="message-avatar">
                    <img src="https://ui-avatars.com/api/?name=Sarah+Smith&background=4c8a89&color=fff&size=64" alt="Sarah Smith">
                </div>
                <div class="message-details">
                    <div class="message-title">Sarah Smith</div>
                    <div class="message-text">Hey, can you review the latest designs?</div>
                    <div class="message-time">30 minutes ago</div>
                </div>
                <div class="message-status unread"></div>
            </div>
            <div class="message-item">
                <div class="message-avatar">
                    <img src="https://ui-avatars.com/api/?name=Mike+Johnson&background=4c8a89&color=fff&size=64" alt="Mike Johnson">
                </div>
                <div class="message-details">
                    <div class="message-title">Mike Johnson</div>
                    <div class="message-text">Meeting scheduled for tomorrow at 2 PM</div>
                    <div class="message-time">2 hours ago</div>
                </div>
                <div class="message-status unread"></div>
            </div>
            <div class="message-item">
                <div class="message-avatar">
                    <img src="https://ui-avatars.com/api/?name=Emily+Brown&background=4c8a89&color=fff&size=64" alt="Emily Brown">
                </div>
                <div class="message-details">
                    <div class="message-title">Emily Brown</div>
                    <div class="message-text">Thanks for your help with the project!</div>
                    <div class="message-time">1 day ago</div>
                </div>
                <div class="message-status"></div>
            </div>
            <div class="message-item">
                <div class="message-avatar">
                    <img src="https://ui-avatars.com/api/?name=David+Lee&background=4c8a89&color=fff&size=64" alt="David Lee">
                </div>
                <div class="message-details">
                    <div class="message-title">David Lee</div>
                    <div class="message-text">Can you send me the report?</div>
                    <div class="message-time">2 days ago</div>
                </div>
                <div class="message-status"></div>
            </div>
            <div class="message-item">
                <div class="message-avatar">
                    <img src="https://ui-avatars.com/api/?name=Lisa+Wang&background=4c8a89&color=fff&size=64" alt="Lisa Wang">
                </div>
                <div class="message-details">
                    <div class="message-title">Lisa Wang</div>
                    <div class="message-text">Great job on the presentation!</div>
                    <div class="message-time">3 days ago</div>
                </div>
                <div class="message-status"></div>
            </div>
        </div>
        <div class="modal-footer">
            <a href="#" class="view-all-link">View All Messages</a>
        </div>
    </div>
</div>

<!-- Message Content Modal -->
<div class="message-content-modal" id="messageContentModal">
    <div class="modal-content">
        <div class="modal-header">
            <div class="message-header-info">
                <img id="messageUserAvatar" src="" alt="" class="message-user-avatar">
                <div class="message-user-info">
                    <h3 id="messageUserName"></h3>
                    <span id="messageUserStatus"></span>
                </div>
            </div>
            <button class="modal-close" onclick="closeModal('messageContentModal')">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body message-chat-body">
            <div id="messageContent"></div>
        </div>
        <div class="modal-footer message-reply-footer">
            <div class="message-reply-box">
                <input type="text" id="messageReplyInput" placeholder="Type a message..." class="message-input">
                <button class="send-message-btn">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Admin Header functionality
document.addEventListener('DOMContentLoaded', function() {
    const menuToggle = document.getElementById('menuToggle');
    
    // Toggle sidebar from header menu button
    if (menuToggle) {
        menuToggle.addEventListener('click', function() {
            // Use the global sidebarToggle function exposed by sidebar.php
            if (typeof window.sidebarToggle === 'function') {
                window.sidebarToggle();
            } else {
                console.warn('Sidebar toggle function not found. Make sure sidebar.php is included before admin-header.php');
            }
        });
    }
    
    // Search functionality
    const searchInput = document.querySelector('.search-input');
    const searchBtn = document.querySelector('.search-btn');
    
    if (searchBtn) {
        searchBtn.addEventListener('click', function() {
            if (searchInput) {
                const searchTerm = searchInput.value.trim();
                if (searchTerm) {
                    console.log('Searching for:', searchTerm);
                }
            }
        });
    }
    
    if (searchInput) {
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                const searchTerm = searchInput.value.trim();
                if (searchTerm) {
                    console.log('Searching for:', searchTerm);
                }
            }
        });
    }
    
    // Notification button interactions
    const notificationBtns = document.querySelectorAll('.admin-header .notification-btn');
    notificationBtns.forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const ariaLabel = this.getAttribute('aria-label');
            
            if (ariaLabel === 'Notifications') {
                const modal = document.getElementById('notificationModal');
                const messageModal = document.getElementById('messageModal');
                const messageContentModal = document.getElementById('messageContentModal');
                const messageBtn = document.querySelector('.notification-btn[aria-label="Messages"]');
                
                // Remove active class from message button
                if (messageBtn) messageBtn.classList.remove('active');
                
                // Close other modals first
                if (messageModal) messageModal.classList.remove('show');
                if (messageContentModal) messageContentModal.classList.remove('show');
                
                // Toggle notification modal and active state
                if (modal.classList.contains('show')) {
                    modal.classList.remove('show');
                    this.classList.remove('active');
                    document.body.style.overflow = '';
                } else {
                    modal.classList.add('show');
                    this.classList.add('active');
                    document.body.style.overflow = '';
                }
            } else if (ariaLabel === 'Messages') {
                const modal = document.getElementById('messageModal');
                const notificationModal = document.getElementById('notificationModal');
                const messageContentModal = document.getElementById('messageContentModal');
                const notificationBtn = document.querySelector('.notification-btn[aria-label="Notifications"]');
                
                // Remove active class from notification button
                if (notificationBtn) notificationBtn.classList.remove('active');
                
                // Close other modals first
                if (notificationModal) notificationModal.classList.remove('show');
                if (messageContentModal) messageContentModal.classList.remove('show');
                
                // Toggle message modal and active state
                if (modal.classList.contains('show')) {
                    modal.classList.remove('show');
                    this.classList.remove('active');
                    document.body.style.overflow = '';
                } else {
                    modal.classList.add('show');
                    this.classList.add('active');
                    document.body.style.overflow = '';
                }
            }
        });
    });
    
    // User profile dropdown functionality
    const userProfileBtn = document.getElementById('userProfileBtn');
    const userProfileDropdown = document.getElementById('userProfileDropdown');
    
    if (userProfileBtn && userProfileDropdown) {
        userProfileBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            // Close all modals first (except message content modal)
            const notificationModal = document.getElementById('notificationModal');
            const messageModal = document.getElementById('messageModal');
            const messageContentModal = document.getElementById('messageContentModal');
            
            if (notificationModal) notificationModal.classList.remove('show');
            if (messageModal) messageModal.classList.remove('show');
            // Don't close messageContentModal - let it stay open like Facebook chat
            
            // Remove active states from notification buttons
            const notificationBtn = document.querySelector('.notification-btn[aria-label="Notifications"]');
            const messageBtn = document.querySelector('.notification-btn[aria-label="Messages"]');
            if (notificationBtn) notificationBtn.classList.remove('active');
            if (messageBtn) messageBtn.classList.remove('active');
            
            // Toggle user profile dropdown and active state
            const isOpen = userProfileDropdown.classList.contains('show');
            userProfileDropdown.classList.toggle('show');
            userProfileBtn.classList.toggle('active', !isOpen);
        });
    }
    
    // Close modals when clicking outside
    document.addEventListener('click', function(e) {
        const notificationModal = document.getElementById('notificationModal');
        const messageModal = document.getElementById('messageModal');
        const messageContentModal = document.getElementById('messageContentModal');
        const userProfileDropdown = document.getElementById('userProfileDropdown');
        const notificationBtn = document.querySelector('.notification-btn[aria-label="Notifications"]');
        const messageBtn = document.querySelector('.notification-btn[aria-label="Messages"]');
        
        // Close notification modal when clicking outside
        if (notificationModal && notificationModal.classList.contains('show')) {
            if (!notificationModal.contains(e.target) && !e.target.closest('.notification-btn[aria-label="Notifications"]')) {
                notificationModal.classList.remove('show');
                if (notificationBtn) notificationBtn.classList.remove('active');
                document.body.style.overflow = '';
            }
        }
        
        // Close message modal when clicking outside
        if (messageModal && messageModal.classList.contains('show')) {
            if (!messageModal.contains(e.target) && !e.target.closest('.notification-btn[aria-label="Messages"]')) {
                messageModal.classList.remove('show');
                if (messageBtn) messageBtn.classList.remove('active');
                document.body.style.overflow = '';
            }
        }
        
        // Close user profile dropdown when clicking outside
        if (userProfileDropdown && userProfileDropdown.classList.contains('show')) {
            if (!userProfileDropdown.contains(e.target) && !e.target.closest('#userProfileBtn')) {
                userProfileDropdown.classList.remove('show');
                userProfileBtn.classList.remove('active');
            }
        }
        
        // Message content modal stays open when clicking outside (don't close it)
    });
    
    // Close modals on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeAllModals();
        }
    });
    
    // Modal functions
    function openModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.add('show');
            // Don't hide body scroll for message content modal (Facebook style)
            if (modalId !== 'messageContentModal') {
                document.body.style.overflow = 'hidden';
            }
        }
    }
    
    function closeModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.remove('show');
            document.body.style.overflow = '';
        }
    }
    
    function closeAllModals() {
        const modals = document.querySelectorAll('.notification-modal, .message-content-modal');
        modals.forEach(modal => {
            modal.classList.remove('show');
        });
        document.body.style.overflow = '';
    }
    
    // Message item interactions
    const messageItems = document.querySelectorAll('.message-item');
    messageItems.forEach(item => {
        item.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const userName = this.querySelector('.message-title').textContent;
            const userAvatar = this.querySelector('.message-avatar img').src;
            const messageText = this.querySelector('.message-text').textContent;
            const messageTime = this.querySelector('.message-time').textContent;
            
            // Remove active state from message button when opening chat
            const messageBtn = document.querySelector('.notification-btn[aria-label="Messages"]');
            if (messageBtn) messageBtn.classList.remove('active');
            
            // Close message dropdown modal
            const messageModal = document.getElementById('messageModal');
            if (messageModal) messageModal.classList.remove('show');
            
            // Open message content modal
            openMessageContent(userName, userAvatar, messageText, messageTime);
            
            // Remove unread status
            const statusDot = this.querySelector('.message-status.unread');
            if (statusDot) {
                statusDot.classList.remove('unread');
            }
        });
    });
    
    // Message content functions
    function openMessageContent(userName, userAvatar, lastMessage, messageTime) {
        const modal = document.getElementById('messageContentModal');
        const nameElement = document.getElementById('messageUserName');
        const avatarElement = document.getElementById('messageUserAvatar');
        const contentElement = document.getElementById('messageContent');
        const statusElement = document.getElementById('messageUserStatus');
        
        // Set user info
        nameElement.textContent = userName;
        avatarElement.src = userAvatar;
        avatarElement.alt = userName;
        statusElement.textContent = 'Active now';
        
        // Create conversation HTML
        contentElement.innerHTML = `
            <div class="chat-message received">
                <div class="message-bubble">${lastMessage}</div>
                <div class="message-time">${messageTime}</div>
            </div>
            <div class="chat-message sent">
                <div class="message-bubble">Thanks for reaching out! I'll get back to you soon.</div>
                <div class="message-time">Just now</div>
            </div>
        `;
        
        // Close message modal and open content modal
        closeModal('messageModal');
        modal.classList.add('show');
        // Don't hide body scroll for Facebook-style chat
        document.body.style.overflow = '';
    }
    
    // Send message functionality
    const sendBtn = document.querySelector('.send-message-btn');
    const messageInput = document.getElementById('messageReplyInput');
    
    if (sendBtn && messageInput) {
        sendBtn.addEventListener('click', sendMessage);
        messageInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                sendMessage();
            }
        });
    }
    
    function sendMessage() {
        const message = messageInput.value.trim();
        if (message) {
            const contentElement = document.getElementById('messageContent');
            const newMessage = document.createElement('div');
            newMessage.className = 'chat-message sent';
            newMessage.innerHTML = `
                <div class="message-bubble">${message}</div>
                <div class="message-time">Just now</div>
            `;
            contentElement.appendChild(newMessage);
            messageInput.value = '';
            
            // Scroll to bottom
            contentElement.scrollTop = contentElement.scrollHeight;
        }
    }
    
    // Make functions globally accessible
    window.openModal = openModal;
    window.closeModal = closeModal;
    window.closeAllModals = closeAllModals;
    
    // User profile interaction
    const userProfile = document.querySelector('.admin-header .user-profile');
    if (userProfile) {
        userProfile.addEventListener('click', function() {
            console.log('User profile clicked');
        });
    }
});
</script>
