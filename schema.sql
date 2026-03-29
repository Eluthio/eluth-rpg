CREATE TABLE IF NOT EXISTS `rpg_sessions` (
    `id` char(36) NOT NULL,
    `channel_id` char(36) NOT NULL,
    `gm_member_id` varchar(255) NOT NULL,
    `gm_username` varchar(255) NOT NULL,
    `status` varchar(50) NOT NULL DEFAULT 'waiting',
    `created_at` timestamp NULL,
    `updated_at` timestamp NULL,
    PRIMARY KEY (`id`),
    KEY `rpg_sessions_channel_id_index` (`channel_id`)
);

CREATE TABLE IF NOT EXISTS `rpg_players` (
    `id` char(36) NOT NULL,
    `session_id` char(36) NOT NULL,
    `member_id` varchar(255) NOT NULL,
    `username` varchar(255) NOT NULL,
    `joined_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `rpg_players_session_member_unique` (`session_id`, `member_id`),
    KEY `rpg_players_session_id_index` (`session_id`)
);

CREATE TABLE IF NOT EXISTS `rpg_character_templates` (
    `id` char(36) NOT NULL,
    `session_id` char(36) NOT NULL,
    `name` varchar(255) NOT NULL,
    `fields` json NOT NULL,
    `created_at` timestamp NULL,
    `updated_at` timestamp NULL,
    PRIMARY KEY (`id`),
    KEY `rpg_character_templates_session_id_index` (`session_id`)
);

CREATE TABLE IF NOT EXISTS `rpg_characters` (
    `id` char(36) NOT NULL,
    `session_id` char(36) NOT NULL,
    `member_id` varchar(255) NOT NULL,
    `username` varchar(255) NOT NULL,
    `template_id` char(36) NULL,
    `data` json NULL,
    `created_at` timestamp NULL,
    `updated_at` timestamp NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `rpg_characters_session_member_unique` (`session_id`, `member_id`),
    KEY `rpg_characters_session_id_index` (`session_id`)
);

CREATE TABLE IF NOT EXISTS `rpg_roll_queue` (
    `id` char(36) NOT NULL,
    `session_id` char(36) NOT NULL,
    `requested_by_member_id` varchar(255) NOT NULL,
    `assigned_to_member_id` varchar(255) NOT NULL,
    `dice_type` varchar(20) NOT NULL,
    `note` varchar(255) NULL,
    `status` varchar(20) NOT NULL DEFAULT 'pending',
    `created_at` timestamp NULL,
    `updated_at` timestamp NULL,
    PRIMARY KEY (`id`),
    KEY `rpg_roll_queue_session_id_index` (`session_id`)
);

CREATE TABLE IF NOT EXISTS `rpg_rolls` (
    `id` char(36) NOT NULL,
    `session_id` char(36) NOT NULL,
    `queue_id` char(36) NULL,
    `roller_member_id` varchar(255) NOT NULL,
    `roller_username` varchar(255) NOT NULL,
    `dice_type` varchar(20) NOT NULL,
    `result` int NOT NULL,
    `is_public` tinyint(1) NOT NULL DEFAULT 1,
    `note` varchar(255) NULL,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `rpg_rolls_session_id_index` (`session_id`)
);

CREATE TABLE IF NOT EXISTS `rpg_messages` (
    `id` char(36) NOT NULL,
    `session_id` char(36) NOT NULL,
    `author_member_id` varchar(255) NOT NULL,
    `author_username` varchar(255) NOT NULL,
    `content` text NOT NULL,
    `is_whisper` tinyint(1) NOT NULL DEFAULT 0,
    `target_member_id` varchar(255) NULL,
    `target_username` varchar(255) NULL,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `rpg_messages_session_id_index` (`session_id`)
);
