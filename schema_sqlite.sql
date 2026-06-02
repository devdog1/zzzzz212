-- SQLite Schema for Event Management System

CREATE TABLE IF NOT EXISTS `type` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `name` TEXT NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS `department` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `name` TEXT NOT NULL UNIQUE,
    `azure_group_id` TEXT
);

CREATE TABLE IF NOT EXISTS `state` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `name` TEXT NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS `service` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `name` TEXT NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS `tag` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `name` TEXT NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS `area` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `name` TEXT NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS `wb_events` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `type_id` INTEGER,
    `ticket_id` TEXT DEFAULT '0',
    `ticket_nr` TEXT DEFAULT '0',
    `create_user` TEXT DEFAULT '0',
    `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `update_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `update_user` TEXT,
    `department_id` INTEGER,
    `customers_affected` INTEGER DEFAULT 0,
    `description` TEXT,
    `state_id` INTEGER DEFAULT 0,
    `teams_message_Id` TEXT,
    `teams_chat_id` TEXT,
    `impactScoreNotified` INTEGER DEFAULT 0,
    `impactScore` INTEGER DEFAULT 0,
    FOREIGN KEY (`type_id`) REFERENCES `type`(`id`),
    FOREIGN KEY (`department_id`) REFERENCES `department`(`id`),
    FOREIGN KEY (`state_id`) REFERENCES `state`(`id`)
);

CREATE TABLE IF NOT EXISTS `event_services` (
    `event_id` INTEGER,
    `service_id` INTEGER,
    PRIMARY KEY (`event_id`, `service_id`),
    FOREIGN KEY (`event_id`) REFERENCES `wb_events`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`service_id`) REFERENCES `service`(`id`) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS `event_tags` (
    `event_id` INTEGER,
    `tag_id` INTEGER,
    PRIMARY KEY (`event_id`, `tag_id`),
    FOREIGN KEY (`event_id`) REFERENCES `wb_events`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`tag_id`) REFERENCES `tag`(`id`) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS `event_areas` (
    `event_id` INTEGER,
    `area_id` INTEGER,
    PRIMARY KEY (`event_id`, `area_id`),
    FOREIGN KEY (`event_id`) REFERENCES `wb_events`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`area_id`) REFERENCES `area`(`id`) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS `event_updates` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `event_id` INTEGER NOT NULL,
    `update_text` TEXT,
    `create_user` TEXT,
    `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`event_id`) REFERENCES `wb_events`(`id`) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS `event_state_history` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `event_id` INTEGER NOT NULL,
    `state_id` INTEGER NOT NULL,
    `enter_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `exit_time` DATETIME NULL,
    `user` TEXT,
    FOREIGN KEY (`event_id`) REFERENCES `wb_events`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`state_id`) REFERENCES `state`(`id`)
);

CREATE TABLE IF NOT EXISTS `audit_log` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `table_name` TEXT,
    `record_id` INTEGER,
    `action` TEXT, -- CREATE, UPDATE, DELETE
    `old_values` TEXT,
    `new_values` TEXT,
    `user` TEXT,
    `timestamp` DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS `defaults` (
    `setting_key` TEXT PRIMARY KEY,
    `setting_value` TEXT,
    `description` TEXT
);

INSERT OR IGNORE INTO `defaults` (`setting_key`, `setting_value`, `description`)
VALUES ('always_include_azure_group_id', NULL, 'Azure AD Group ID to always include in all incident Teams chats');
