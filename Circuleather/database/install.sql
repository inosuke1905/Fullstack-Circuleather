-- Complete schema for a NEW Circuleather installation. No accounts or business data are included.
-- Docker creates the database and its application user before importing this file.
-- Historical migration scripts are for older installations; do not run them after this schema.

-- Accounts, roles and saved preferences.
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `full_name` varchar(120) NOT NULL,
  `email` varchar(254) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `language` enum('nl','en') NOT NULL DEFAULT 'nl',
  `theme` enum('light','dark','system') NOT NULL DEFAULT 'light',
  `low_stock_notifications` tinyint(1) NOT NULL DEFAULT 1,
  `order_notifications` tinyint(1) NOT NULL DEFAULT 1,
  `role` enum('admin','worker') NOT NULL DEFAULT 'worker',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `inventory_notifications` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inventory sold by kilogram.
CREATE TABLE `batches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sku` varchar(64) DEFAULT NULL,
  `material_name` varchar(150) NOT NULL,
  `grade` enum('A','B','C','snippers') NOT NULL,
  `color` varchar(80) DEFAULT NULL,
  `thickness` varchar(40) DEFAULT NULL,
  `sale_price` decimal(10,2) NOT NULL,
  `cost_price` decimal(10,2) NOT NULL,
  `unit` enum('kg','piece') NOT NULL DEFAULT 'kg',
  `stock` decimal(10,2) unsigned NOT NULL DEFAULT 0.00,
  `minimum_stock` decimal(10,2) unsigned NOT NULL DEFAULT 0.00,
  `origin` text DEFAULT NULL,
  `supplier` varchar(150) NOT NULL,
  `arrival_date` date DEFAULT NULL,
  `batch_photo_path` varchar(255) DEFAULT NULL,
  `inspection_photo_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_by_name` varchar(120) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_batches_sku` (`sku`),
  KEY `idx_batches_material_name` (`material_name`),
  KEY `idx_batches_grade` (`grade`),
  CONSTRAINT `chk_batches_unit` CHECK (`unit` = 'kg')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Separate inventory sold in whole pieces.
CREATE TABLE `individual_pieces` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sku` varchar(64) DEFAULT NULL,
  `material_name` varchar(150) NOT NULL,
  `grade` enum('A','B','C','snippers') NOT NULL,
  `color` varchar(80) DEFAULT NULL,
  `thickness` varchar(40) DEFAULT NULL,
  `sale_price` decimal(10,2) NOT NULL,
  `cost_price` decimal(10,2) NOT NULL,
  `unit` enum('kg','piece') NOT NULL DEFAULT 'piece',
  `stock` decimal(10,2) unsigned NOT NULL DEFAULT 0.00,
  `minimum_stock` decimal(10,2) unsigned NOT NULL DEFAULT 0.00,
  `origin` text DEFAULT NULL,
  `supplier` varchar(150) NOT NULL,
  `arrival_date` date DEFAULT NULL,
  `batch_photo_path` varchar(255) DEFAULT NULL,
  `inspection_photo_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_by_name` varchar(120) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_batches_sku` (`sku`),
  KEY `idx_batches_material_name` (`material_name`),
  KEY `idx_batches_grade` (`grade`),
  CONSTRAINT `chk_individual_pieces_unit` CHECK (`unit` = 'piece')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Customer information shared by email.
CREATE TABLE `clients` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `full_name` varchar(150) NOT NULL,
  `street_address` varchar(255) NOT NULL,
  `postal_code` varchar(24) NOT NULL,
  `city` varchar(100) NOT NULL,
  `email` varchar(254) NOT NULL,
  `phone` varchar(40) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_clients_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Order header, status and total.
CREATE TABLE `orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_number` varchar(32) NOT NULL,
  `client_id` bigint(20) unsigned NOT NULL,
  `status` enum('open','processing','shipped','delivered','cancelled') NOT NULL DEFAULT 'open',
  `payment_status` enum('unpaid','paid') NOT NULL DEFAULT 'unpaid',
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_by_name` varchar(120) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_orders_number` (`order_number`),
  KEY `idx_orders_client` (`client_id`),
  KEY `idx_orders_status` (`status`),
  CONSTRAINT `fk_orders_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Purchased material snapshots with exactly one inventory reference.
CREATE TABLE `order_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) unsigned NOT NULL,
  `batch_id` bigint(20) unsigned DEFAULT NULL,
  `individual_piece_id` bigint(20) unsigned DEFAULT NULL,
  `sku` varchar(64) DEFAULT NULL,
  `material_name` varchar(150) NOT NULL,
  `grade` varchar(24) NOT NULL,
  `color` varchar(80) DEFAULT NULL,
  `thickness` varchar(40) DEFAULT NULL,
  `quantity` decimal(10,2) unsigned NOT NULL,
  `unit` enum('kg','piece') NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `line_total` decimal(12,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_order_items_order` (`order_id`),
  KEY `idx_order_items_batch` (`batch_id`),
  KEY `idx_order_items_piece` (`individual_piece_id`),
  CONSTRAINT `fk_order_items_batch` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`),
  CONSTRAINT `fk_order_items_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_order_items_piece` FOREIGN KEY (`individual_piece_id`) REFERENCES `individual_pieces` (`id`),
  CONSTRAINT `chk_order_items_inventory_reference` CHECK (`batch_id` is not null and `individual_piece_id` is null or `batch_id` is null and `individual_piece_id` is not null)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-user alerts and structured change history.
CREATE TABLE `notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `recipient_user_id` bigint(20) unsigned NOT NULL,
  `type` enum('low_stock','account_activity','order_activity','inventory_activity') NOT NULL,
  `title` varchar(120) NOT NULL,
  `message` varchar(255) NOT NULL,
  `target_url` varchar(255) NOT NULL DEFAULT '?page=inventory',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `delivered_at` timestamp NULL DEFAULT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `actor_name` varchar(120) NOT NULL DEFAULT 'System',
  `event_action` enum('created','updated','deleted') NOT NULL DEFAULT 'updated',
  `details_json` longtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_notifications_unread` (`recipient_user_id`,`read_at`,`created_at`),
  KEY `idx_notifications_delivery` (`recipient_user_id`,`delivered_at`,`id`),
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`recipient_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hashed public links with expiration and revocation.
CREATE TABLE `share_links` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `token_hash` char(64) NOT NULL,
  `resource_type` enum('batch','piece','order') NOT NULL,
  `resource_id` bigint(20) unsigned NOT NULL,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_by_name` varchar(120) NOT NULL,
  `language` enum('nl','en') NOT NULL DEFAULT 'nl',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_share_links_token_hash` (`token_hash`),
  KEY `idx_share_links_resource` (`resource_type`,`resource_id`,`revoked_at`),
  KEY `fk_share_links_user` (`created_by_user_id`),
  CONSTRAINT `fk_share_links_user` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

