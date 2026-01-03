# Emergency Response System (ERS) Database Setup

This document provides instructions for setting up the database for the Emergency Response System.

## Database Overview

The ERS database (`ers_db`) is designed to manage emergency response operations including:

- Emergency call logging and tracking
- Incident management and prioritization
- Resource allocation and dispatch
- GPS tracking of emergency units
- Inter-agency coordination
- Reporting and analytics
- User management and authentication

## Database Schema

The database consists of the following main tables:

### Core Entities
- `users` - System users (admins, dispatchers)
- `calls` - Emergency calls received
- `incidents` - Emergency incidents
- `resources` - Emergency vehicles, personnel, and equipment
- `dispatches` - Resource assignments to incidents

### Supporting Tables
- `resource_types` - Categories for resources
- `agencies` - Partner agencies for coordination
- `agency_coordination` - Inter-agency communications
- `gps_tracking` - Location tracking data
- `incident_reports` - Incident documentation
- `messages` - Internal notifications
- `system_logs` - Audit trail

## Setup Instructions

### Prerequisites
- MySQL 5.7+ or MariaDB 10.0+
- PHP 7.4+ with mysqli extension
- XAMPP or similar local development environment

### Step 1: Create the Database

1. Open phpMyAdmin (usually at `http://localhost/phpmyadmin`)
2. Create a new database named `ers_db` with UTF-8 encoding
3. Alternatively, run this SQL command:
   ```sql
   CREATE DATABASE ers_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

### Step 2: Run the Schema Script

1. In phpMyAdmin, select the `ers_db` database
2. Go to the "Import" tab
3. Upload and import the `database_schema.sql` file
4. Alternatively, copy and paste the contents of `database_schema.sql` into the SQL query window and execute

### Step 3: Load Sample Data (Optional)

For testing and demonstration purposes:

1. In phpMyAdmin, with `ers_db` selected
2. Import the `sample_data.sql` file
3. This will populate the database with realistic sample emergency data

### Step 4: Configure Database Connection

The database connection is already configured in `includes/db.php`. Verify the settings:

```php
$host = "localhost";
$user = "root";
$pass = "";
$db = "ers_db";
```

Update these values if your MySQL setup is different.

### Step 5: Test the Connection

Create a simple test file to verify the database connection:

```php
<?php
include 'includes/db.php';
if ($conn) {
    echo "Database connection successful!";
} else {
    echo "Connection failed: " . mysqli_connect_error();
}
?>
```

## Default Users

After running the schema script, these default users are available:

- **Username:** admin
  **Password:** admin123
  **Role:** Administrator

- **Username:** dispatcher1
  **Password:** admin123
  **Role:** Dispatcher

**Important:** Change default passwords in production!

## Database Relationships

### Key Relationships:
- Calls → Incidents (one-to-one/many)
- Incidents → Dispatches (one-to-many)
- Resources → Dispatches (one-to-many)
- Incidents → Agency Coordination (one-to-many)
- Resources → GPS Tracking (one-to-many)

### Views Available:
- `active_incidents` - Currently active emergency incidents
- `available_resources` - Resources ready for dispatch
- `current_dispatches` - Ongoing resource assignments

## Backup and Maintenance

### Regular Backups
```sql
mysqldump -u root -p ers_db > ers_backup_$(date +%Y%m%d).sql
```

### Performance Optimization
The schema includes indexes on commonly queried fields:
- Incident status and priority
- Call timestamps
- Resource status
- GPS tracking data

### Data Retention
Consider implementing data archiving for:
- Resolved incidents (older than 1 year)
- GPS tracking data (older than 30 days)
- System logs (older than 90 days)

## Security Considerations

1. **Passwords:** Use strong, unique passwords for all users
2. **Access Control:** Implement proper user roles and permissions
3. **Input Validation:** Always sanitize user inputs to prevent SQL injection
4. **Encryption:** Consider encrypting sensitive data at rest
5. **Audit Logging:** The `system_logs` table tracks all user actions

## Troubleshooting

### Common Issues:

1. **Connection Failed**
   - Verify MySQL service is running
   - Check database credentials in `db.php`
   - Ensure user has proper permissions

2. **Import Errors**
   - Check for syntax errors in SQL files
   - Ensure database user has CREATE/INSERT permissions
   - Verify character set compatibility

3. **Performance Issues**
   - Check indexes are created properly
   - Monitor query execution times
   - Consider database optimization

## Support

For technical support or questions about the database schema, refer to the system documentation or contact the development team.