-- Emergency Response System (ERS) Database Schema
-- This script creates the complete database structure for the ERS system

-- Create the database
CREATE DATABASE IF NOT EXISTS ers_db;
USE ers_db;

-- ===========================================
-- USERS AND AUTHENTICATION
-- ===========================================

-- Users table for system administrators and dispatchers
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('admin', 'dispatcher', 'supervisor') DEFAULT 'dispatcher',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL
);

-- ===========================================
-- EMERGENCY CALLS
-- ===========================================

-- Emergency calls received by the system
CREATE TABLE calls (
    id INT PRIMARY KEY AUTO_INCREMENT,
    caller_name VARCHAR(100) NOT NULL,
    caller_phone VARCHAR(20) NOT NULL,
    incident_type ENUM('Medical Emergency', 'Fire', 'Police Emergency', 'Traffic Accident', 'Natural Disaster', 'Hazardous Material', 'Other') NOT NULL,
    priority ENUM('low', 'medium', 'high') DEFAULT 'medium',
    location TEXT NOT NULL,
    description TEXT NOT NULL,
    dispatcher_notes TEXT,
    status ENUM('received', 'processing', 'dispatched', 'resolved') DEFAULT 'received',
    received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_by INT,
    FOREIGN KEY (processed_by) REFERENCES users(id)
);

-- ===========================================
-- INCIDENTS
-- ===========================================

