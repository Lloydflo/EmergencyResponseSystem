-- Sample Data for Emergency Response System (ERS)
-- This script inserts sample data for testing and demonstration

USE ers_db;

-- ===========================================
-- SAMPLE INCIDENTS AND CALLS
-- ===========================================

-- Insert sample emergency calls
INSERT INTO calls (caller_name, caller_phone, incident_type, priority, location, description, dispatcher_notes, status) VALUES
('John Smith', '+1-555-0123', 'Medical Emergency', 'high', '123 Emergency Ave, Downtown', 'Elderly man experiencing chest pains and difficulty breathing', 'Patient is conscious but in severe distress', 'dispatched'),
('Sarah Johnson', '+1-555-0456', 'Fire', 'high', '456 Oak Street, Apt 3B', 'Apartment building fire on the 3rd floor, heavy smoke reported', 'Multiple residents evacuating, possible trapped occupants', 'dispatched'),
('Mike Davis', '+1-555-0789', 'Traffic Accident', 'medium', 'Highway 101, Mile Marker 45', 'Two vehicle collision, one rollover, injuries reported', 'Traffic backed up for miles, requesting tow trucks', 'processing'),
('Lisa Chen', '+1-555-0321', 'Police Emergency', 'medium', '789 Main Street, Store Front', 'Burglary in progress at local jewelry store', 'Suspects still on scene, armed and dangerous', 'dispatched'),
('Robert Wilson', '+1-555-0654', 'Medical Emergency', 'low', '321 Park Avenue, Suite 200', 'Patient with minor laceration requiring stitches', 'Non-life threatening injury', 'received');

-- Insert sample incidents (some linked to calls, some direct)
INSERT INTO incidents (call_id, title, description, priority, status, incident_type, location, latitude, longitude, created_by, assigned_dispatcher) VALUES
(1, 'Cardiac Arrest - Downtown Hospital', 'Elderly patient in cardiac arrest requiring immediate medical attention', 'high', 'responding', 'Medical Emergency', '123 Emergency Ave, Downtown', 40.7128, -74.0060, 2, 2),
(2, 'Structure Fire - Apartment Building', 'Multi-story apartment building fire with heavy smoke and possible trapped residents', 'high', 'on_scene', 'Fire', '456 Oak Street, Apt 3B', 40.7589, -73.9851, 2, 2),
(3, 'Multi-Vehicle Accident - Highway 101', 'Two vehicle collision with injuries and traffic disruption', 'medium', 'dispatched', 'Traffic Accident', 'Highway 101, Mile Marker 45', 40.7505, -73.9934, 2, 2),
(NULL, 'Power Outage - Residential Area', 'Widespread power outage affecting 200+ homes, possible downed power lines', 'medium', 'active', 'Other', 'Elm Street Neighborhood', 40.7282, -73.7949, 1, 2),
(4, 'Commercial Burglary - Jewelry Store', 'Armed burglary in progress at downtown jewelry store', 'high', 'responding', 'Police Emergency', '789 Main Street, Store Front', 40.7505, -73.9934, 2, 2);

-- ===========================================
-- SAMPLE DISPATCHES
-- ===========================================

-- Dispatch resources to incidents
INSERT INTO dispatches (incident_id, resource_id, dispatched_by, estimated_arrival, status, notes) VALUES
(1, 1, 2, DATE_ADD(NOW(), INTERVAL 8 MINUTE), 'en_route', 'ALS ambulance with full cardiac team'),
(1, 7, 2, DATE_ADD(NOW(), INTERVAL 6 MINUTE), 'en_route', 'EMT team for immediate response'),
(2, 3, 2, DATE_ADD(NOW(), INTERVAL 5 MINUTE), 'on_scene', 'Engine company with full firefighting crew'),
(2, 4, 2, DATE_ADD(NOW(), INTERVAL 7 MINUTE), 'en_route', 'Ladder truck for roof access'),
(2, 8, 2, DATE_ADD(NOW(), INTERVAL 4 MINUTE), 'on_scene', 'Firefighter squad for search and rescue'),
(3, 5, 2, DATE_ADD(NOW(), INTERVAL 10 MINUTE), 'dispatched', 'Police unit for traffic control'),
(3, 6, 2, DATE_ADD(NOW(), INTERVAL 12 MINUTE), 'dispatched', 'Additional police support'),
(4, 1, 2, DATE_ADD(NOW(), INTERVAL 15 MINUTE), 'dispatched', 'Utility company coordination'),
(5, 5, 2, DATE_ADD(NOW(), INTERVAL 3 MINUTE), 'en_route', 'SWAT team requested'),
(5, 9, 2, DATE_ADD(NOW(), INTERVAL 2 MINUTE), 'en_route', 'Police officer for immediate response');

