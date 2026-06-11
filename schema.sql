-- MySQL Schema for Event Management System

CREATE TABLE IF NOT EXISTS `type` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `department` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL UNIQUE,
    `azure_group_id` VARCHAR(255)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `state` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `service` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `tag` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `area` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `wb_events` (
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
    FOREIGN KEY (`type_id`) REFERENCES `type`(`id`),
    FOREIGN KEY (`department_id`) REFERENCES `department`(`id`),
    FOREIGN KEY (`state_id`) REFERENCES `state`(`id`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `event_services` (
    `event_id` INT,
    `service_id` INT,
    PRIMARY KEY (`event_id`, `service_id`),
    FOREIGN KEY (`event_id`) REFERENCES `wb_events`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`service_id`) REFERENCES `service`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `event_tags` (
    `event_id` INT,
    `tag_id` INT,
    PRIMARY KEY (`event_id`, `tag_id`),
    FOREIGN KEY (`event_id`) REFERENCES `wb_events`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`tag_id`) REFERENCES `tag`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `event_areas` (
    `event_id` INT,
    `area_id` INT,
    PRIMARY KEY (`event_id`, `area_id`),
    FOREIGN KEY (`event_id`) REFERENCES `wb_events`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`area_id`) REFERENCES `area`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `event_updates` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `event_id` INT NOT NULL,
    `update_text` TEXT,
    `create_user` VARCHAR(255),
    `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`event_id`) REFERENCES `wb_events`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `event_state_history` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `event_id` INT NOT NULL,
    `state_id` INT NOT NULL,
    `enter_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `exit_time` DATETIME NULL,
    `user` VARCHAR(255),
    FOREIGN KEY (`event_id`) REFERENCES `wb_events`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`state_id`) REFERENCES `state`(`id`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `audit_log` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `table_name` VARCHAR(255),
    `record_id` INT,
    `action` VARCHAR(50), -- CREATE, UPDATE, DELETE
    `old_values` TEXT,
    `new_values` TEXT,
    `user` VARCHAR(255),
    `timestamp` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `defaults` (
    `setting_key` VARCHAR(255) PRIMARY KEY,
    `setting_value` TEXT,
    `description` VARCHAR(255)
) ENGINE=InnoDB;

INSERT IGNORE INTO `defaults` (`setting_key`, `setting_value`, `description`)
VALUES ('always_include_azure_group_id', NULL, 'Azure AD Group ID to always include in all incident Teams chats');

INSERT IGNORE INTO `defaults` (`setting_key`, `setting_value`, `description`)
VALUES ('otrs_enabled', '0', 'Enable OTRS ticket integration (0 or 1)');

INSERT IGNORE INTO `defaults` (`setting_key`, `setting_value`, `description`)
VALUES ('otrs_customer_user', 'customer@example.com', 'Default customer user for OTRS tickets');

CREATE TABLE IF NOT EXISTS `navigation` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `label` VARCHAR(255) NOT NULL,
    `url` VARCHAR(255) NOT NULL,
    `permission` VARCHAR(255) NULL,
    `parent_id` INT NULL,
    `alignment` ENUM('left', 'right') DEFAULT 'left',
    `weight` INT DEFAULT 0,
    `is_external` TINYINT(1) DEFAULT 0,
    FOREIGN KEY (`parent_id`) REFERENCES `navigation`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT IGNORE INTO `navigation` (`label`, `url`, `permission`, `parent_id`, `alignment`, `weight`, `is_external`) VALUES
('Active', 'index.php', 'events.manage', NULL, 'left', 10, 0),
('Archive', 'closed.php', 'events.manage', NULL, 'left', 20, 0),
('Search', 'search.php', 'events.manage', NULL, 'left', 30, 0),
('Analytics', '#', 'events.manage', NULL, 'left', 40, 0);

-- Get IDs for nested items (MySQL specific, but illustrative)
SET @analytics_id = (SELECT id FROM navigation WHERE label = 'Analytics' LIMIT 1);

INSERT IGNORE INTO `navigation` (`label`, `url`, `permission`, `parent_id`, `alignment`, `weight`, `is_external`) VALUES
('Statistics', 'statistics.php', 'events.manage', @analytics_id, 'left', 10, 0),
('Reports', 'reports.php', 'events.manage', @analytics_id, 'left', 20, 0);

INSERT IGNORE INTO `navigation` (`label`, `url`, `permission`, `parent_id`, `alignment`, `weight`, `is_external`) VALUES
('Admin', '#', 'admin.panel', NULL, 'right', 100, 0);

SET @admin_id = (SELECT id FROM navigation WHERE label = 'Admin' LIMIT 1);

INSERT IGNORE INTO `navigation` (`label`, `url`, `permission`, `parent_id`, `alignment`, `weight`, `is_external`) VALUES
('Departments', 'departments.php', 'admin.panel', @admin_id, 'right', 10, 0),
('Settings', 'settings.php', 'admin.panel', @admin_id, 'right', 20, 0),
('API Docs', 'api-docs.php', 'admin.panel', @admin_id, 'right', 30, 0);
