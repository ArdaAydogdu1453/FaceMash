-- FaceMash Database Schema
-- Compatible with MySQL 5.7+, 8.x, and MariaDB 10.3+

CREATE DATABASE IF NOT EXISTS `facemash` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `facemash`;

-- Table structure for `photos`
CREATE TABLE IF NOT EXISTS `photos` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `filename` VARCHAR(255) NOT NULL,
  `wins` INT(11) NOT NULL DEFAULT 0,
  `losses` INT(11) NOT NULL DEFAULT 0,
  `rating` INT(11) NOT NULL DEFAULT 1000,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;