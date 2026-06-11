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
(12, 'Overview', 'overview.php', 'events.manage', NULL, 'left', 5, 0),
(13, 'Calendar', 'calendar.php', 'events.manage', NULL, 'left', 8, 0),
(1, 'Active', 'index.php', 'events.manage', NULL, 'left', 10, 0),
(14, 'Video URLs', 'videoUrls.php', 'videoLinks.view', NULL, 'left', 15, 0),
(2, 'Archive', 'closed.php', 'events.manage', NULL, 'left', 20, 0),
(3, 'Search', 'search.php', 'events.manage', NULL, 'left', 30, 0),
(4, 'Analytics', '#', 'events.manage', NULL, 'left', 40, 0),
(5, 'Statistics', 'statistics.php', 'events.manage', 4, 'left', 10, 0),
(6, 'Reports', 'reports.php', 'events.manage', 4, 'left', 20, 0),
(7, 'Admin', '#', 'admin.panel', NULL, 'right', 100, 0),
(8, 'Departments', 'departments.php', 'admin.panel', 7, 'right', 10, 0),
(9, 'Settings', 'settings.php', 'admin.panel', 7, 'right', 20, 0),
(11, 'Navigation', 'navigation-manage.php', 'admin.panel', 7, 'right', 25, 0),
(10, 'API Docs', 'api-docs.php', 'admin.panel', 7, 'right', 30, 0);

-- Authentication and RBAC Tables
CREATE TABLE IF NOT EXISTS `users` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `azure_oid` TEXT UNIQUE,
    `username` TEXT UNIQUE,
    `email` TEXT,
    `display_name` TEXT,
    `auto_provisioned` INTEGER DEFAULT 0,
    `last_login` DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS `roles` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `role_name` TEXT UNIQUE
);

CREATE TABLE IF NOT EXISTS `permissions` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `permission_name` TEXT UNIQUE
);

CREATE TABLE IF NOT EXISTS `role_permissions` (
    `role_id` INTEGER,
    `permission_id` INTEGER,
    PRIMARY KEY (`role_id`, `permission_id`),
    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS `user_roles` (
    `user_id` INTEGER,
    `role_id` INTEGER,
    PRIMARY KEY (`user_id`, `role_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS `user_permissions` (
    `user_id` INTEGER,
    `permission_id` INTEGER,
    PRIMARY KEY (`user_id`, `permission_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS `denied_permissions` (
    `user_id` INTEGER,
    `permission_id` INTEGER,
    PRIMARY KEY (`user_id`, `permission_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS `azure_group_roles` (
    `azure_group_name` TEXT,
    `role_id` INTEGER,
    PRIMARY KEY (`azure_group_name`, `role_id`),
    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS `default_roles` (
    `role_id` INTEGER PRIMARY KEY,
    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE
);

-- Seed Initial Auth Data
INSERT OR IGNORE INTO roles (role_name) VALUES ('admin'), ('manager');
INSERT OR IGNORE INTO permissions (permission_name) VALUES ('admin.panel'), ('events.manage'), ('videoLinks.view'), ('videoLinks.edit');

INSERT OR IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p WHERE r.role_name = 'admin';

INSERT OR IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p WHERE r.role_name = 'manager' AND p.permission_name IN ('events.manage', 'videoLinks.view');

INSERT OR IGNORE INTO default_roles (role_id) SELECT id FROM roles WHERE role_name = 'manager';
