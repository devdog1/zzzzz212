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

INSERT OR IGNORE INTO `defaults` (`setting_key`, `setting_value`, `description`)
VALUES ('otrs_enabled', '0', 'Enable OTRS ticket integration (0 or 1)');

INSERT OR IGNORE INTO `defaults` (`setting_key`, `setting_value`, `description`)
VALUES ('otrs_customer_user', 'customer@example.com', 'Default customer user for OTRS tickets');

CREATE TABLE IF NOT EXISTS `navigation` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `label` TEXT NOT NULL,
    `url` TEXT NOT NULL,
    `permission` TEXT NULL,
    `parent_id` INTEGER NULL,
    `alignment` TEXT DEFAULT 'left',
    `weight` INTEGER DEFAULT 0,
    `is_external` INTEGER DEFAULT 0,
    FOREIGN KEY (`parent_id`) REFERENCES `navigation`(`id`) ON DELETE CASCADE
);

INSERT OR IGNORE INTO `navigation` (`id`, `label`, `url`, `permission`, `parent_id`, `alignment`, `weight`, `is_external`) VALUES
(1, 'Active', 'index.php', 'events.manage', NULL, 'left', 10, 0),
(2, 'Archive', 'closed.php', 'events.manage', NULL, 'left', 20, 0),
(3, 'Search', 'search.php', 'events.manage', NULL, 'left', 30, 0),
(4, 'Analytics', '#', 'events.manage', NULL, 'left', 40, 0),
(5, 'Statistics', 'statistics.php', 'events.manage', 4, 'left', 10, 0),
(6, 'Reports', 'reports.php', 'events.manage', 4, 'left', 20, 0),
(7, 'Admin', '#', 'admin.panel', NULL, 'right', 100, 0),
(8, 'Departments', 'departments.php', 'admin.panel', 7, 'right', 10, 0),
(9, 'Settings', 'settings.php', 'admin.panel', 7, 'right', 20, 0),
(10, 'API Docs', 'api-docs.php', 'admin.panel', 7, 'right', 30, 0);
