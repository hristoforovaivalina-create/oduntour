-- Одун Тур: база данных. Импортируйте через phpMyAdmin (OSPanel -> Модули -> phpMyAdmin)
-- CREATE DATABASE IF NOT EXISTS `odun_tur` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE `odun_tur`;

-- Заявки с сайта (вместо Firebase "leads")
CREATE TABLE IF NOT EXISTS `leads` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(150) NOT NULL,
  `phone`      VARCHAR(40)  NOT NULL,
  `comment`    TEXT NULL,
  `status`     ENUM('new','processed') NOT NULL DEFAULT 'new',
  `ip`         VARCHAR(45) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Администраторы (пароль хранится как bcrypt-хеш)
CREATE TABLE IF NOT EXISTS `admins` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `login`         VARCHAR(50)  NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_login` (`login`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Защита от подбора пароля
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip`         VARCHAR(45) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ip_time` (`ip`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Администратор: логин admin, пароль oduntour2026
INSERT INTO `admins` (`login`,`password_hash`) VALUES
('admin','$2y$10$NGgR60EJpHz2GtpyWeqFAe.VQFOKV13JYnkJ7UXwBzsbzWvrvrr2W')
ON DUPLICATE KEY UPDATE `password_hash`=VALUES(`password_hash`);
