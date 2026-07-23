-- Система обміну та повернення товарів
-- MySQL 5.7+ / MariaDB 10.2+

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('admin','manager') NOT NULL DEFAULT 'manager',
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `last_login_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rma` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rma_number` VARCHAR(32) NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'new',

  -- замовлення
  `order_number` VARCHAR(64) NOT NULL,
  `order_id_sd` VARCHAR(64) NULL,
  `order_date` DATE NULL,
  `order_found` TINYINT(1) NOT NULL DEFAULT 0,
  `needs_manual_check` TINYINT(1) NOT NULL DEFAULT 0,

  -- клієнт
  `customer_name` VARCHAR(190) NULL,
  `phone` VARCHAR(20) NOT NULL,
  `email` VARCHAR(190) NULL,

  -- причина
  `reason_code` VARCHAR(40) NOT NULL,
  `reason_details` TEXT NULL,
  `desired_action` VARCHAR(40) NOT NULL,
  `exchange_wish` TEXT NULL,
  `customer_comment` TEXT NULL,

  -- підтвердження стану товару
  `confirm_not_installed` TINYINT(1) NOT NULL DEFAULT 0,
  `confirm_no_traces` TINYINT(1) NOT NULL DEFAULT 0,
  `confirm_packaging` TINYINT(1) NOT NULL DEFAULT 0,
  `confirm_understand` TINYINT(1) NOT NULL DEFAULT 0,
  `confirm_rules` TINYINT(1) NOT NULL DEFAULT 0,

  -- реквізити
  `refund_name` VARCHAR(190) NULL,
  `refund_iban` VARCHAR(34) NULL,
  `refund_tax_id` VARCHAR(20) NULL,
  `refund_bank` VARCHAR(190) NULL,
  `refund_comment` TEXT NULL,
  `refund_amount` DECIMAL(10,2) NULL,
  `refund_paid_at` DATETIME NULL,

  -- доставка
  `return_ttn` VARCHAR(40) NULL,
  `ttn_source` VARCHAR(20) NULL,
  `np_doc_ref` VARCHAR(64) NULL,
  `np_track_status` VARCHAR(160) NULL,
  `np_tracked_at` DATETIME NULL,
  `np_cost_alert` TINYINT(1) NOT NULL DEFAULT 0,
  `np_cost_note` VARCHAR(200) NULL,
  `np_original_ttn` VARCHAR(40) NULL,
  `light_return_reason` VARCHAR(160) NULL,
  `carrier` VARCHAR(40) NULL,
  `shipped_at` DATE NULL,
  `received_at` DATE NULL,
  `shipping_payer` VARCHAR(20) NULL,
  `shipping_comment` TEXT NULL,

  -- обробка
  `reject_reason` VARCHAR(60) NULL,
  `manager_id` INT UNSIGNED NULL,
  `client_message` TEXT NULL,
  `total_amount` DECIMAL(10,2) NULL,

  -- службове
  `source` VARCHAR(20) NOT NULL DEFAULT 'web',
  `ip` VARCHAR(45) NULL,
  `sd_synced` TINYINT(1) NOT NULL DEFAULT 0,
  `public_token` CHAR(32) NULL,
  `notified_stale_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rma_number` (`rma_number`),
  KEY `idx_rma_status` (`status`),
  KEY `idx_rma_order` (`order_number`),
  KEY `idx_rma_phone` (`phone`),
  KEY `idx_rma_ttn` (`return_ttn`),
  KEY `idx_rma_created` (`created_at`),
  KEY `idx_rma_manager` (`manager_id`),
  KEY `idx_rma_reason` (`reason_code`),
  KEY `idx_rma_action` (`desired_action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rma_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rma_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `sku` VARCHAR(64) NULL,
  `qty` INT NOT NULL DEFAULT 1,
  `price` DECIMAL(10,2) NULL,
  `url` VARCHAR(500) NULL,
  `supplier` VARCHAR(40) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_items_rma` (`rma_id`),
  KEY `idx_items_sku` (`sku`),
  KEY `idx_items_supplier` (`supplier`),
  CONSTRAINT `fk_items_rma` FOREIGN KEY (`rma_id`) REFERENCES `rma` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rma_photos` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rma_id` INT UNSIGNED NOT NULL,
  `type` VARCHAR(20) NOT NULL DEFAULT 'general',
  `file` VARCHAR(120) NOT NULL,
  `orig_name` VARCHAR(255) NULL,
  `size` INT UNSIGNED NULL,
  `uploaded_by` VARCHAR(20) NOT NULL DEFAULT 'client',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_photos_rma` (`rma_id`),
  CONSTRAINT `fk_photos_rma` FOREIGN KEY (`rma_id`) REFERENCES `rma` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rma_history` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rma_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NULL,
  `user_name` VARCHAR(100) NOT NULL DEFAULT 'Система',
  `field` VARCHAR(60) NOT NULL,
  `old_value` TEXT NULL,
  `new_value` TEXT NULL,
  `comment` TEXT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_history_rma` (`rma_id`),
  CONSTRAINT `fk_history_rma` FOREIGN KEY (`rma_id`) REFERENCES `rma` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rma_comments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rma_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NULL,
  `author` VARCHAR(100) NOT NULL,
  `type` ENUM('internal','client','system') NOT NULL DEFAULT 'internal',
  `text` TEXT NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_comments_rma` (`rma_id`),
  CONSTRAINT `fk_comments_rma` FOREIGN KEY (`rma_id`) REFERENCES `rma` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- кеш відповідей SalesDrive (ліміт API: 10 запитів/хв)
CREATE TABLE IF NOT EXISTS `sd_cache` (
  `cache_key` VARCHAR(64) NOT NULL,
  `payload` MEDIUMTEXT NOT NULL,
  `expires_at` DATETIME NOT NULL,
  PRIMARY KEY (`cache_key`),
  KEY `idx_cache_exp` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- лічильник запитів до SalesDrive (rate limit)
CREATE TABLE IF NOT EXISTS `sd_calls` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `called_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_calls_time` (`called_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
  `k` VARCHAR(64) NOT NULL,
  `v` TEXT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- лог сповіщень клієнту (email / sms / viber) + захист від дублів
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rma_id` INT UNSIGNED NOT NULL,
  `event` VARCHAR(40) NOT NULL,
  `channel` ENUM('email','sms') NOT NULL,
  `recipient` VARCHAR(190) NOT NULL,
  `status` ENUM('sent','failed','skipped') NOT NULL,
  `detail` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_notif_rma` (`rma_id`),
  KEY `idx_notif_dedupe` (`rma_id`, `event`, `channel`),
  CONSTRAINT `fk_notif_rma` FOREIGN KEY (`rma_id`) REFERENCES `rma` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