-- ===========================================
-- SAMPLE GPS TRACKING DATA
-- ===========================================

-- GPS data for dispatched units
INSERT INTO gps_tracking (resource_id, latitude, longitude, speed, heading, accuracy, status) VALUES
(1, 40.7128, -74.0060, 45.5, 90.0, 5.0, 'moving'), -- Ambulance #1 heading to incident
(3, 40.7589, -73.9851, 0.0, 0.0, 3.0, 'stopped'), -- Engine #1 on scene
(5, 40.7505, -73.9934, 35.0, 180.0, 4.0, 'moving'), -- Police Unit #1 responding
(7, 40.7128, -74.0060, 42.0, 85.0, 5.5, 'moving'), -- EMT Team Alpha
(8, 40.7589, -73.9851, 0.0, 0.0, 2.5, 'stopped'), -- Firefighter Squad on scene
(9, 40.7505, -73.9934, 38.0, 175.0, 4.2, 'moving'); -- Police Officer responding

-- ===========================================
-- SAMPLE AGENCY COORDINATION
-- ===========================================

-- Inter-agency coordination records
INSERT INTO agency_coordination (incident_id, agency_id, coordination_type, message, sent_by, status) VALUES
(2, 2, 'request', 'Requesting additional fire suppression resources for apartment building fire', 2, 'acknowledged'),
(2, 3, 'offer', 'EMS standing by for potential injuries from fire incident', 2, 'responded'),
(3, 4, 'request', 'Highway Patrol assistance needed for traffic accident on Highway 101', 2, 'sent'),
(5, 1, 'joint_response', 'Coordinating with local police for armed burglary response', 2, 'completed');

-- ===========================================
-- SAMPLE INCIDENT REPORTS
-- ===========================================

-- Progress reports on incidents
INSERT INTO incident_reports (incident_id, report_type, content, created_by) VALUES
(1, 'initial', 'Cardiac arrest patient found unconscious. CPR initiated by bystander. AED applied. Patient showing weak pulse.', 2),
(2, 'progress', 'Fire contained to apartment 3B. Two occupants rescued from adjacent units. Fire investigation underway.', 2),
(3, 'initial', 'Two vehicle collision. Driver of sedan has moderate injuries. Passenger in rollover vehicle unconscious. Extrication in progress.', 2),
(5, 'initial', 'Burglary suspects have fled the scene. Jewelry store secure. Investigating security footage.', 2);

-- ===========================================
-- SAMPLE MESSAGES
-- ===========================================

-- Internal notifications
INSERT INTO messages (sender_id, recipient_id, subject, content, priority, type) VALUES
(1, NULL, 'System Maintenance Tonight', 'Scheduled system maintenance from 2-4 AM. All non-emergency systems will be offline.', 'normal', 'notification'),
(2, 1, 'Resource Shortage Alert', 'Ambulance #3 is out of service for maintenance. Only 2 ALS units available.', 'high', 'alert'),
(2, NULL, 'Weather Alert', 'Severe thunderstorm warning in effect. All units prepare for potential increase in emergency calls.', 'high', 'broadcast');

-- ===========================================
-- SAMPLE SYSTEM LOGS
-- ===========================================

-- Audit logs
INSERT INTO system_logs (user_id, action, entity_type, entity_id, details) VALUES
(2, 'created', 'incident', 1, 'Created incident from emergency call #1'),
(2, 'dispatched', 'resource', 1, 'Dispatched Ambulance #1 to incident #1'),
(2, 'updated', 'incident', 2, 'Updated incident #2 status to on_scene'),
(1, 'login', 'user', 1, 'Administrator login from 192.168.1.100'),
(2, 'coordination', 'agency', 2, 'Requested assistance from City Fire Department for incident #2');