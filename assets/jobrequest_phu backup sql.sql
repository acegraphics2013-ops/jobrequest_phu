-- --------------------------------------------------------
-- Host:                         127.0.0.1
-- Server version:               8.0.30 - MySQL Community Server - GPL
-- Server OS:                    Win64
-- HeidiSQL Version:             12.1.0.6537
-- --------------------------------------------------------

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8 */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;


-- Dumping database structure for hpu_job_request
CREATE DATABASE IF NOT EXISTS `hpu_job_request` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci */ /*!80016 DEFAULT ENCRYPTION='N' */;
USE `hpu_job_request`;

-- Dumping structure for table hpu_job_request.departments
CREATE TABLE IF NOT EXISTS `departments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `department_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `department_name` (`department_name`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table hpu_job_request.departments: ~9 rows (approximately)
INSERT INTO `departments` (`id`, `department_name`, `created_at`) VALUES
	(1, 'Administrative Office', '2026-07-10 21:16:45'),
	(2, 'Accounting', '2026-07-10 21:16:45'),
	(3, 'Human Resource', '2026-07-10 21:16:45'),
	(4, 'Health Promotion Unit', '2026-07-10 21:16:45'),
	(5, 'Information Technology', '2026-07-10 21:16:45'),
	(6, 'Medical Service', '2026-07-10 21:16:45'),
	(7, 'Nursing Service', '2026-07-10 21:16:45'),
	(8, 'Public Health Unit', '2026-07-10 21:33:49'),
	(9, 'Procurement Unit', '2026-07-10 21:43:07');

-- Dumping structure for table hpu_job_request.job_requests
CREATE TABLE IF NOT EXISTS `job_requests` (
  `id` int NOT NULL AUTO_INCREMENT,
  `job_no` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `requestor` int NOT NULL,
  `requestor_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `department` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date_request` date NOT NULL,
  `date_completion` date DEFAULT NULL,
  `attachment` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `request_types` longtext COLLATE utf8mb4_unicode_ci,
  `project_title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `purpose_objective` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_datetime` datetime DEFAULT NULL,
  `event_not_applicable` tinyint(1) NOT NULL DEFAULT '0',
  `venue_location` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `venue_not_applicable` tinyint(1) NOT NULL DEFAULT '0',
  `send_to` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `send_to_not_applicable` tinyint(1) NOT NULL DEFAULT '0',
  `graphic_design_types` longtext COLLATE utf8mb4_unicode_ci,
  `graphic_design_other` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `graphic_colors` longtext COLLATE utf8mb4_unicode_ci,
  `publication_methods` longtext COLLATE utf8mb4_unicode_ci,
  `publication_other` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `graphic_quantity` int NOT NULL DEFAULT '0',
  `video_types` longtext COLLATE utf8mb4_unicode_ci,
  `video_type_other` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `video_formats` longtext COLLATE utf8mb4_unicode_ci,
  `video_format_other` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `video_quantity` int NOT NULL DEFAULT '0',
  `photographers_needed` int NOT NULL DEFAULT '0',
  `specific_shots` text COLLATE utf8mb4_unicode_ci,
  `coverage_formats` longtext COLLATE utf8mb4_unicode_ci,
  `coverage_format_other` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `coverage_quantity` int NOT NULL DEFAULT '0',
  `request_details` text COLLATE utf8mb4_unicode_ci,
  `requestor_signature_date` date DEFAULT NULL,
  `reviewed_by` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reviewed_date` date DEFAULT NULL,
  `disapproved` tinyint(1) NOT NULL DEFAULT '0',
  `disapproved_reason` text COLLATE utf8mb4_unicode_ci,
  `approved_by` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `approved_date` date DEFAULT NULL,
  `initial_draft_date` date DEFAULT NULL,
  `final_approval_date` date DEFAULT NULL,
  `revision_required` tinyint(1) DEFAULT NULL,
  `revision_count` int NOT NULL DEFAULT '0',
  `distribution_mode` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `final_design_drive_link` text COLLATE utf8mb4_unicode_ci,
  `delivered_on_time` tinyint(1) DEFAULT NULL,
  `objectives_met` tinyint(1) DEFAULT NULL,
  `challenges_encountered` tinyint(1) DEFAULT NULL,
  `challenges_details` text COLLATE utf8mb4_unicode_ci,
  `improvement_suggestions` text COLLATE utf8mb4_unicode_ci,
  `satisfaction_rating` tinyint DEFAULT NULL,
  `approval_status` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pending Review',
  `action_log` longtext COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `archived` tinyint(1) NOT NULL DEFAULT '0',
  `archived_at` datetime DEFAULT NULL,
  `archived_by` int DEFAULT NULL,
  `public_token` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `assigned_to` int DEFAULT NULL,
  `assigned_at` datetime DEFAULT NULL,
  `assignment_notes` text COLLATE utf8mb4_unicode_ci,
  `priority` enum('Low','Normal','High','Urgent') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Normal',
  PRIMARY KEY (`id`),
  UNIQUE KEY `job_no` (`job_no`),
  UNIQUE KEY `public_token` (`public_token`),
  KEY `idx_requestor` (`requestor`),
  KEY `idx_status` (`approval_status`),
  CONSTRAINT `fk_job_request_user` FOREIGN KEY (`requestor`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table hpu_job_request.job_requests: ~14 rows (approximately)
INSERT INTO `job_requests` (`id`, `job_no`, `requestor`, `requestor_name`, `department`, `contact`, `date_request`, `date_completion`, `attachment`, `request_types`, `project_title`, `purpose_objective`, `event_datetime`, `event_not_applicable`, `venue_location`, `venue_not_applicable`, `send_to`, `send_to_not_applicable`, `graphic_design_types`, `graphic_design_other`, `graphic_colors`, `publication_methods`, `publication_other`, `graphic_quantity`, `video_types`, `video_type_other`, `video_formats`, `video_format_other`, `video_quantity`, `photographers_needed`, `specific_shots`, `coverage_formats`, `coverage_format_other`, `coverage_quantity`, `request_details`, `requestor_signature_date`, `reviewed_by`, `reviewed_date`, `disapproved`, `disapproved_reason`, `approved_by`, `approved_date`, `initial_draft_date`, `final_approval_date`, `revision_required`, `revision_count`, `distribution_mode`, `final_design_drive_link`, `delivered_on_time`, `objectives_met`, `challenges_encountered`, `challenges_details`, `improvement_suggestions`, `satisfaction_rating`, `approval_status`, `action_log`, `created_at`, `updated_at`, `archived`, `archived_at`, `archived_by`, `public_token`, `assigned_to`, `assigned_at`, `assignment_notes`, `priority`) VALUES
	(2, 'HPU-20260711-0002', 12, 'BELL MANDREW L. MANLIGUEZ, RN', 'Public Health Unit', '09510936558', '2026-07-11', NULL, '', '["Graphic Design"]', 'gag', 'gaga', NULL, 1, NULL, 1, NULL, 1, '["Digital Ad"]', '', '["Full Color"]', '["Digital File"]', '', 1, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, 0, '0', '2026-07-11', 'ACE R. CABUGA', '2026-07-11', 0, '', NULL, NULL, '2026-07-12', '2026-07-11', 1, 2, 'N/A', NULL, 1, 1, 0, '', 'N/A', 5, 'Closed', 'Created by BELL MANDREW L. MANLIGUEZ, RN on 2026-07-11 06:21:44\nReviewed by ACE R. CABUGA on 2026-07-11 06:22:36. Decision: Approved.\nSupervisor decision saved by BELL MANDREW L. MANLIGUEZ, RN on 2026-07-11 07:17:56. Decision: Approved.\nSupervisor decision saved by BELL MANDREW L. MANLIGUEZ, RN on 2026-07-11 23:19:39. Decision: Approved.\nSupervisor decision saved by BELL MANDREW L. MANLIGUEZ, RN on 2026-07-11 23:20:15. Decision: Completed.\nEvaluation requested by BELL MANDREW L. MANLIGUEZ, RN on 2026-07-11 23:20:38.\nEvaluation submitted on 2026-07-12 03:26:30.', '2026-07-11 03:21:44', '2026-07-11 19:26:30', 0, NULL, NULL, NULL, NULL, NULL, NULL, 'Normal'),
	(3, 'HPU-20260711-0003', 11, 'Judy Ann Ruaya', 'Procurement Unit', '09510936558', '2026-07-11', NULL, '', '["Graphic Design"]', 'werwer', 'werwrw', '2026-07-11 08:01:00', 0, NULL, 1, NULL, 1, '["Digital Ad"]', '', '["Full Color"]', '["Photo Paper"]', '', 1, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, 0, '0', '2026-07-11', 'JEA A. DAYO', '2026-07-11', 0, '', NULL, NULL, '2026-07-11', NULL, NULL, 0, 'N/A', NULL, 1, 1, 0, '', 'N/A', 5, 'Closed', 'Created by Judy Ann Ruaya on 2026-07-11 06:45:39\nReviewed by JEA A. DAYO on 2026-07-11 07:12:05.\nDevelopment details updated by ACE RAZ CABUGA on 2026-07-11 07:12:24.\nReviewed by JEA A. DAYO on 2026-07-11 07:15:08.\nSupervisor decision saved by BELL MANDREW L. MANLIGUEZ, RN on 2026-07-11 07:15:29. Decision: Approved.\nSupervisor decision saved by BELL MANDREW L. MANLIGUEZ, RN on 2026-07-11 07:15:38. Decision: Completed.\nEvaluation submitted on 2026-07-11 07:20:12.', '2026-07-11 03:45:39', '2026-07-10 23:20:12', 0, NULL, NULL, NULL, NULL, NULL, NULL, 'Normal'),
	(4, 'HPU-20260711-0004', 11, 'Judy Ann Ruaya', 'Procurement Unit', '09510936558', '2026-07-11', NULL, '', '["Graphic Design","Video Production"]', 'weqwqw', 'qrqrq', '2026-07-11 09:30:00', 0, NULL, 1, NULL, 1, '["Digital Ad"]', '', '["Full Color"]', '["Digital File"]', '', 2, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, 0, '0', '2026-07-11', 'GREG Y. MASANGKAY', '2026-07-11', 1, 'trip lang', NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Disapproved', 'Created by Judy Ann Ruaya on 2026-07-11 06:47:08\nReviewed by GREG Y. MASANGKAY on 2026-07-11 07:11:58.\nSupervisor decision saved by BELL MANDREW L. MANLIGUEZ, RN on 2026-07-11 07:14:30. Decision: Disapproved.', '2026-07-11 03:47:08', '2026-07-10 23:14:30', 0, NULL, NULL, NULL, NULL, NULL, NULL, 'Normal'),
	(6, 'HPU-20260711-0006', 11, 'Judy Ann Ruaya', 'Procurement Unit', '09510936558', '2026-07-11', '2026-07-12', '', '["IEC Materials"]', 'ERGONOMICS', 'AGAG', '2026-07-11 14:02:00', 0, 'DAAWIS', 0, 'EMAILE..', 0, NULL, '', NULL, NULL, '', 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, 0, '0', '2026-07-11', 'ACE R. CABUGA', '2026-07-11', 0, '', NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 1, 1, 0, '', 'n/a', 5, 'Closed', 'Created by Judy Ann Ruaya on 2026-07-11 07:56:15\nReviewed by ACE R. CABUGA on 2026-07-11 07:57:55.\nSupervisor decision saved by BELL MANDREW L. MANLIGUEZ, RN on 2026-07-11 07:58:18. Decision: Approved.\nSupervisor decision saved by BELL MANDREW L. MANLIGUEZ, RN on 2026-07-11 08:06:56. Decision: Completed.\nEvaluation requested by BELL MANDREW L. MANLIGUEZ, RN on 2026-07-11 08:07:04.\nEvaluation submitted on 2026-07-11 08:09:19.', '2026-07-11 04:56:15', '2026-07-11 00:09:19', 0, NULL, NULL, NULL, NULL, NULL, NULL, 'Normal'),
	(7, 'HPU-20260711-0007', 11, 'Judy Ann Ruaya', 'Procurement Unit', '09510936558', '2026-07-11', NULL, '20260711_080215_02f265cefe_724152897_2477859099327012_8906830022231090422_n.jpg', '["Graphic Design","Video Production","Photo/Video Coverage","IEC Materials"]', 'logo design', 'offce logo', '2026-07-15 08:30:00', 0, NULL, 1, NULL, 1, '["Digital Ad"]', '', '["Full Color"]', '["Digital File"]', '', 1, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, 0, '0', '2026-07-11', 'JEA A. DAYO', '2026-07-11', 0, '', NULL, NULL, '2026-07-11', '2026-07-11', 0, 0, 'N/A', NULL, 1, 1, 1, 'galing', 'none', 5, 'Closed', 'Created by Judy Ann Ruaya on 2026-07-11 08:02:15\nReviewed by JEA A. DAYO on 2026-07-11 08:03:15.\nSupervisor decision saved by BELL MANDREW L. MANLIGUEZ, RN on 2026-07-11 08:04:23. Decision: Approved.\nSupervisor decision saved by BELL MANDREW L. MANLIGUEZ, RN on 2026-07-11 08:04:55. Decision: Completed.\nEvaluation requested by ACE RAZ CABUGA on 2026-07-11 08:05:49.\nEvaluation submitted on 2026-07-11 08:06:40.\nDevelopment details updated by BELL MANDREW L. MANLIGUEZ, RN on 2026-07-11 08:24:13.\nDevelopment details updated by BELL MANDREW L. MANLIGUEZ, RN on 2026-07-11 19:37:01.\nDevelopment details updated by BELL MANDREW L. MANLIGUEZ, RN on 2026-07-11 19:37:53.\nDevelopment details updated by BELL MANDREW L. MANLIGUEZ, RN on 2026-07-11 19:37:57.', '2026-07-11 05:02:15', '2026-07-11 11:37:57', 0, NULL, NULL, NULL, NULL, NULL, NULL, 'Normal'),
	(8, 'HPU-20260711-0008', 11, 'Judy Ann Ruaya', 'Procurement Unit', '1', '2026-07-11', '2026-07-15', '', '["Graphic Design"]', 'OFFICE SIGNAGE', 'SAWADEKA', '2026-07-11 12:30:00', 0, 'OPD', 0, 'OPD@GMAIL.COM', 0, '["Digital Ad","Print Ad / Tarpaulin","Brochure","Poster / Flyer","Invitation","Forms / Cards","Logo Design","Others"]', 'teset design', '["Full Color","Single Color","Grayscale"]', '["Digital File","Tarpaulin","Photo Paper","Others"]', 'test method', 2, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, 0, '0', '2026-07-11', 'GREG Y. MASANGKAY', '2026-07-11', 0, '', NULL, NULL, '2026-07-11', '2026-07-12', 1, 3, 'TAPR', NULL, 1, 1, 1, 'aagag', 'gagaga', 5, 'Closed', 'Created by Judy Ann Ruaya on 2026-07-11 22:36:32\nReviewed by GREG Y. MASANGKAY on 2026-07-11 22:39:24.\nDevelopment details updated by ACE RAZ CABUGA on 2026-07-11 22:39:58.\nDevelopment details updated by ACE RAZ CABUGA on 2026-07-11 22:40:12.\nDevelopment details updated by ACE RAZ CABUGA on 2026-07-11 22:40:15.\nDevelopment details updated by ACE RAZ CABUGA on 2026-07-11 22:40:28.\nSupervisor decision saved by System Administrator on 2026-07-11 22:51:05. Decision: Approved.\nSupervisor decision saved by System Administrator on 2026-07-11 22:53:44. Decision: Completed.\nEvaluation submitted on 2026-07-11 22:54:24.\nDevelopment details updated by System Administrator on 2026-07-11 22:55:11.', '2026-07-11 19:36:32', '2026-07-11 14:55:11', 0, NULL, NULL, NULL, NULL, NULL, NULL, 'Normal'),
	(9, 'HPU-20260711-0009', 11, 'Judy Ann Ruaya', 'Procurement Unit', '09510936558', '2026-07-11', NULL, '', '["Video Production"]', 'OFFICE EVENT', 'TRIP LANG', NULL, 1, NULL, 1, NULL, 1, NULL, '', NULL, NULL, '', 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, 0, '0', '2026-07-11', 'ACE R. CABUGA', '2026-07-11', 0, '', NULL, NULL, NULL, NULL, NULL, 0, 'N/A', NULL, 1, 1, 0, '', 'N/A', 1, 'Closed', 'Created by Judy Ann Ruaya on 2026-07-11 22:42:01\nReviewed by ACE R. CABUGA on 2026-07-11 22:42:43.\nDevelopment details updated by BELL MANDREW L. MANLIGUEZ, RN on 2026-07-11 22:56:24.\nSupervisor decision saved by BELL MANDREW L. MANLIGUEZ, RN on 2026-07-11 22:56:34. Decision: Approved.\nSupervisor decision saved by BELL MANDREW L. MANLIGUEZ, RN on 2026-07-11 22:56:49. Decision: Completed.\nEvaluation requested by BELL MANDREW L. MANLIGUEZ, RN on 2026-07-11 22:57:12.\nEvaluation submitted on 2026-07-11 23:02:48.\nDevelopment details updated by BELL MANDREW L. MANLIGUEZ, RN on 2026-07-11 23:03:15.', '2026-07-11 19:42:01', '2026-07-11 15:03:15', 0, NULL, NULL, NULL, NULL, NULL, NULL, 'Normal'),
	(14, 'HPU-20260712-0001', 10, 'ACE RAZ CABUGA', 'Public Health Unit', '1', '2026-07-12', NULL, '', '["Graphic Design"]', '1', '1', NULL, 1, NULL, 1, NULL, 1, '["Digital Ad"]', '', '["Full Color"]', '["Digital File"]', '', 1, NULL, '', NULL, '', 0, 0, '', NULL, '', 0, '1', '2026-07-12', 'GREG Y. MASANGKAY', '2026-07-12', 0, '', NULL, NULL, '2026-07-01', NULL, NULL, 0, 'N/A', NULL, 0, 0, 0, '', '', 5, 'Closed', 'Created by ACE RAZ CABUGA on 2026-07-12 11:35:35\nReviewed by GREG Y. MASANGKAY on 2026-07-12 11:36:35.\nEvaluation submitted on 2026-07-12 11:38:27.', '2026-07-12 08:35:35', '2026-07-12 03:38:27', 0, NULL, NULL, NULL, NULL, NULL, NULL, 'Normal'),
	(15, 'HPU-20260712-0002', 12, 'BELL MANDREW L. MANLIGUEZ, RN', 'Public Health Unit', '1', '2026-07-12', '2026-07-15', '', '["Graphic Design","Photo/Video Coverage","IEC Materials"]', '1', '1', NULL, 1, NULL, 1, NULL, 1, '["Digital Ad","Print Ad / Tarpaulin","Brochure","Poster / Flyer","Invitation","Forms / Cards","Logo Design","Others"]', '2', '["Full Color","Single Color","Grayscale"]', '["Digital File","Tarpaulin","Photo Paper","Others"]', '2', 2, NULL, '', NULL, '', 0, 3, '3', '["JPEG","PNG","RAW","MP4","MKV","Others"]', '3', 3, '5', '2026-07-12', 'ACE R. CABUGA', '2026-07-12', 0, '', NULL, NULL, '2026-07-12', '2026-07-13', 0, 0, 'N/A', NULL, 1, 1, 1, 'D', 'D', 1, 'Closed', 'Created by BELL MANDREW L. MANLIGUEZ, RN on 2026-07-12 11:50:54\nReviewed by ACE R. CABUGA on 2026-07-12 11:51:25.\nReviewed by ACE R. CABUGA on 2026-07-12 11:51:31.\nEvaluation submitted on 2026-07-12 12:34:32.', '2026-07-12 08:50:54', '2026-07-12 04:34:32', 0, NULL, NULL, NULL, NULL, NULL, NULL, 'Normal'),
	(16, 'HPU-20260712-0003', 10, 'ACE RAZ CABUGA', 'Accounting', '09510936558 / acecabuga@gmail.com', '2026-07-12', '2026-07-15', '20260712_120541_d7f6801bb4_HPU-20260711-0010_QR.png', '["Graphic Design","Video Production","Photo/Video Coverage","IEC Materials"]', '0', '0', NULL, 1, NULL, 1, NULL, 1, '["Digital Ad","Print Ad / Tarpaulin","Brochure","Poster / Flyer","Invitation","Forms / Cards","Logo Design","Others"]', '2', '["Full Color","Single Color","Grayscale"]', '["Digital File","Tarpaulin","Photo Paper","Others"]', '2', 2, '["Digital Video Ads","Music Video","Promotional","Documentary","Instructional","Informative","DOH Videos","Others"]', '3', '["MP4","MP3 (Audio Only)","Others"]', '3', 3, 4, '4', '["JPEG","PNG","RAW","MP4","MKV","Others"]', '4', 4, '6', '2026-07-12', 'ACE R. CABUGA', '2026-07-12', 0, '', NULL, NULL, '2026-07-13', '2026-07-14', 0, 0, 'N/A', NULL, 1, 1, 0, '', '', 2, 'Closed', 'Created by ACE RAZ CABUGA on 2026-07-12 12:05:41\nReviewed by ACE R. CABUGA on 2026-07-12 12:06:16.\nEvaluation requested by ACE RAZ CABUGA on 2026-07-12 12:08:37.\nEvaluation submitted on 2026-07-12 12:09:02.', '2026-07-12 09:05:41', '2026-07-12 04:09:02', 0, NULL, NULL, NULL, NULL, NULL, NULL, 'Normal'),
	(17, 'HPU-20260712-0004', 12, 'BELL MANDREW L. MANLIGUEZ, RN', 'Accounting', '1', '2026-07-12', NULL, '', '["Video Production"]', '1', '1', NULL, 1, NULL, 1, NULL, 1, NULL, '', NULL, NULL, '', 0, '["Digital Video Ads","Others"]', '7', '["Others"]', '7', 7, 0, '', NULL, '', 0, '', '2026-07-12', 'JASMIN B. CAONG', '2026-07-12', 0, '', NULL, NULL, '2026-07-12', '2026-07-14', 0, 0, 'N/A', 'https://drive.google.com/drive/folders/1KO1GdHWc0t1kMuavozUEJ45t_2dVoiiO?usp=sharing', 1, 1, 0, '', '', 5, 'Closed', 'Created by BELL MANDREW L. MANLIGUEZ, RN on 2026-07-12 12:35:33\nReviewed by JASMIN B. CAONG on 2026-07-12 12:37:46.\nEvaluation requested by ACE RAZ CABUGA on 2026-07-12 14:17:51.\nEvaluation submitted on 2026-07-12 14:29:08.', '2026-07-12 09:35:33', '2026-07-12 06:29:08', 0, NULL, NULL, NULL, NULL, NULL, NULL, 'Normal'),
	(18, 'HPU-20260712-0005', 12, 'BELL MANDREW L. MANLIGUEZ, RN', 'Administrative Office', '1', '2026-07-12', NULL, '', '["Photo/Video Coverage"]', 'docu', 'wla lang', NULL, 1, NULL, 1, NULL, 1, NULL, '', NULL, NULL, '', 0, NULL, '', NULL, '', 0, 500, 'picture the artist', '["MKV","Others"]', 'psd', 2, 'none', '2026-07-12', NULL, NULL, 0, NULL, NULL, NULL, '2026-07-13', '2026-07-14', 1, 1, 'N/A', 'https://drive.google.com/file/d/1JD9Zt8n8j_YpYW4l5pu9vpqOoIgXxHzl/view?usp=sharing', NULL, NULL, NULL, NULL, NULL, NULL, 'Closed', 'Created by BELL MANDREW L. MANLIGUEZ, RN on 2026-07-12 14:30:47', '2026-07-12 11:30:47', '2026-07-13 00:05:15', 0, NULL, NULL, NULL, NULL, NULL, NULL, 'Normal'),
	(19, 'HPU-20260712-0006', 12, 'ROBERT LOYOLA', 'Administrative Office', '1', '2026-07-12', NULL, '', '["Graphic Design","Video Production"]', '1', '11', NULL, 1, NULL, 1, NULL, 1, '["Digital Ad","Print Ad / Tarpaulin","Brochure","Poster / Flyer","Invitation","Forms / Cards","Logo Design","Others"]', '2', '["Full Color","Single Color","Grayscale"]', '["Digital File","Tarpaulin","Photo Paper","Others"]', '22', 222, '["Digital Video Ads","Music Video","Promotional","Documentary","Instructional","Informative","DOH Videos","Others"]', '3', '["MP4","MP3 (Audio Only)","Others"]', '33', 333, 0, '', NULL, '', 0, '4', '2026-07-12', 'ACE R. CABUGA', '2026-07-12', 0, '', NULL, NULL, '2026-07-12', '2026-07-15', 1, 1, 'N/A', 'https://drive.google.com/file/d/1JD9Zt8n8j_YpYW4l5pu9vpqOoIgXxHzl/view?usp=sharing', 1, 1, 0, '', '', 5, 'Closed', 'Created by ROBERT LOYOLA on 2026-07-12 15:26:47\nReviewed by ACE R. CABUGA on 2026-07-12 20:20:12.\nEvaluation requested by BELL MANDREW L. MANLIGUEZ, RN on 2026-07-12 21:10:29.\nEvaluation submitted on 2026-07-12 21:11:19.', '2026-07-12 12:26:47', '2026-07-12 13:13:34', 0, NULL, NULL, '57d10317b670bef9c5ee1e2dc0b7ffc01bcd5052c7283a9cd4f150124a73dc5c', NULL, NULL, NULL, 'Normal'),
	(20, 'HPU-20260713-0001', 14, 'Greg Y. Masangkay', 'Human Resource', 'masangkay.greg12@gmail.com', '2026-07-13', '2026-07-17', '20260713_081406_c58f380514_HPU-20260712-0006_QR.png', '["Graphic Design"]', 'FLyer design', 'For dissemination of health event pamphlets', NULL, 1, NULL, 1, NULL, 1, '["Poster / Flyer"]', '', '["Full Color"]', '["Digital File","Others"]', 'Bondpaper A4', 50, NULL, '', NULL, '', 0, 0, '', NULL, '', 0, '', '2026-07-13', 'ACE R. CABUGA', '2026-07-13', 0, '', NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Completed', 'Created by Greg Y. Masangkay on 2026-07-13 08:14:06\nReviewed by ACE R. CABUGA on 2026-07-13 08:15:46.', '2026-07-13 05:14:06', '2026-07-13 00:24:17', 0, NULL, NULL, NULL, NULL, NULL, NULL, 'Normal');

-- Dumping structure for table hpu_job_request.job_request_iec_items
CREATE TABLE IF NOT EXISTS `job_request_iec_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `request_id` int NOT NULL,
  `material_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` int NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_iec_request` (`request_id`),
  CONSTRAINT `fk_iec_request` FOREIGN KEY (`request_id`) REFERENCES `job_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=45 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table hpu_job_request.job_request_iec_items: ~28 rows (approximately)
INSERT INTO `job_request_iec_items` (`id`, `request_id`, `material_name`, `quantity`, `created_at`) VALUES
	(17, 15, '5S Dengue', 1, '2026-07-12 03:50:54'),
	(18, 15, 'ASTMMC Services', 1, '2026-07-12 03:50:54'),
	(19, 15, 'Breastfeeding', 1, '2026-07-12 03:50:54'),
	(20, 15, 'Diphtheria', 1, '2026-07-12 03:50:54'),
	(21, 15, 'DOH Materials', 1, '2026-07-12 03:50:54'),
	(22, 15, 'Earthquake', 1, '2026-07-12 03:50:54'),
	(23, 15, 'Handwashing', 1, '2026-07-12 03:50:54'),
	(24, 15, 'HIV / AIDS', 1, '2026-07-12 03:50:54'),
	(25, 15, 'Leptospirosis', 1, '2026-07-12 03:50:54'),
	(26, 15, 'Mental Health', 1, '2026-07-12 03:50:54'),
	(27, 15, 'MPOX', 1, '2026-07-12 03:50:54'),
	(28, 15, 'Patient Rights', 1, '2026-07-12 03:50:54'),
	(29, 15, 'Patient and Watcher Handbook', 1, '2026-07-12 03:50:54'),
	(30, 15, '4', 1, '2026-07-12 03:50:54'),
	(31, 16, '5S Dengue', 1, '2026-07-12 04:05:41'),
	(32, 16, 'ASTMMC Services', 1, '2026-07-12 04:05:41'),
	(33, 16, 'Breastfeeding', 1, '2026-07-12 04:05:41'),
	(34, 16, 'Diphtheria', 1, '2026-07-12 04:05:41'),
	(35, 16, 'DOH Materials', 1, '2026-07-12 04:05:41'),
	(36, 16, 'Earthquake', 1, '2026-07-12 04:05:41'),
	(37, 16, 'Handwashing', 1, '2026-07-12 04:05:41'),
	(38, 16, 'HIV / AIDS', 1, '2026-07-12 04:05:41'),
	(39, 16, 'Leptospirosis', 1, '2026-07-12 04:05:41'),
	(40, 16, 'Mental Health', 1, '2026-07-12 04:05:41'),
	(41, 16, 'MPOX', 1, '2026-07-12 04:05:41'),
	(42, 16, 'Patient Rights', 1, '2026-07-12 04:05:41'),
	(43, 16, 'Patient and Watcher Handbook', 1, '2026-07-12 04:05:41'),
	(44, 16, '5', 1, '2026-07-12 04:05:41');

-- Dumping structure for table hpu_job_request.notifications
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `request_id` int DEFAULT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `notification_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'info',
  `action_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notification_user` (`user_id`),
  KEY `idx_notification_request` (`request_id`),
  KEY `idx_notification_read` (`is_read`),
  CONSTRAINT `fk_notification_request` FOREIGN KEY (`request_id`) REFERENCES `job_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notification_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table hpu_job_request.notifications: ~12 rows (approximately)
INSERT INTO `notifications` (`id`, `user_id`, `request_id`, `title`, `message`, `notification_type`, `action_url`, `is_read`, `created_at`) VALUES
	(1, 11, 7, 'Post Project Evaluation Required', 'Your job request HPU-20260711-0007 has been completed. Please complete the Post Project Evaluation.', 'evaluation', 'http://localhost/HPU-Job-Request-System/user/evaluation.php?id=7', 1, '2026-07-11 00:05:49'),
	(2, 11, 6, 'Post Project Evaluation Required', 'Your job request HPU-20260711-0006 has been completed. Please complete the Post Project Evaluation.', 'evaluation', 'http://localhost/HPU-Job-Request-System/user/evaluation.php?id=6', 1, '2026-07-11 00:07:04'),
	(3, 11, 6, 'Post Project Evaluation Required', 'palihog update', 'evaluation', 'http://localhost/HPU-Job-Request-System/user/evaluation.php?id=6', 1, '2026-07-11 00:07:29'),
	(4, 11, 6, 'Post Project Evaluation Required', 'humana????', 'evaluation', 'http://localhost/HPU-Job-Request-System/user/evaluation.php?id=6', 1, '2026-07-11 00:08:43'),
	(5, 11, 9, 'Post Project Evaluation Required', 'Your job request HPU-20260711-0009 has been completed. Please complete the Post Project Evaluation.', 'evaluation', 'http://localhost/HPU-Job-Request-System/user/evaluation.php?id=9', 1, '2026-07-11 14:57:12'),
	(6, 12, 2, 'Post Project Evaluation Required', 'Your job request HPU-20260711-0002 has been completed. Please complete the Post Project Evaluation.', 'evaluation', 'http://localhost/HPU-Job-Request-System/user/evaluation.php?id=2', 1, '2026-07-11 15:20:38'),
	(7, 12, 2, 'Post Project Evaluation Required', 'Your job request HPU-20260711-0002 has been completed. Please complete the Post Project Evaluation.', 'evaluation', 'http://localhost/HPU-Job-Request-System/user/evaluation.php?id=2', 1, '2026-07-11 15:21:33'),
	(12, 10, 16, 'Post Project Evaluation Required', 'Your job request HPU-20260712-0003 has been completed. Please complete the Post Project Evaluation.', 'evaluation', 'http://192.168.11.148/HPU-Job-Request-System/user/evaluation.php?id=16', 1, '2026-07-12 04:08:37'),
	(13, 12, 17, 'Post Project Evaluation Required', 'Your job request HPU-20260712-0004 has been completed. Please complete the Post Project Evaluation.', 'evaluation', 'http://192.168.11.148/HPU-Job-Request-System/user/evaluation.php?id=17', 0, '2026-07-12 06:17:51'),
	(14, 12, 17, 'Post Project Evaluation Required', 'Your job request HPU-20260712-0004 has been completed. Please complete the Post Project Evaluation.', 'evaluation', 'http://192.168.11.148/HPU-Job-Request-System/user/evaluation.php?id=17', 1, '2026-07-12 06:18:53'),
	(15, 12, 19, 'Post Project Evaluation Required', 'Your job request HPU-20260712-0006 has been completed. Please complete the Post Project Evaluation.', 'evaluation', 'http://192.168.11.148/jobrequest_phu/user/evaluation.php?id=19', 0, '2026-07-12 13:10:29'),
	(16, 12, 19, 'Post Project Evaluation Required', 'HUYYYY', 'evaluation', 'http://192.168.11.148/jobrequest_phu/user/evaluation.php?id=19', 1, '2026-07-12 13:10:57');

-- Dumping structure for table hpu_job_request.request_activity_logs
CREATE TABLE IF NOT EXISTS `request_activity_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `request_id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `action_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `action_title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `old_status` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `new_status` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `metadata_json` longtext COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_activity_request` (`request_id`),
  KEY `idx_activity_user` (`user_id`),
  CONSTRAINT `fk_activity_request` FOREIGN KEY (`request_id`) REFERENCES `job_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_activity_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=79 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table hpu_job_request.request_activity_logs: ~54 rows (approximately)
INSERT INTO `request_activity_logs` (`id`, `request_id`, `user_id`, `action_type`, `action_title`, `description`, `old_status`, `new_status`, `metadata_json`, `created_at`) VALUES
	(25, 14, 10, 'created', 'Request Submitted', 'The job request was created and submitted for review.', NULL, 'Pending Review', '{"job_no":"HPU-20260712-0001","project_title":"1"}', '2026-07-12 03:35:35'),
	(26, 14, 10, 'reviewed', 'Staff Review Saved', 'The request was reviewed by GREG Y. MASANGKAY.', 'Pending Review', 'Under Review', '{"reviewed_by":"GREG Y. MASANGKAY","reviewed_date":"2026-07-12"}', '2026-07-12 03:36:35'),
	(27, 14, 12, 'completed', 'Request Completed', 'The requested output was marked as completed.', 'Under Review', 'Completed', '{"approved_by":null,"approved_date":null,"reason":""}', '2026-07-12 03:37:25'),
	(28, 14, 12, 'development', 'Development Details Updated', 'Initial draft, revision and distribution details were updated.', NULL, NULL, '{"initial_draft_date":"2026-07-01","final_approval_date":null,"revision_required":null,"revision_count":0,"distribution_mode":"N/A"}', '2026-07-12 03:37:34'),
	(29, 15, 12, 'created', 'Request Submitted', 'The job request was created and submitted for review.', NULL, 'Pending Review', '{"job_no":"HPU-20260712-0002","project_title":"1"}', '2026-07-12 03:50:54'),
	(30, 15, 10, 'reviewed', 'Staff Review Saved', 'The request was reviewed by ACE R. CABUGA.', 'Pending Review', 'Under Review', '{"reviewed_by":"ACE R. CABUGA","reviewed_date":"2026-07-12"}', '2026-07-12 03:51:25'),
	(31, 15, 10, 'reviewed', 'Staff Review Saved', 'The request was reviewed by ACE R. CABUGA.', 'Under Review', 'Under Review', '{"reviewed_by":"ACE R. CABUGA","reviewed_date":"2026-07-12"}', '2026-07-12 03:51:31'),
	(32, 15, 12, 'approved', 'Request Approved', 'The supervisor approved the job request.', 'Under Review', 'Approved', '{"approved_by":"BELL MANDREW MANLIGUEZ, RN - HEPO II","approved_date":"2026-07-12","reason":""}', '2026-07-12 03:51:49'),
	(33, 15, 10, 'development', 'Development Details Updated', 'Initial draft, revision and distribution details were updated.', NULL, NULL, '{"initial_draft_date":"2026-07-12","final_approval_date":"2026-07-13","revision_required":0,"revision_count":0,"distribution_mode":"N/A"}', '2026-07-12 03:52:29'),
	(34, 15, 12, 'completed', 'Request Completed', 'The requested output was marked as completed.', 'Approved', 'Completed', '{"approved_by":null,"approved_date":null,"reason":""}', '2026-07-12 03:52:45'),
	(35, 15, 10, 'development', 'Development Details Updated', 'Initial draft, revision and distribution details were updated.', NULL, NULL, '{"initial_draft_date":"2026-07-12","final_approval_date":"2026-07-13","revision_required":0,"revision_count":0,"distribution_mode":"N/A"}', '2026-07-12 03:53:53'),
	(36, 15, 10, 'development', 'Development Details Updated', 'Initial draft, revision and distribution details were updated.', NULL, NULL, '{"initial_draft_date":"2026-07-12","final_approval_date":"2026-07-13","revision_required":0,"revision_count":0,"distribution_mode":"N/A"}', '2026-07-12 03:53:58'),
	(37, 16, 10, 'created', 'Request Submitted', 'The job request was created and submitted for review.', NULL, 'Pending Review', '{"job_no":"HPU-20260712-0003","project_title":"0"}', '2026-07-12 04:05:41'),
	(38, 16, 10, 'reviewed', 'Staff Review Saved', 'The request was reviewed by ACE R. CABUGA.', 'Pending Review', 'Under Review', '{"reviewed_by":"ACE R. CABUGA","reviewed_date":"2026-07-12"}', '2026-07-12 04:06:16'),
	(39, 16, 10, 'development', 'Development Details Updated', 'Initial draft, revision and distribution details were updated.', NULL, NULL, '{"initial_draft_date":"2026-07-13","final_approval_date":null,"revision_required":0,"revision_count":0,"distribution_mode":"N/A"}', '2026-07-12 04:06:33'),
	(40, 16, 12, 'completed', 'Request Completed', 'The requested output was marked as completed.', 'Under Review', 'Completed', '{"approved_by":null,"approved_date":null,"reason":""}', '2026-07-12 04:08:03'),
	(41, 16, 12, 'development', 'Development Details Updated', 'Initial draft, revision and distribution details were updated.', NULL, NULL, '{"initial_draft_date":"2026-07-13","final_approval_date":"2026-07-14","revision_required":0,"revision_count":0,"distribution_mode":"N/A"}', '2026-07-12 04:08:15'),
	(42, 16, 12, 'development', 'Development Details Updated', 'Initial draft, revision and distribution details were updated.', NULL, NULL, '{"initial_draft_date":"2026-07-13","final_approval_date":"2026-07-14","revision_required":0,"revision_count":0,"distribution_mode":"N/A"}', '2026-07-12 04:18:51'),
	(43, 17, 12, 'created', 'Request Submitted', 'The job request was created and submitted for review.', NULL, 'Pending Review', '{"job_no":"HPU-20260712-0004","project_title":"1"}', '2026-07-12 04:35:33'),
	(44, 17, 10, 'reviewed', 'Staff Review Saved', 'The request was reviewed by JASMIN B. CAONG.', 'Pending Review', 'Under Review', '{"reviewed_by":"JASMIN B. CAONG","reviewed_date":"2026-07-12"}', '2026-07-12 04:37:46'),
	(45, 17, 10, 'development', 'Development Details Updated', 'Initial draft, revision and distribution details were updated.', NULL, NULL, '{"initial_draft_date":"2026-07-12","final_approval_date":null,"revision_required":0,"revision_count":0,"distribution_mode":"N/A"}', '2026-07-12 04:38:26'),
	(46, 17, 12, 'approved', 'Request Approved', 'The job request was approved by BELL MANDREW MANLIGUEZ, RN - HEPO II.', 'Under Review', 'Approved', '{"approved_by":"BELL MANDREW MANLIGUEZ, RN - HEPO II","approved_date":"2026-07-12","reason":""}', '2026-07-12 04:39:52'),
	(47, 17, 12, 'approved', 'Request Approved', 'The job request was approved by BELL MANDREW MANLIGUEZ, RN - HEPO II.', 'Approved', 'Approved', '{"approved_by":"BELL MANDREW MANLIGUEZ, RN - HEPO II","approved_date":"2026-07-12","reason":""}', '2026-07-12 04:40:44'),
	(48, 17, 12, 'approved', 'Request Approved', 'The job request was approved by RECKLYN D. RUAZA, RN - HEPO III.', 'Approved', 'Approved', '{"approved_by":"RECKLYN D. RUAZA, RN - HEPO III","approved_date":"2026-07-12","reason":""}', '2026-07-12 04:51:10'),
	(49, 17, 12, 'approved', 'Request Approved', 'The job request was approved by BELL MANDREW MANLIGUEZ, RN - HEPO II.', 'Approved', 'Approved', '{"approved_by":"BELL MANDREW MANLIGUEZ, RN - HEPO II","approved_date":"2026-07-12","reason":""}', '2026-07-12 04:51:16'),
	(50, 17, 12, 'approved', 'Request Approved', 'The job request was approved by RECKLYN D. RUAZA, RN - HEPO III.', 'Approved', 'Approved', '{"approved_by":"RECKLYN D. RUAZA, RN - HEPO III","approved_date":"2026-07-12","reason":""}', '2026-07-12 05:19:23'),
	(51, 17, 12, 'approved', 'Request Approved', 'The job request was approved by RECKLYN D. RUAZA, RN - HEPO III.', 'Approved', 'Approved', '{"approved_by":"RECKLYN D. RUAZA, RN - HEPO III","approved_date":"2026-07-15","reason":""}', '2026-07-12 05:19:36'),
	(52, 17, 12, 'disapproved', 'Request Disapproved', 'trip lang naman', 'Approved', 'Disapproved', '{"approved_by":null,"approved_date":null,"reason":"trip lang naman"}', '2026-07-12 05:21:10'),
	(53, 17, 12, 'development', 'Development Details Updated', 'Initial draft, revision and distribution details were updated.', NULL, NULL, '{"initial_draft_date":"2026-07-12","final_approval_date":null,"revision_required":0,"revision_count":0,"distribution_mode":"N/A","final_design_drive_link":null}', '2026-07-12 06:00:39'),
	(54, 17, 12, 'development', 'Development Details Updated', 'Initial draft, revision and distribution details were updated.', NULL, NULL, '{"initial_draft_date":"2026-07-12","final_approval_date":null,"revision_required":0,"revision_count":0,"distribution_mode":"N/A","final_design_drive_link":"https://drive.google.com/file/d/1JD9Zt8n8j_YpYW4l5pu9vpqOoIgXxHzl/view?usp=sharing"}', '2026-07-12 06:11:57'),
	(55, 17, 12, 'development', 'Development Details Updated', 'Initial draft, revision and distribution details were updated.', NULL, NULL, '{"initial_draft_date":"2026-07-12","final_approval_date":null,"revision_required":0,"revision_count":0,"distribution_mode":"N/A","final_design_drive_link":null}', '2026-07-12 06:13:47'),
	(56, 17, 12, 'completed', 'Request Completed', 'The requested output was marked as completed.', 'Disapproved', 'Completed', '{"approved_by":null,"approved_date":null,"reason":""}', '2026-07-12 06:16:13'),
	(57, 17, 12, 'development', 'Development Details Updated', 'Initial draft, revision and distribution details were updated.', NULL, NULL, '{"initial_draft_date":"2026-07-12","final_approval_date":null,"revision_required":0,"revision_count":0,"distribution_mode":"N/A","final_design_drive_link":"https://drive.google.com/drive/folders/1KO1GdHWc0t1kMuavozUEJ45t_2dVoiiO?usp=sharing"}', '2026-07-12 06:16:32'),
	(58, 17, 10, 'development', 'Development Details Updated', 'Initial draft, revision and distribution details were updated.', NULL, NULL, '{"initial_draft_date":"2026-07-12","final_approval_date":"2026-07-14","revision_required":0,"revision_count":0,"distribution_mode":"N/A","final_design_drive_link":"https://drive.google.com/drive/folders/1KO1GdHWc0t1kMuavozUEJ45t_2dVoiiO?usp=sharing"}', '2026-07-12 06:18:27'),
	(59, 16, 10, 'development', 'Development Details Updated', 'Initial draft, revision and distribution details were updated.', NULL, NULL, '{"initial_draft_date":"2026-07-13","final_approval_date":"2026-07-14","revision_required":0,"revision_count":0,"distribution_mode":"N/A","final_design_drive_link":null}', '2026-07-12 06:21:54'),
	(60, 18, 12, 'created', 'Request Submitted', 'The job request was created and submitted for review.', NULL, 'Pending Review', '{"job_no":"HPU-20260712-0005","project_title":"docu"}', '2026-07-12 06:30:47'),
	(61, 18, 12, 'workflow_move', 'Workflow Status Updated', 'The request was moved through the Kanban board.', 'Pending Review', 'Under Review', NULL, '2026-07-12 06:30:57'),
	(62, 18, 12, 'workflow_move', 'Workflow Status Updated', 'The request was moved through the Kanban board.', 'Under Review', 'Approved', NULL, '2026-07-12 06:31:36'),
	(63, 18, 12, 'workflow_move', 'Workflow Status Updated', 'The request was moved through the Kanban board.', 'Approved', 'In Development', NULL, '2026-07-12 06:31:51'),
	(64, 18, 12, 'workflow_move', 'Workflow Status Updated', 'The request was moved through the Kanban board.', 'In Development', 'Completed', NULL, '2026-07-12 06:32:11'),
	(65, 18, 12, 'workflow_move', 'Workflow Status Updated', 'The request was moved through the Kanban board.', 'Completed', 'For Evaluation', NULL, '2026-07-12 06:32:16'),
	(66, 18, 12, 'workflow_move', 'Workflow Status Updated', 'The request was moved through the Kanban board.', 'For Evaluation', 'Closed', NULL, '2026-07-12 06:32:22'),
	(67, 19, 12, 'created', 'Request Submitted', 'The job request was created and submitted for review.', NULL, 'Pending Review', '{"job_no":"HPU-20260712-0006","project_title":"1"}', '2026-07-12 07:26:47'),
	(68, 19, 10, 'reviewed', 'Staff Review Saved', 'The request was reviewed by ACE R. CABUGA.', 'Pending Review', 'Under Review', '{"reviewed_by":"ACE R. CABUGA","reviewed_date":"2026-07-12"}', '2026-07-12 12:20:12'),
	(69, 19, 10, 'development', 'Development Details Updated', 'Initial draft, revision and distribution details were updated.', NULL, NULL, '{"initial_draft_date":"2026-07-12","final_approval_date":"2026-07-15","revision_required":1,"revision_count":1,"distribution_mode":"N/A","final_design_drive_link":null}', '2026-07-12 12:20:35'),
	(70, 19, 12, 'completed', 'Request Completed', 'The requested output was marked as completed.', 'Under Review', 'Completed', '{"approved_by":null,"approved_date":null,"reason":""}', '2026-07-12 13:06:49'),
	(71, 19, 12, 'development', 'Development Details Updated', 'Initial draft, revision and distribution details were updated.', NULL, NULL, '{"initial_draft_date":"2026-07-12","final_approval_date":"2026-07-15","revision_required":1,"revision_count":1,"distribution_mode":"N/A","final_design_drive_link":null}', '2026-07-12 13:07:00'),
	(72, 19, 12, 'development', 'Development Details Updated', 'Initial draft, revision and distribution details were updated.', NULL, NULL, '{"initial_draft_date":"2026-07-12","final_approval_date":"2026-07-15","revision_required":1,"revision_count":1,"distribution_mode":"N/A","final_design_drive_link":"https://drive.google.com/file/d/1JD9Zt8n8j_YpYW4l5pu9vpqOoIgXxHzl/view?usp=sharing"}', '2026-07-12 13:12:10'),
	(73, 19, 12, 'development', 'Development Details Updated', 'Initial draft, revision and distribution details were updated.', NULL, NULL, '{"initial_draft_date":"2026-07-12","final_approval_date":"2026-07-15","revision_required":1,"revision_count":1,"distribution_mode":"N/A","final_design_drive_link":"https://drive.google.com/file/d/1JD9Zt8n8j_YpYW4l5pu9vpqOoIgXxHzl/view?usp=sharing"}', '2026-07-12 13:12:13'),
	(74, 18, 14, 'development', 'Development Details Updated', 'Initial draft, revision and distribution details were updated.', NULL, NULL, '{"initial_draft_date":"2026-07-13","final_approval_date":"2026-07-14","revision_required":1,"revision_count":1,"distribution_mode":"N/A","final_design_drive_link":"https://drive.google.com/file/d/1JD9Zt8n8j_YpYW4l5pu9vpqOoIgXxHzl/view?usp=sharing"}', '2026-07-13 00:05:15'),
	(75, 20, 14, 'created', 'Request Submitted', 'The job request was created and submitted for review.', NULL, 'Pending Review', '{"job_no":"HPU-20260713-0001","project_title":"FLyer design"}', '2026-07-13 00:14:06'),
	(76, 20, 14, 'reviewed', 'Staff Review Saved', 'The request was reviewed by ACE R. CABUGA.', 'Pending Review', 'Under Review', '{"reviewed_by":"ACE R. CABUGA","reviewed_date":"2026-07-13"}', '2026-07-13 00:15:46'),
	(77, 20, 12, 'approved', 'Request Approved', 'The job request was approved by BELL MANDREW MANLIGUEZ, RN - HEPO II.', 'Under Review', 'Approved', '{"approved_by":"BELL MANDREW MANLIGUEZ, RN - HEPO II","approved_date":"2026-07-13","reason":""}', '2026-07-13 00:17:01'),
	(78, 20, 12, 'completed', 'Request Completed', 'The requested output was marked as completed.', 'Approved', 'Completed', '{"approved_by":null,"approved_date":null,"reason":""}', '2026-07-13 00:24:17');

-- Dumping structure for table hpu_job_request.request_comments
CREATE TABLE IF NOT EXISTS `request_comments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `request_id` int NOT NULL,
  `user_id` int NOT NULL,
  `parent_id` int DEFAULT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `attachment_id` int DEFAULT NULL,
  `is_internal` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_comment_request` (`request_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `request_comments_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `job_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `request_comments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table hpu_job_request.request_comments: ~0 rows (approximately)

-- Dumping structure for table hpu_job_request.request_files
CREATE TABLE IF NOT EXISTS `request_files` (
  `id` int NOT NULL AUTO_INCREMENT,
  `request_id` int NOT NULL,
  `uploaded_by` int DEFAULT NULL,
  `file_category` enum('Request Attachment','Reference','Initial Draft','Revision','Final Output','Other') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Other',
  `original_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stored_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size` bigint NOT NULL DEFAULT '0',
  `version_number` int NOT NULL DEFAULT '1',
  `version_notes` text COLLATE utf8mb4_unicode_ci,
  `is_current` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_file_request` (`request_id`),
  KEY `fk_file_user` (`uploaded_by`),
  CONSTRAINT `fk_file_request` FOREIGN KEY (`request_id`) REFERENCES `job_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_file_user` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table hpu_job_request.request_files: ~0 rows (approximately)

-- Dumping structure for table hpu_job_request.users
CREATE TABLE IF NOT EXISTS `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('admin','staff','supervisor','user') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user',
  `department` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('Active','Inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table hpu_job_request.users: ~10 rows (approximately)
INSERT INTO `users` (`id`, `name`, `email`, `password`, `role`, `department`, `status`, `created_at`) VALUES
	(2, 'HPU Staff', 'staff@hpu.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', 'staff', 'Health Promotion Unit', 'Active', '2026-07-10 21:16:45'),
	(3, 'HPU Supervisor', 'supervisor@hpu.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', 'supervisor', 'Health Promotion Unit', 'Active', '2026-07-10 21:16:45'),
	(4, 'Sample Requestor', 'user@hpu.local', '$2y$10$fcLaF1I1ujA93HWHEcNynO00LhJt2fC2vR7ogks1Eto0/YPmKfk9S', 'user', 'Accounting', 'Active', '2026-07-10 21:16:45'),
	(9, 'System Administrator', 'admin@hpu.local', '$2y$10$GQgGsFQ.VzoNO3NPJw89..Gg7mehGLaAsTuJyFP2Mctsr/axwHa3S', 'admin', 'Health Promotion Unit', 'Active', '2026-07-10 21:30:55'),
	(10, 'ACE RAZ CABUGA', 'acegraphics2013@gmail.com', '$2y$10$wlaDYXpYGj/fwA1X7DXEru5mXb/pP0E1XJXC7RpptJouRFpgBt5Y6', 'staff', 'Public Health Unit', 'Active', '2026-07-10 21:42:07'),
	(11, 'Judy Ann Ruaya', 'astmmcprocurement@gmail.com', '$2y$10$nCnP4Gup9PjTz1Ru6UeuheRBkgWeXNx5AJ9kbNRHcrBQHd76CR9US', 'user', 'Procurement Unit', 'Active', '2026-07-10 21:43:55'),
	(12, 'BELL MANDREW L. MANLIGUEZ, RN', 'bell@gmail.com', '$2y$10$N30.RQK/V.IblEE5J7mQSOOCh.P70gSEGj8E5d30IP5uUDNFyFmjO', 'supervisor', 'Public Health Unit', 'Active', '2026-07-10 22:15:31'),
	(13, 'RECKLYN D. RUAZA, RN', 'recklynruaza@gmail.com', '$2y$10$X4Do5NYjO2rDyV/iJByUW.mpjOaNYxb2EnnU8qjZKS.B3ZUYBf.kS', 'supervisor', 'Public Health Unit', 'Active', '2026-07-11 16:31:04'),
	(14, 'Greg Y. Masangkay', 'masangkay.greg12@gmail.com', '$2y$10$JmG5eE8.FRLLxz6jAT/ZAuEQGWk/dERYADJzHcS.H3S.Q5Tqhid1q', 'staff', 'Public Health Unit', 'Active', '2026-07-12 23:58:28'),
	(15, 'JASMIN B. CAONG', 'caongjasmin@gmail.com', '$2y$10$ssp46hHD5qm3z1PPOu5gQ.ddoqWtXNqni5GVyZnpIdHnyDdi4p9nm', 'staff', 'Public Health Unit', 'Active', '2026-07-13 02:30:22');

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
