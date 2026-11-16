/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.11.14-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: chefs_Db
-- ------------------------------------------------------
-- Server version	10.11.14-MariaDB-0ubuntu0.24.04.1

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `auth_sessions`
--

DROP TABLE IF EXISTS `auth_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `auth_sessions` (
  `session_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `token_hash` char(64) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  PRIMARY KEY (`session_id`),
  UNIQUE KEY `uq_sessions_token` (`token_hash`),
  KEY `ix_sessions_user` (`user_id`),
  KEY `ix_sessions_expires` (`expires_at`),
  CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `drinksl_ingredients`
--

DROP TABLE IF EXISTS `drinksl_ingredients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `drinksl_ingredients` (
  `drinks_id` int(10) unsigned NOT NULL,
  `position` tinyint(3) unsigned NOT NULL,
  `ingredient_id` int(10) unsigned NOT NULL,
  `measure` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`drinks_id`,`position`),
  KEY `idx_ci_ingredient` (`ingredient_id`),
  CONSTRAINT `drinksl_ingredients_ibfk_1` FOREIGN KEY (`drinks_id`) REFERENCES `spirit_sips` (`drinks_id`) ON DELETE CASCADE,
  CONSTRAINT `drinksl_ingredients_ibfk_2` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`ingredient_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ingredients`
--

DROP TABLE IF EXISTS `ingredients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ingredients` (
  `ingredient_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(128) NOT NULL,
  PRIMARY KEY (`ingredient_id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `log_levels`
--

DROP TABLE IF EXISTS `log_levels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `log_levels` (
  `level_id` tinyint(3) unsigned NOT NULL,
  `level_name` varchar(10) NOT NULL,
  PRIMARY KEY (`level_id`),
  UNIQUE KEY `uq_log_levels_name` (`level_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `log_sources`
--

DROP TABLE IF EXISTS `log_sources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `log_sources` (
  `source_id` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  `source_name` varchar(64) NOT NULL,
  `first_seen_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  PRIMARY KEY (`source_id`),
  UNIQUE KEY `uq_log_sources_name` (`source_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `spirit_sips`
--

DROP TABLE IF EXISTS `spirit_sips`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `spirit_sips` (
  `drinks_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `external_id` varchar(20) NOT NULL,
  `name` varchar(255) NOT NULL,
  `category` varchar(64) DEFAULT NULL,
  `alcoholic` varchar(32) DEFAULT NULL,
  `glass` varchar(64) DEFAULT NULL,
  `instructions` text DEFAULT NULL,
  `thumb_url` varchar(512) DEFAULT NULL,
  `imported_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  PRIMARY KEY (`drinks_id`),
  UNIQUE KEY `external_id` (`external_id`),
  KEY `idx_drinks_name` (`name`),
  KEY `idx_drinks_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sys_logs`
--

DROP TABLE IF EXISTS `sys_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_logs` (
  `log_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `event_uuid` char(36) NOT NULL,
  `level_id` tinyint(3) unsigned NOT NULL,
  `source_id` smallint(5) unsigned NOT NULL,
  `host` varchar(255) DEFAULT NULL,
  `message` text NOT NULL,
  `context` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`context`)),
  `correlation_id` varchar(64) DEFAULT NULL,
  `logged_at` datetime(3) NOT NULL,
  `ingested_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  PRIMARY KEY (`log_id`),
  UNIQUE KEY `uq_sys_logs_event` (`event_uuid`),
  KEY `ix_sys_logs_time` (`logged_at`),
  KEY `ix_sys_logs_level_time` (`level_id`,`logged_at`),
  KEY `ix_sys_logs_source_time` (`source_id`,`logged_at`),
  KEY `ix_sys_logs_corr` (`correlation_id`),
  CONSTRAINT `fk_sys_logs_level` FOREIGN KEY (`level_id`) REFERENCES `log_levels` (`level_id`),
  CONSTRAINT `fk_sys_logs_source` FOREIGN KEY (`source_id`) REFERENCES `log_sources` (`source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `user_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(254) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('user','chef','admin') NOT NULL DEFAULT 'user',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `failed_logins` smallint(5) unsigned NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-11-15 20:22:16