-- Incidents created from calls or direct reports
CREATE TABLE incidents (
    id INT PRIMARY KEY AUTO_INCREMENT,
    call_id INT NULL, -- NULL if incident created directly
    title VARCHAR(200) NOT NULL,
    description TEXT,
    priority ENUM('low', 'medium', 'high') DEFAULT 'medium',
    status ENUM('active', 'dispatched', 'responding', 'on_scene', 'resolved', 'closed') DEFAULT 'active',
    incident_type ENUM('Medical Emergency', 'Fire', 'Police Emergency', 'Traffic Accident', 'Natural Disaster', 'Hazardous Material', 'Other') NOT NULL,
    location TEXT NOT NULL,
    latitude DECIMAL(10, 8) NULL,
    longitude DECIMAL(11, 8) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    dispatched_at TIMESTAMP NULL,
    resolved_at TIMESTAMP NULL,
    created_by INT,
    assigned_dispatcher INT,
    FOREIGN KEY (call_id) REFERENCES calls(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (assigned_dispatcher) REFERENCES users(id)
);

-- ===========================================
-- RESOURCES AND UNITS
-- ===========================================

-- Resource categories
CREATE TABLE resource_types (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(50) NOT NULL, -- 'Vehicle', 'Personnel', 'Equipment'
    description TEXT
);

-- Individual resources/units
CREATE TABLE resources (
    id INT PRIMARY KEY AUTO_INCREMENT,
    resource_type_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    identifier VARCHAR(50) UNIQUE, -- e.g., 'AMB-001', 'ENG-005'
    status ENUM('available', 'dispatched', 'on_scene', 'maintenance', 'out_of_service') DEFAULT 'available',
    location TEXT,
    latitude DECIMAL(10, 8) NULL,
    longitude DECIMAL(11, 8) NULL,
    capacity INT DEFAULT 1, -- number of personnel or capacity
    equipment_type VARCHAR(100), -- ambulance, fire engine, etc.
    agency_id INT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (resource_type_id) REFERENCES resource_types(id)
);

-- ===========================================
-- DISPATCH MANAGEMENT
-- ===========================================

-- Dispatch assignments
CREATE TABLE dispatches (
    id INT PRIMARY KEY AUTO_INCREMENT,
    incident_id INT NOT NULL,
    resource_id INT NOT NULL,
    dispatched_by INT NOT NULL,
    dispatched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    estimated_arrival TIMESTAMP NULL,
    actual_arrival TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    status ENUM('dispatched', 'en_route', 'on_scene', 'completed', 'cancelled') DEFAULT 'dispatched',
    notes TEXT,
    FOREIGN KEY (incident_id) REFERENCES incidents(id),
    FOREIGN KEY (resource_id) REFERENCES resources(id),
    FOREIGN KEY (dispatched_by) REFERENCES users(id)
);

-- ===========================================
-- INTER-AGENCY COORDINATION
-- ===========================================

-- Partner agencies
CREATE TABLE agencies (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    type ENUM('Police', 'Fire', 'Medical', 'Utility', 'Government', 'Other') NOT NULL,
    contact_person VARCHAR(100),
    contact_phone VARCHAR(20),
    contact_email VARCHAR(100),
    address TEXT,
    jurisdiction TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Inter-agency communications/coordination records
CREATE TABLE agency_coordination (
    id INT PRIMARY KEY AUTO_INCREMENT,
    incident_id INT NOT NULL,
    agency_id INT NOT NULL,
    coordination_type ENUM('request', 'offer', 'update', 'joint_response') NOT NULL,
    message TEXT NOT NULL,
    sent_by INT NOT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    response TEXT,
    responded_at TIMESTAMP NULL,
    status ENUM('sent', 'acknowledged', 'responded', 'completed') DEFAULT 'sent',
    FOREIGN KEY (incident_id) REFERENCES incidents(id),
    FOREIGN KEY (agency_id) REFERENCES agencies(id),
    FOREIGN KEY (sent_by) REFERENCES users(id)
);

-- ===========================================
-- GPS TRACKING
-- ===========================================

-- GPS location history for resources
CREATE TABLE gps_tracking (
    id INT PRIMARY KEY AUTO_INCREMENT,
    resource_id INT NOT NULL,
    latitude DECIMAL(10, 8) NOT NULL,
    longitude DECIMAL(11, 8) NOT NULL,
    speed DECIMAL(5, 2) NULL, -- km/h
    heading DECIMAL(5, 2) NULL, -- degrees
    accuracy DECIMAL(5, 2) NULL, -- meters
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('moving', 'stopped', 'emergency') DEFAULT 'moving',
    FOREIGN KEY (resource_id) REFERENCES resources(id),
    INDEX idx_resource_timestamp (resource_id, timestamp)
);

-- ===========================================
-- REPORTING AND ANALYTICS
-- ===========================================

-- Incident reports and analytics
CREATE TABLE incident_reports (
    id INT PRIMARY KEY AUTO_INCREMENT,
    incident_id INT NOT NULL,
    report_type ENUM('initial', 'progress', 'final', 'follow_up') NOT NULL,
    content TEXT NOT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    attachments TEXT, -- JSON array of file paths
    FOREIGN KEY (incident_id) REFERENCES incidents(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- System logs for auditing
CREATE TABLE system_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50), -- 'incident', 'call', 'dispatch', etc.
    entity_id INT,
    details TEXT,
    ip_address VARCHAR(45),
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- ===========================================
-- NOTIFICATIONS AND MESSAGES
-- ===========================================

-- Internal messages/notifications
CREATE TABLE messages (
    id INT PRIMARY KEY AUTO_INCREMENT,
    sender_id INT NOT NULL,
    recipient_id INT NULL, -- NULL for broadcast messages
    subject VARCHAR(200) NOT NULL,
    content TEXT NOT NULL,
    priority ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal',
    type ENUM('notification', 'alert', 'message', 'broadcast') DEFAULT 'message',
    is_read BOOLEAN DEFAULT FALSE,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP NULL,
    FOREIGN KEY (sender_id) REFERENCES users(id),
    FOREIGN KEY (recipient_id) REFERENCES users(id)
);

-- ===========================================
-- INITIAL DATA INSERTION
-- ===========================================

-- Insert default resource types
INSERT INTO resource_types (name, description) VALUES
('Vehicle', 'Emergency vehicles like ambulances, fire trucks, police cars'),
('Personnel', 'Emergency responders and staff'),
('Equipment', 'Specialized equipment and tools');

-- Insert sample agencies
INSERT INTO agencies (name, type, contact_person, contact_phone, contact_email) VALUES
('City Police Department', 'Police', 'Chief Johnson', '+1-555-0101', 'chief@citypolice.gov'),
('City Fire Department', 'Fire', 'Fire Chief Smith', '+1-555-0102', 'chief@cityfire.gov'),
('City Medical Services', 'Medical', 'EMS Director Brown', '+1-555-0103', 'director@cityems.gov'),
('State Highway Patrol', 'Police', 'Captain Davis', '+1-555-0104', 'captain@statepatrol.gov'),
('Utility Company', 'Utility', 'Operations Manager Wilson', '+1-555-0105', 'ops@utilityco.com');

-- Insert sample resources
INSERT INTO resources (resource_type_id, name, identifier, status, equipment_type, agency_id) VALUES
(1, 'Ambulance #1', 'AMB-001', 'available', 'Basic Life Support Ambulance', 3),
(1, 'Ambulance #2', 'AMB-002', 'available', 'Advanced Life Support Ambulance', 3),
(1, 'Engine #1', 'ENG-001', 'available', 'Fire Engine', 2),
(1, 'Engine #2', 'ENG-002', 'available', 'Ladder Truck', 2),
(1, 'Police Unit #1', 'POL-001', 'available', 'Patrol Car', 1),
(1, 'Police Unit #2', 'POL-002', 'available', 'SUV', 1),
(2, 'EMT Team Alpha', 'EMT-001', 'available', 'Emergency Medical Technician', 3),
(2, 'Firefighter Squad 1', 'FF-001', 'available', 'Firefighter', 2),
(2, 'Police Officer Unit 1', 'PO-001', 'available', 'Police Officer', 1),
(3, 'Defibrillator Unit', 'DEF-001', 'available', 'Automated External Defibrillator', 3),
(3, 'Jaws of Life', 'JOL-001', 'available', 'Hydraulic Rescue Tool', 2);

-- Create default admin user (password: admin123 - hashed)
-- Note: In production, use proper password hashing
INSERT INTO users (username, password_hash, email, full_name, role) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@ers.gov', 'System Administrator', 'admin'),
('dispatcher1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'dispatcher@ers.gov', 'Senior Dispatcher', 'dispatcher');

-- ===========================================
-- INDEXES FOR PERFORMANCE
-- ===========================================

-- Indexes for common queries
CREATE INDEX idx_incidents_status ON incidents(status);
CREATE INDEX idx_incidents_priority ON incidents(priority);
CREATE INDEX idx_incidents_created_at ON incidents(created_at);
CREATE INDEX idx_calls_status ON calls(status);
CREATE INDEX idx_calls_received_at ON calls(received_at);
CREATE INDEX idx_resources_status ON resources(status);
CREATE INDEX idx_dispatches_status ON dispatches(status);
CREATE INDEX idx_gps_tracking_resource_time ON gps_tracking(resource_id, timestamp);
CREATE INDEX idx_messages_recipient_read ON messages(recipient_id, is_read);

-- ===========================================
-- VIEWS FOR COMMON QUERIES
-- ===========================================

-- Active incidents view
CREATE VIEW active_incidents AS
SELECT i.*, c.caller_name, c.caller_phone
FROM incidents i
LEFT JOIN calls c ON i.call_id = c.id
WHERE i.status IN ('active', 'dispatched', 'responding', 'on_scene');

-- Available resources view
CREATE VIEW available_resources AS
SELECT r.*, rt.name as resource_type_name
FROM resources r
JOIN resource_types rt ON r.resource_type_id = rt.id
WHERE r.status = 'available' AND r.is_active = TRUE;

-- Current dispatches view
CREATE VIEW current_dispatches AS
SELECT d.*, i.title as incident_title, i.location as incident_location,
       r.name as resource_name, r.identifier as resource_identifier,
       u.full_name as dispatcher_name
FROM dispatches d
JOIN incidents i ON d.incident_id = i.id
JOIN resources r ON d.resource_id = r.id
JOIN users u ON d.dispatched_by = u.id
WHERE d.status IN ('dispatched', 'en_route', 'on_scene');