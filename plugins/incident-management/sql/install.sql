-- MySQL Schema for Incident Management Plugin

CREATE TABLE IF NOT EXISTS `plug_incident_management_type` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `plug_incident_management_department` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL UNIQUE,
    `azure_group_id` VARCHAR(255)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `plug_incident_management_state` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `plug_incident_management_service` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `plug_incident_management_tag` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `plug_incident_management_area` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `plug_incident_management_wb_events` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `type_id` INT,
    `ticket_id` VARCHAR(255) DEFAULT '0',
    `ticket_nr` VARCHAR(255) DEFAULT '0',
    `create_user` VARCHAR(255) DEFAULT '0',
    `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `update_time` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `update_user` VARCHAR(255),
    `department_id` INT,
    `customers_affected` INT DEFAULT 0,
    `description` TEXT,
    `state_id` INT DEFAULT 0,
    `teams_message_Id` VARCHAR(255),
    `teams_chat_id` VARCHAR(255),
    `impactScoreNotified` INT DEFAULT 0,
    `impactScore` INT DEFAULT 0,
    FOREIGN KEY (`type_id`) REFERENCES `plug_incident_management_type`(`id`),
    FOREIGN KEY (`department_id`) REFERENCES `plug_incident_management_department`(`id`),
    FOREIGN KEY (`state_id`) REFERENCES `plug_incident_management_state`(`id`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `plug_incident_management_event_services` (
    `event_id` INT,
    `service_id` INT,
    PRIMARY KEY (`event_id`, `service_id`),
    FOREIGN KEY (`event_id`) REFERENCES `plug_incident_management_wb_events`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`service_id`) REFERENCES `plug_incident_management_service`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `plug_incident_management_event_tags` (
    `event_id` INT,
    `tag_id` INT,
    PRIMARY KEY (`event_id`, `tag_id`),
    FOREIGN KEY (`event_id`) REFERENCES `plug_incident_management_wb_events`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`tag_id`) REFERENCES `plug_incident_management_tag`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `plug_incident_management_event_areas` (
    `event_id` INT,
    `area_id` INT,
    PRIMARY KEY (`event_id`, `area_id`),
    FOREIGN KEY (`event_id`) REFERENCES `plug_incident_management_wb_events`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`area_id`) REFERENCES `plug_incident_management_area`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `plug_incident_management_event_updates` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `event_id` INT NOT NULL,
    `update_text` TEXT,
    `create_user` VARCHAR(255),
    `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`event_id`) REFERENCES `plug_incident_management_wb_events`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `plug_incident_management_event_state_history` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `event_id` INT NOT NULL,
    `state_id` INT NOT NULL,
    `enter_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `exit_time` DATETIME NULL,
    `user` VARCHAR(255),
    FOREIGN KEY (`event_id`) REFERENCES `plug_incident_management_wb_events`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`state_id`) REFERENCES `plug_incident_management_state`(`id`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `plug_incident_management_audit_log` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `table_name` VARCHAR(255),
    `record_id` INT,
    `action` VARCHAR(50),
    `old_values` TEXT,
    `new_values` TEXT,
    `user` VARCHAR(255),
    `timestamp` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `plug_incident_management_defaults` (
    `setting_key` VARCHAR(255) PRIMARY KEY,
    `setting_value` TEXT,
    `description` VARCHAR(255)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `plug_incident_management_event_circuits` (
    `event_id` INT,
    `circuit_id` INT,
    `circuit_cid` VARCHAR(255),
    `provider` VARCHAR(255),
    PRIMARY KEY (`event_id`, `circuit_id`),
    FOREIGN KEY (`event_id`) REFERENCES `plug_incident_management_wb_events`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `plug_incident_management_external_message_log` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `event_id` INT NOT NULL,
    `recipient` VARCHAR(255),
    `subject` VARCHAR(255),
    `message` TEXT,
    `sent_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`event_id`) REFERENCES `plug_incident_management_wb_events`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Seed Default States
INSERT IGNORE INTO `plug_incident_management_state` (`id`, `name`) VALUES
(1, 'Detected'),
(2, 'Acknowledged'),
(3, 'Investigating'),
(4, 'Identified'),
(5, 'Mitigating'),
(6, 'Closed'),
(7, 'Reopened');

-- Seed Default Types
INSERT IGNORE INTO `plug_incident_management_type` (`id`, `name`) VALUES
(1, 'Outage'),
(2, 'Degradation'),
(3, 'Maintenance');

-- Seed Default Settings
INSERT IGNORE INTO `plug_incident_management_defaults` (`setting_key`, `setting_value`, `description`) VALUES
('always_include_azure_group_id', NULL, 'Azure AD Group ID to always include in all incident Teams chats'),
('otrs_enabled', '0', 'Enable OTRS ticket integration (0 or 1)'),
('otrs_customer_user', 'customer@example.com', 'Default customer user for OTRS tickets'),
('otrs_db_host', '127.0.0.1', 'OTRS MySQL Database Host'),
('otrs_db_name', 'otrs', 'OTRS Database Name'),
('otrs_db_user', 'otrs_user', 'OTRS Database Username'),
('otrs_db_pass', '', 'OTRS Database Password'),
('otrs_ticket_link', 'https://otrs.example.com/otrs/index.pl?Action=AgentTicketZoom;TicketID=', 'OTRS Agent Ticket Link Base URL'),
('otrs_change_link', 'https://otrs.example.com/otrs/index.pl?Action=AgentITSMChangeZoom;ChangeID=', 'OTRS Agent Change Link Base URL'),
('netbox_enabled', '0', 'Enable NetBox circuit integration (0 or 1)'),
('netbox_url', '', 'NetBox API URL'),
('netbox_token', '', 'NetBox API Token'),
('external_email_template', 'Hello,\n\nAn incident has been reported that may affect your circuit {circuit_cid}.\n\nIncident Description: {description}\nLatest Update: {update_text}\n\nWe will keep you informed.', 'Template for external circuit owner emails');
