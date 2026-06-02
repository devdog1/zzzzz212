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
