# Event Management System - Setup Guide

This guide explains how to set up the Event Management System using a MySQL backend.

## 1. Database Setup

1. **Create the Database**:
   Log in to your MySQL server and create a new database:
   ```sql
   CREATE DATABASE event_mgmt;
   ```

2. **Import the Schema**:
   Import the provided `schema.sql` file into your newly created database:
   ```bash
   mysql -u your_username -p event_mgmt < schema.sql
   ```

3. **Seeding Initial Data (Optional but recommended)**:
   You should add initial states, types, and departments. You can do this via the `EventManager` class or directly in SQL:
   ```sql
   INSERT INTO type (name) VALUES ('Outage'), ('Degradation');
   INSERT INTO state (name) VALUES ('Identified'), ('Monitoring'), ('Resolved');
   INSERT INTO department (name) VALUES ('IT Support'), ('Network Operations');
   ```

## 2. Configuration

Open `/inc/config.php` and update the database parameters to match your environment:

```php
$config['db']['events'] = [
    'dbhost' => 'localhost',     // Your MySQL Host
    'dbname' => 'event_mgmt',    // Your Database Name
    'dbuser' => 'your_user',     // Your MySQL Username
    'dbpass' => 'your_password'  // Your MySQL Password
];

// The Auth class also requires a 'local' database entry
$config['db']['local'] = [
    'dbhost' => 'localhost',
    'dbname' => 'auth_db',       // This can be the same as event_mgmt or a separate DB
    'dbuser' => 'your_user',
    'dbpass' => 'your_password'
];
```

## 3. Web Server Configuration

1. Ensure your web server (Apache/Nginx) has the PDO MySQL extension enabled.
2. Point your document root to the project folder.
3. Ensure the `/inc/config.php` file is readable by the web server but not accessible directly via the browser (e.g., via `.htaccess` or placing it outside the public root).

## 4. Authentication

The system uses Azure AD SSO. Ensure you have registered an application in the Azure Portal and updated the `azure` section in `/inc/config.php` with your `clientId`, `clientSecret`, and `redirectUri`.

## 5. Troubleshooting

- **Connection Refused**: Double-check your `dbhost`, `dbuser`, and `dbpass`.
- **Table Not Found**: Ensure you imported `schema.sql` into the correct database.
- **Session Issues**: Ensure the web server has permission to write session files.
