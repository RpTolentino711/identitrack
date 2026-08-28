-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Aug 21, 2026 at 04:57 PM
-- Server version: 11.8.8-MariaDB-log
-- PHP Version: 7.2.34

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `u321173822_track`
--

-- --------------------------------------------------------

--
-- Table structure for table `admin_user`
--

CREATE TABLE `admin_user` (
  `admin_id` bigint(20) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(150) NOT NULL,
  `role` enum('SDO_OFFICER','ADMIN','UPCC_MEMBER') NOT NULL DEFAULT 'SDO_OFFICER',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `password_hash` varchar(255) DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_active` datetime DEFAULT NULL,
  `active_session_token` varchar(255) DEFAULT NULL,
  `active_session_ip` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admin_user`
--

INSERT INTO `admin_user` (`admin_id`, `full_name`, `username`, `email`, `role`, `is_active`, `password_hash`, `photo_path`, `created_at`, `updated_at`, `last_active`, `active_session_token`, `active_session_ip`) VALUES
(1, 'Demo Admin', 'admin', 'romeopaolotolentino@gmail.com', 'ADMIN', 1, '$2y$10$Uti1lli33PnkxFjCOt/6g.MldQDoGdVQoiLbsBdVzT667JQFciEkK', '../uploads/admin/admin_1_20260311_132220.jpg', '2026-03-06 16:57:19', '2026-08-21 16:56:51', '2026-08-22 00:56:51', '26fe863f7ef6f93d52617703040479b6', '49.144.44.231'),
(2, 'Admin Tester', 'admintester', 'admintester@nulipa.edu.ph', 'ADMIN', 1, 'PASTE_ADMIN_HASH', NULL, '2026-04-06 19:08:31', '2026-04-06 19:08:31', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `audit_log`
--

CREATE TABLE `audit_log` (
  `audit_id` bigint(20) NOT NULL,
  `actor_admin_id` bigint(20) DEFAULT NULL,
  `action` varchar(80) NOT NULL,
  `entity_type` varchar(80) DEFAULT NULL,
  `entity_id` varchar(80) DEFAULT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `auth_session`
--

CREATE TABLE `auth_session` (
  `session_id` bigint(20) NOT NULL,
  `actor_type` enum('STUDENT','ADMIN') NOT NULL,
  `student_id` varchar(50) DEFAULT NULL,
  `admin_id` bigint(20) DEFAULT NULL,
  `session_token_hash` varchar(255) NOT NULL,
  `issued_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `auth_session`
--

INSERT INTO `auth_session` (`session_id`, `actor_type`, `student_id`, `admin_id`, `session_token_hash`, `issued_at`, `expires_at`, `revoked_at`, `created_at`) VALUES
(152, 'STUDENT', '2023-183482', NULL, '$2y$10$vkUpmVsa7r2UP1Im7LsUne4USUeGtIqjA5vqJPnOK40uxM7WNqTeK', '2026-08-21 04:11:18', '2026-09-20 04:11:18', NULL, '2026-08-20 20:11:18');

-- --------------------------------------------------------

--
-- Table structure for table `community_service_requirement`
--

CREATE TABLE `community_service_requirement` (
  `requirement_id` bigint(20) NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `assigned_by` bigint(20) NOT NULL,
  `related_case_id` bigint(20) DEFAULT NULL,
  `task_name` varchar(150) NOT NULL,
  `location` varchar(150) DEFAULT NULL,
  `hours_required` decimal(10,4) NOT NULL DEFAULT 0.0000,
  `status` enum('ACTIVE','COMPLETED','CANCELLED','PENDING_ACCEPTANCE') NOT NULL DEFAULT 'ACTIVE',
  `notes` text DEFAULT NULL,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `community_service_session`
--

CREATE TABLE `community_service_session` (
  `session_id` bigint(20) NOT NULL,
  `requirement_id` bigint(20) NOT NULL,
  `time_in` datetime NOT NULL,
  `time_out` datetime DEFAULT NULL,
  `login_method` enum('MANUAL','NFC') NOT NULL DEFAULT 'MANUAL',
  `logout_method` enum('MANUAL','NFC') DEFAULT NULL,
  `validated_by` bigint(20) DEFAULT NULL,
  `sdo_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `status` enum('ACTIVE','PAUSED','COMPLETED') NOT NULL DEFAULT 'ACTIVE',
  `pause_reason` varchar(255) DEFAULT NULL,
  `paused_at` datetime DEFAULT NULL,
  `accum_paused_seconds` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `dept_id` int(11) NOT NULL,
  `dept_name` varchar(100) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`dept_id`, `dept_name`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'SABM', 1, '2026-04-11 14:41:47', '2026-04-11 14:41:47'),
(198, 'SACE', 1, '2026-04-28 14:44:47', '2026-05-03 10:06:57');

-- --------------------------------------------------------

--
-- Table structure for table `guardian`
--

CREATE TABLE `guardian` (
  `guardian_id` bigint(20) NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `guardian_fn` varchar(100) NOT NULL,
  `guardian_ln` varchar(100) NOT NULL,
  `guardian_email` varchar(150) DEFAULT NULL,
  `guardian_number` varchar(25) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `guardian`
--

INSERT INTO `guardian` (`guardian_id`, `student_id`, `guardian_fn`, `guardian_ln`, `guardian_email`, `guardian_number`, `created_at`, `updated_at`) VALUES
(46, '2023-183482', 'Guardian', 'Account', 'romeopaolotolentino@gmail.com', '9668257301', '2026-08-20 20:09:53', '2026-08-20 20:09:53');

-- --------------------------------------------------------

--
-- Table structure for table `guard_violation_report`
--

CREATE TABLE `guard_violation_report` (
  `report_id` bigint(20) NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `submitted_by` bigint(20) NOT NULL,
  `offense_type_id` bigint(20) NOT NULL,
  `date_committed` datetime NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',
  `is_seen` tinyint(1) NOT NULL DEFAULT 0,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `reviewed_by` bigint(20) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `manual_login_request`
--

CREATE TABLE `manual_login_request` (
  `request_id` bigint(20) NOT NULL,
  `requirement_id` bigint(20) DEFAULT NULL,
  `student_id` varchar(50) NOT NULL,
  `request_type` enum('LOGIN','LOGOUT') NOT NULL DEFAULT 'LOGIN',
  `login_method` enum('MANUAL','NFC') NOT NULL DEFAULT 'MANUAL',
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `reason` varchar(255) DEFAULT NULL,
  `status` enum('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',
  `decided_by` bigint(20) DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `decision_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notice_to_explain`
--

CREATE TABLE `notice_to_explain` (
  `nte_id` int(11) NOT NULL,
  `case_id` int(11) DEFAULT NULL,
  `offense_id` int(11) DEFAULT NULL,
  `student_id` varchar(50) NOT NULL,
  `incident_report_no` varchar(100) DEFAULT NULL,
  `alleged_details` text DEFAULT NULL,
  `handbook_section` varchar(100) DEFAULT NULL,
  `handbook_page` varchar(50) DEFAULT NULL,
  `custom_instructions` text DEFAULT NULL,
  `admin_signature` varchar(255) DEFAULT NULL,
  `status` varchar(50) DEFAULT 'SENT',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `attachment_path` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

--
-- Dumping data for table `notice_to_explain`
--

INSERT INTO `notice_to_explain` (`nte_id`, `case_id`, `offense_id`, `student_id`, `incident_report_no`, `alleged_details`, `handbook_section`, `handbook_page`, `custom_instructions`, `admin_signature`, `status`, `created_at`, `updated_at`, `attachment_path`) VALUES
(14, 64, 145, '2023-183482', '', '', '', '', '', 'Demo Admin', 'SENT', '2026-08-20 20:20:05', '2026-08-21 16:54:27', 'uploads/nte/nte_1787331267_6a8882c3929b2.pdf');

-- --------------------------------------------------------

--
-- Table structure for table `notification`
--

CREATE TABLE `notification` (
  `notification_id` int(11) NOT NULL,
  `type` varchar(50) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `student_id` varchar(50) DEFAULT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `related_table` varchar(80) DEFAULT NULL,
  `related_id` varchar(80) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notification`
--

INSERT INTO `notification` (`notification_id`, `type`, `title`, `message`, `student_id`, `admin_id`, `related_table`, `related_id`, `is_read`, `is_deleted`, `created_at`) VALUES
(275, 'OFFENSE_LETTER', 'Student Conduct Notice Sent', 'An official conduct notice (Student Conduct Notice — Offense Report) was sent to your parent/guardian by the Student Discipline Office.', '2023-183482', 1, 'violation_letter', '35', 0, 0, '2026-08-21 04:12:07'),
(276, 'OFFENSE_LETTER', 'Student Conduct Notice Sent', 'An official conduct notice (Student Conduct Notice — Offense Report) was sent to your parent/guardian by the Student Discipline Office.', '2023-183482', 1, 'violation_letter', '36', 0, 0, '2026-08-21 04:19:57'),
(277, 'FORM_F005', 'Notice To Explain Issued (Form F-005)', 'Per NU Lipa SDO Policy, an official Form F-005 Notice to Explain has been sent to your student Outlook email. Please check your inbox and submit your written explanation within 5 days.', '2023-183482', 1, 'notice_to_explain', '14', 0, 0, '2026-08-21 04:20:07'),
(278, 'FORM_F005', 'Notice To Explain Issued (Form F-005)', 'Per NU Lipa SDO Policy, an official Form F-005 Notice to Explain has been sent to your student Outlook email. Please check your inbox and submit your written explanation within 5 days.', '2023-183482', 1, 'notice_to_explain', '14', 0, 0, '2026-08-21 04:21:35'),
(279, 'FORM_F005', 'Notice To Explain Issued (Form F-005)', 'Per NU Lipa SDO Policy, an official Form F-005 Notice to Explain has been sent to your student Outlook email. Please check your inbox and submit your written explanation within 5 days.', '2023-183482', 1, 'notice_to_explain', '14', 0, 0, '2026-08-21 04:26:23'),
(280, 'FORM_F005', 'Notice To Explain Issued (Form F-005)', 'Per NU Lipa SDO Policy, an official Form F-005 Notice to Explain has been sent to your student Outlook email. Please check your inbox and submit your written explanation within 5 days.', '2023-183482', 1, 'notice_to_explain', '14', 0, 0, '2026-08-21 04:30:58'),
(281, 'FORM_F005', 'Notice To Explain Issued (Form F-005)', 'Per NU Lipa SDO Policy, an official Form F-005 Notice to Explain has been sent to your student Outlook email. Please check your inbox and submit your written explanation within 5 days.', '2023-183482', 1, 'notice_to_explain', '14', 0, 0, '2026-08-21 04:36:02'),
(282, 'FORM_F005', 'Notice To Explain Issued (Form F-005)', 'Per NU Lipa SDO Policy, an official Form F-005 Notice to Explain has been sent to your student Outlook email. Please check your inbox and submit your written explanation within 5 days.', '2023-183482', 1, 'notice_to_explain', '14', 0, 0, '2026-08-22 00:28:41'),
(283, 'FORM_F005', 'Notice To Explain Issued (Form F-005)', 'Per NU Lipa SDO Policy, an official Form F-005 Notice to Explain has been sent to your student Outlook email. Please check your inbox and submit your written explanation within 5 days.', '2023-183482', 1, 'notice_to_explain', '14', 0, 0, '2026-08-22 00:29:11'),
(284, 'FORM_F005', 'Notice To Explain Issued (Form F-005)', 'Per NU Lipa SDO Policy, an official Form F-005 Notice to Explain has been sent to your student Outlook email. Please check your inbox and submit your written explanation within 5 days.', '2023-183482', 1, 'notice_to_explain', '14', 0, 0, '2026-08-22 00:33:07'),
(285, 'FORM_F005', 'Notice To Explain Issued (Form F-005)', 'Per NU Lipa SDO Policy, an official Form F-005 Notice to Explain has been sent to your student Outlook email. Please check your inbox and submit your written explanation within 5 days.', '2023-183482', 1, 'notice_to_explain', '14', 0, 0, '2026-08-22 00:35:36'),
(286, 'FORM_F005', 'Notice To Explain Issued (Form F-005)', 'Per NU Lipa SDO Policy, an official Form F-005 Notice to Explain has been sent to your student Outlook email. Please check your inbox and submit your written explanation within 5 days.', '2023-183482', 1, 'notice_to_explain', '14', 0, 0, '2026-08-22 00:38:41'),
(287, 'FORM_F005', 'Notice To Explain Issued (Form F-005)', 'Per NU Lipa SDO Policy, an official Form F-005 Notice to Explain has been sent to your student Outlook email. Please check your inbox and submit your written explanation within 5 days.', '2023-183482', 1, 'notice_to_explain', '14', 0, 0, '2026-08-22 00:40:39'),
(288, 'FORM_F005', 'Notice To Explain Issued (Form F-005)', 'Per NU Lipa SDO Policy, an official Form F-005 Notice to Explain has been sent to your student Outlook email. Please check your inbox and submit your written explanation within 5 days.', '2023-183482', 1, 'notice_to_explain', '14', 0, 0, '2026-08-22 00:41:23'),
(289, 'FORM_F005', 'Notice To Explain Issued (Form F-005)', 'Per NU Lipa SDO Policy, an official Form F-005 Notice to Explain has been sent to your student Outlook email. Please check your inbox and submit your written explanation within 5 days.', '2023-183482', 1, 'notice_to_explain', '14', 0, 0, '2026-08-22 00:49:21'),
(290, 'FORM_F005', 'Notice To Explain Issued (Form F-005)', 'Per NU Lipa SDO Policy, an official Form F-005 Notice to Explain has been sent to your student Outlook email. Please check your inbox and submit your written explanation within 5 days.', '2023-183482', 1, 'notice_to_explain', '14', 0, 0, '2026-08-22 00:52:23'),
(291, 'FORM_F005', 'Notice To Explain Issued (Form F-005)', 'Per NU Lipa SDO Policy, an official Form F-005 Notice to Explain has been sent to your student Outlook email. Please check your inbox and submit your written explanation within 5 days.', '2023-183482', 1, 'notice_to_explain', '14', 0, 0, '2026-08-22 00:54:45');

-- --------------------------------------------------------

--
-- Table structure for table `notification_log`
--

CREATE TABLE `notification_log` (
  `notification_id` bigint(20) NOT NULL,
  `student_id` varchar(50) DEFAULT NULL,
  `guardian_id` bigint(20) DEFAULT NULL,
  `channel` enum('EMAIL','SMS','PUSH') NOT NULL,
  `subject` varchar(200) DEFAULT NULL,
  `message` text NOT NULL,
  `status` enum('QUEUED','SENT','FAILED') NOT NULL DEFAULT 'QUEUED',
  `provider_response` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `sent_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `offense`
--

CREATE TABLE `offense` (
  `offense_id` bigint(20) NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `recorded_by` bigint(20) NOT NULL,
  `offense_type_id` bigint(20) NOT NULL,
  `level` enum('MINOR','MAJOR','DISMISSED') NOT NULL DEFAULT 'MINOR',
  `description` blob DEFAULT NULL,
  `date_committed` datetime NOT NULL,
  `status` enum('OPEN','RESOLVED','VOID') NOT NULL DEFAULT 'OPEN',
  `guardian_notified_at` datetime DEFAULT NULL,
  `acknowledged_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_deleted_by_student` tinyint(1) DEFAULT 0,
  `dismissal_reason` text DEFAULT NULL,
  `evidence_file` varchar(255) DEFAULT NULL,
  `incident_photo` varchar(255) DEFAULT NULL,
  `show_in_hearing` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `offense`
--

INSERT INTO `offense` (`offense_id`, `student_id`, `recorded_by`, `offense_type_id`, `level`, `description`, `date_committed`, `status`, `guardian_notified_at`, `acknowledged_at`, `created_at`, `updated_at`, `is_deleted_by_student`, `dismissal_reason`, `evidence_file`, `incident_photo`, `show_in_hearing`) VALUES
(143, '2023-183482', 1, 14, 'MINOR', NULL, '2026-08-21 04:11:00', 'OPEN', NULL, NULL, '2026-08-20 20:11:41', '2026-08-20 20:35:33', 0, NULL, 'uploads/incident_reports/evidence_1787258133_6a876515c7162.pdf', NULL, 1),
(144, '2023-183482', 1, 7, 'MINOR', NULL, '2026-08-21 04:11:00', 'OPEN', '2026-08-21 04:12:07', NULL, '2026-08-20 20:11:52', '2026-08-20 20:35:33', 0, NULL, 'uploads/incident_reports/evidence_1787258133_6a876515c7162.pdf', NULL, 1),
(145, '2023-183482', 1, 14, 'MINOR', NULL, '2026-08-21 04:19:00', 'OPEN', '2026-08-21 04:19:57', NULL, '2026-08-20 20:19:49', '2026-08-20 20:35:33', 0, NULL, 'uploads/incident_reports/evidence_1787258133_6a876515c7162.pdf', NULL, 1);

--
-- Triggers `offense`
--
DELIMITER $$
CREATE TRIGGER `trg_offense_set_level_before_insert` BEFORE INSERT ON `offense` FOR EACH ROW BEGIN
  DECLARE v_level ENUM('MINOR','MAJOR');
  SELECT level INTO v_level
  FROM offense_type
  WHERE offense_type_id = NEW.offense_type_id
  LIMIT 1;

  SET NEW.level = v_level;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `offense_type`
--

CREATE TABLE `offense_type` (
  `offense_type_id` bigint(20) NOT NULL,
  `code` varchar(30) NOT NULL,
  `name` varchar(150) NOT NULL,
  `level` enum('MINOR','MAJOR','DISMISSED') NOT NULL DEFAULT 'MINOR',
  `major_category` tinyint(4) DEFAULT NULL,
  `intervention_first` text DEFAULT NULL,
  `intervention_second` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `offense_type`
--

INSERT INTO `offense_type` (`offense_type_id`, `code`, `name`, `level`, `major_category`, `intervention_first`, `intervention_second`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'MIN-001', 'Non-wearing of the prescribed uniform inside the campus.', 'MINOR', NULL, NULL, NULL, 1, '2026-03-08 11:50:03', '2026-03-08 11:50:03'),
(2, 'MIN-002', 'Non-wearing or failure to bring University ID on campus or during official University activities outside the campus.', 'MINOR', NULL, NULL, NULL, 1, '2026-03-08 11:50:03', '2026-03-08 11:50:03'),
(3, 'MIN-003', 'Wearing inappropriate attire within the University premises.', 'MINOR', NULL, NULL, NULL, 1, '2026-03-08 11:50:03', '2026-06-21 15:04:11'),
(4, 'MIN-004', 'Wearing clothing with inappropriate language and suggestive graphics that do not conform with the University\'s values.', 'MINOR', NULL, NULL, NULL, 1, '2026-03-08 11:50:03', '2026-03-08 11:50:03'),
(5, 'MIN-005', 'Using the classroom, facilities, or equipment without reservation or proper authority.', 'MINOR', NULL, NULL, NULL, 1, '2026-03-08 11:50:03', '2026-03-08 11:50:03'),
(6, 'MIN-006', 'Loitering along the classroom corridors while classes are going on.', 'MINOR', NULL, NULL, NULL, 1, '2026-03-08 11:50:03', '2026-03-08 11:50:03'),
(7, 'MIN-007', 'Eating in classrooms, laboratories, offices, libraries, and study areas.', 'MINOR', NULL, NULL, NULL, 1, '2026-03-08 11:50:03', '2026-03-08 11:50:03'),
(8, 'MIN-008', 'Littering.', 'MINOR', NULL, NULL, NULL, 1, '2026-03-08 11:50:03', '2026-03-08 11:50:03'),
(9, 'MIN-009', 'Rearranging the tables, chairs and other fixtures in classrooms, laboratories, or the library without approval.', 'MINOR', NULL, NULL, NULL, 1, '2026-03-08 11:50:03', '2026-03-08 11:50:03'),
(10, 'MIN-010', 'Violating the policies on the use of lockers.', 'MINOR', NULL, NULL, NULL, 1, '2026-03-08 11:50:03', '2026-03-08 11:50:03'),
(11, 'MIN-011', 'Concealing or hiding of library materials in any area of the library for one’s exclusive use.', 'MINOR', NULL, NULL, NULL, 1, '2026-03-08 11:50:03', '2026-03-08 11:50:03'),
(12, 'MIN-012', 'Dyeing hair with artificial color that is deemed inappropriate by the University.', 'MINOR', NULL, NULL, NULL, 1, '2026-03-08 11:50:03', '2026-03-08 11:50:03'),
(13, 'MIN-013', 'Presence of the opposite sex in areas designated exclusively for the use of either the male or the female sex.', 'MINOR', NULL, NULL, NULL, 1, '2026-03-08 11:50:03', '2026-03-08 11:50:03'),
(14, 'MIN-014', 'Bypassing the student entrance in bringing any item inside the University premises.', 'MINOR', NULL, NULL, NULL, 1, '2026-03-08 11:50:03', '2026-03-08 11:50:03'),
(15, 'MIN-015', 'Piercings, excessive and dangling earrings.', 'MINOR', NULL, NULL, NULL, 1, '2026-03-08 11:50:03', '2026-03-08 11:50:03'),
(16, 'MIN-016', 'Earrings among males.', 'MINOR', NULL, NULL, NULL, 1, '2026-03-08 11:50:03', '2026-03-08 11:50:03'),
(17, 'MAJ-001', 'Cheating or academic dishonesty, in online or face-to-face settings, before or during an examination.', 'MAJOR', 2, 'Category 2 & 0.0 in the course', 'Category 3 & 0.0 in the course', 1, '2026-03-08 11:50:03', '2026-03-08 11:50:03'),
(18, 'MAJ-002', 'Unjust enrichment or stealing whether attempted, frustrated or consummated.', 'MAJOR', 3, 'Category 3', NULL, 1, '2026-03-08 11:50:03', '2026-03-08 11:50:03'),
(19, 'MAJ-003', 'Selling items, engaging in business, or soliciting contributions or donations in campus without prior approval or authority.', 'MAJOR', 2, 'Category 2', 'Category 3', 1, '2026-03-08 11:50:03', '2026-03-08 11:50:03'),
(20, 'MAJ-004', 'Vandalism, unhygienic use, or destruction of property belonging to the University or to any NU personnel, student, or visitor while on campus.', 'MAJOR', 2, 'Category 2 and charged for the damages', 'Category 3 and charged for the damages', 1, '2026-03-08 11:50:03', '2026-03-08 11:50:03'),
(21, 'MAJ-005', 'Brawl within the University premises or during an academic function or school activity outside the University.', 'MAJOR', 3, 'Category 3', NULL, 1, '2026-03-08 11:50:03', '2026-03-08 11:50:03'),
(22, 'OTHER_MINOR', 'Other / Custom Minor Offense', 'MINOR', NULL, NULL, NULL, 1, '2026-05-03 06:16:16', '2026-05-03 06:16:16'),
(23, 'OTHER_MAJOR', 'Other / Custom Major Offense', 'MAJOR', NULL, NULL, NULL, 1, '2026-05-03 06:16:16', '2026-05-03 06:16:16'),
(24, 'titit', 'dadada', 'MINOR', NULL, NULL, NULL, 0, '2026-06-21 14:58:39', '2026-06-21 15:03:41'),
(25, 'dadada', 'a', 'MINOR', NULL, NULL, NULL, 0, '2026-06-21 15:04:18', '2026-06-21 15:05:13'),
(26, 'dada', 'dsada', 'MAJOR', 1, NULL, NULL, 0, '2026-06-27 17:25:06', '2026-06-27 17:28:06'),
(27, 'sdasda', 'dadada', 'MAJOR', 1, NULL, NULL, 1, '2026-06-27 17:25:25', '2026-06-27 17:25:25'),
(28, 'a', 'a', 'MAJOR', 4, NULL, NULL, 1, '2026-07-09 21:08:54', '2026-07-09 21:08:54'),
(29, 'DISM-01', 'Confiscated Item without Prohibited Component (e.g. Vape without battery/e-liquid)', 'DISMISSED', NULL, NULL, NULL, 1, '2026-08-18 20:59:09', '2026-08-18 20:59:09'),
(30, 'DISM-02', 'Informal Incident / Non-Actionable Administrative Tracking', 'DISMISSED', NULL, NULL, NULL, 1, '2026-08-18 20:59:09', '2026-08-18 20:59:09');

-- --------------------------------------------------------

--
-- Table structure for table `security_guard`
--

CREATE TABLE `security_guard` (
  `guard_id` bigint(20) NOT NULL,
  `full_name` varbinary(255) DEFAULT NULL,
  `username` varchar(50) NOT NULL,
  `email` varbinary(255) DEFAULT NULL,
  `role` enum('GUARD','SENIOR_GUARD','GUARD_SUPERVISOR') NOT NULL DEFAULT 'GUARD',
  `password_hash` varchar(255) NOT NULL,
  `contact_number` varchar(25) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `photo_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `security_guard`
--

INSERT INTO `security_guard` (`guard_id`, `full_name`, `username`, `email`, `role`, `password_hash`, `contact_number`, `is_active`, `photo_path`, `created_at`, `updated_at`) VALUES
(1, 0x4a6f686e204775617264, 'johnguard', 0x6a6f686e2e6775617264406e756c6970612e6564752e7068, 'GUARD', '$2y$10$bmEYRQ6MyWbMf6e/TWQuS.Qh/KXCu3DAQtOiKM7facR2I8GsX68yO', '09171234567', 1, NULL, '2026-03-23 18:33:47', '2026-06-11 12:29:41'),
(2, 0x4d617269612053616e746f73, 'mariasantos', 0x6d617269612e73616e746f73406e756c6970612e6564752e7068, 'SENIOR_GUARD', '$2y$10$bmEYRQ6MyWbMf6e/TWQuS.Qh/KXCu3DAQtOiKM7facR2I8GsX68yO', '09187654321', 1, NULL, '2026-03-23 18:33:47', '2026-06-11 12:29:41'),
(3, 0x4361726c6f73205265796573, 'carlosreyes', 0x6361726c6f732e7265796573406e756c6970612e6564752e7068, 'GUARD_SUPERVISOR', '$2y$10$bmEYRQ6MyWbMf6e/TWQuS.Qh/KXCu3DAQtOiKM7facR2I8GsX68yO', '09165432109', 1, NULL, '2026-03-23 18:33:47', '2026-06-11 12:29:41'),
(4, 0x54657374204775617264, 'testguard', 0x74657374406e756c6970612e6564752e7068, 'GUARD', '$2y$10$bmEYRQ6MyWbMf6e/TWQuS.Qh/KXCu3DAQtOiKM7facR2I8GsX68yO', '09171234567', 1, NULL, '2026-03-23 18:33:47', '2026-06-11 12:29:41'),
(5, 0x477561726420546573746572, 'guardtester', 0x6775617264746573746572406e756c6970612e6564752e7068, 'GUARD', 'PASTE_GUARD_HASH', '09170000000', 1, NULL, '2026-04-06 19:08:31', '2026-06-11 12:29:41'),
(7, 0x526f6d656f2050616f6c6f, 'xkid', 0x786b6964406e756c6970612e6564752e7068, 'GUARD', '$2y$10$6ySSzICACTVGRHyAsvHzRuQm5UqTGtf5rbD.GDfVWNajw/ExP7MXe', '0997888', 1, NULL, '2026-04-14 00:52:17', '2026-06-11 12:29:41');

-- --------------------------------------------------------

--
-- Table structure for table `student`
--

CREATE TABLE `student` (
  `student_id` varchar(50) NOT NULL,
  `student_fn` varbinary(255) DEFAULT NULL,
  `student_ln` varbinary(255) DEFAULT NULL,
  `year_level` tinyint(4) NOT NULL,
  `section` varchar(50) DEFAULT NULL,
  `school` varchar(100) DEFAULT NULL,
  `program` varchar(100) DEFAULT NULL,
  `student_email` varbinary(255) DEFAULT NULL,
  `scanner_id_hash` char(64) DEFAULT NULL,
  `home_address` blob DEFAULT NULL,
  `phone_number` varbinary(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `privacy_accepted` int(11) DEFAULT 0,
  `privacy_accepted_at` datetime DEFAULT NULL,
  `app_registered_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `student`
--

INSERT INTO `student` (`student_id`, `student_fn`, `student_ln`, `year_level`, `section`, `school`, `program`, `student_email`, `scanner_id_hash`, `home_address`, `phone_number`, `is_active`, `created_at`, `updated_at`, `privacy_accepted`, `privacy_accepted_at`, `app_registered_at`) VALUES
('2023-183482', 0x526f6d656f2050616f6c6f, 0x546f6c656e74696e6f, 4, 'INF232', 'College', 'BSIT', 0x726f6d656f70616f6c6f746f6c656e74696e6f40676d61696c2e636f6d, 'd6f933824d4f1d779faba48f1f3d2b943ebab885285bc6e156719b6275d60584', NULL, 0x39363638323537333031, 1, '2026-08-20 20:09:53', '2026-08-20 20:11:18', 1, '2026-08-21 04:11:18', '2026-08-21 04:11:18');

-- --------------------------------------------------------

--
-- Table structure for table `student_appeal_request`
--

CREATE TABLE `student_appeal_request` (
  `appeal_id` bigint(20) NOT NULL,
  `student_id` varchar(30) NOT NULL,
  `offense_id` bigint(20) DEFAULT NULL,
  `case_id` bigint(20) DEFAULT NULL,
  `appeal_kind` enum('OFFENSE','UPCC_CASE') NOT NULL DEFAULT 'OFFENSE',
  `reason` text NOT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `attachment_name` varchar(255) DEFAULT NULL,
  `status` enum('PENDING','REVIEWING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',
  `admin_response` text DEFAULT NULL,
  `decided_by` int(11) DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_seen_by_student` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_email_otp`
--

CREATE TABLE `student_email_otp` (
  `otp_id` bigint(20) NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `email` varchar(150) NOT NULL,
  `otp_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `student_email_otp`
--

INSERT INTO `student_email_otp` (`otp_id`, `student_id`, `email`, `otp_hash`, `expires_at`, `used_at`, `created_at`) VALUES
(192, '2023-183482', 'romeopaolotolentino@gmail.com', '$2y$10$3dDcurgbApvXOkHGOZ9EuOnibrSDQVxDn5GnA5KX5jZFDaFuAnpj2', '2026-08-21 04:15:31', '2026-08-21 04:11:18', '2026-08-20 20:10:31');

-- --------------------------------------------------------

--
-- Table structure for table `student_encrypted_backup`
--

CREATE TABLE `student_encrypted_backup` (
  `student_id` varchar(50) NOT NULL,
  `student_fn_hex` text DEFAULT NULL,
  `student_ln_hex` text DEFAULT NULL,
  `student_email_hex` text DEFAULT NULL,
  `home_address_hex` text DEFAULT NULL,
  `phone_number_hex` text DEFAULT NULL,
  `backed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_config`
--

CREATE TABLE `system_config` (
  `config_key` varchar(100) NOT NULL,
  `config_value` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `system_config`
--

INSERT INTO `system_config` (`config_key`, `config_value`, `updated_at`) VALUES
('gemini_api_key', '', '2026-08-14 23:46:22');

-- --------------------------------------------------------

--
-- Table structure for table `upcc_case`
--

CREATE TABLE `upcc_case` (
  `case_id` bigint(20) NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `created_by` bigint(20) NOT NULL,
  `status` enum('PENDING','UNDER_INVESTIGATION','RESOLVED','CLOSED','UNDER_APPEAL','CANCELLED','AWAITING_ADMIN_FINALIZATION') NOT NULL DEFAULT 'PENDING',
  `case_summary` blob DEFAULT NULL,
  `final_decision` blob DEFAULT NULL,
  `resolution_date` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `case_kind` enum('SECTION4_MINOR_ESCALATION','MAJOR_OFFENSE') DEFAULT NULL,
  `decided_category` tinyint(4) DEFAULT NULL,
  `assigned_department_id` int(11) DEFAULT NULL,
  `assigned_panel_members` text DEFAULT NULL,
  `hearing_date` date DEFAULT NULL,
  `hearing_time` time DEFAULT NULL,
  `hearing_type` enum('ONLINE','FACE_TO_FACE') DEFAULT NULL,
  `hearing_is_open` tinyint(1) NOT NULL DEFAULT 0,
  `hearing_opened_at` datetime DEFAULT NULL,
  `hearing_closed_at` datetime DEFAULT NULL,
  `hearing_opened_by_admin` int(11) DEFAULT NULL,
  `hearing_vote_consensus_category` tinyint(4) DEFAULT NULL,
  `hearing_vote_consensus_at` datetime DEFAULT NULL,
  `probation_until` datetime DEFAULT NULL,
  `hearing_is_paused` tinyint(1) NOT NULL DEFAULT 0,
  `punishment_details` blob DEFAULT NULL,
  `hearing_vote_suggested_details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`hearing_vote_suggested_details`)),
  `hearing_vote_suggester_id` int(11) DEFAULT NULL,
  `hearing_pause_reason` varchar(50) DEFAULT NULL,
  `student_explanation_text` text DEFAULT NULL,
  `student_explanation_image` varchar(255) DEFAULT NULL,
  `student_explanation_pdf` varchar(255) DEFAULT NULL,
  `student_explanation_at` datetime DEFAULT NULL,
  `hearing_link_or_location` varchar(255) DEFAULT NULL,
  `student_hearing_response` enum('PENDING','ACCEPTED','DECLINED') NOT NULL DEFAULT 'PENDING',
  `resolution_file_path` varchar(255) DEFAULT NULL,
  `nfi_file_path` varchar(255) DEFAULT NULL,
  `nfi_date` datetime DEFAULT NULL,
  `evidence_file` varchar(255) DEFAULT NULL,
  `incident_photo` varchar(255) DEFAULT NULL,
  `show_in_hearing` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `upcc_case`
--

INSERT INTO `upcc_case` (`case_id`, `student_id`, `created_by`, `status`, `case_summary`, `final_decision`, `resolution_date`, `created_at`, `updated_at`, `case_kind`, `decided_category`, `assigned_department_id`, `assigned_panel_members`, `hearing_date`, `hearing_time`, `hearing_type`, `hearing_is_open`, `hearing_opened_at`, `hearing_closed_at`, `hearing_opened_by_admin`, `hearing_vote_consensus_category`, `hearing_vote_consensus_at`, `probation_until`, `hearing_is_paused`, `punishment_details`, `hearing_vote_suggested_details`, `hearing_vote_suggester_id`, `hearing_pause_reason`, `student_explanation_text`, `student_explanation_image`, `student_explanation_pdf`, `student_explanation_at`, `hearing_link_or_location`, `student_hearing_response`, `resolution_file_path`, `nfi_file_path`, `nfi_date`, `evidence_file`, `incident_photo`, `show_in_hearing`) VALUES
(64, '2023-183482', 1, 'PENDING', 0x53656374696f6e2034204d616a6f72202831737420457363616c6174696f6e2920e28094204d696e6f72204f6666656e736520233320617474656d707420e2869220526566657272656420746f20555043432070616e656c20666f7220696e7665737469676174696f6e20616e642063617465676f72792061737369676e6d656e74202831e2809135292e, NULL, NULL, '2026-08-20 20:19:49', '2026-08-20 20:35:33', 'SECTION4_MINOR_ESCALATION', NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'PENDING', NULL, NULL, NULL, 'uploads/incident_reports/evidence_1787258133_6a876515c7162.pdf', NULL, 1);

-- --------------------------------------------------------

--
-- Table structure for table `upcc_case_activity`
--

CREATE TABLE `upcc_case_activity` (
  `activity_id` bigint(20) NOT NULL,
  `case_id` bigint(20) NOT NULL,
  `actor_type` enum('ADMIN','UPCC','SYSTEM') NOT NULL,
  `actor_id` int(11) NOT NULL DEFAULT 0,
  `action` varchar(80) NOT NULL,
  `payload_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `upcc_case_activity`
--

INSERT INTO `upcc_case_activity` (`activity_id`, `case_id`, `actor_type`, `actor_id`, `action`, `payload_json`, `created_at`) VALUES
(968, 64, 'ADMIN', 1, 'FORM_F005_SENT', '{\"by\":\"Demo Admin\",\"student_email\":\"romeopaolotolentino@gmail.com\",\"attachment\":\"uploads\\/nte\\/nte_1787257205_6a876175d87f4.pdf\",\"date_formatted\":\"August 21, 2026\",\"time_formatted\":\"04:20:07 AM\"}', '2026-08-21 04:20:07'),
(969, 64, 'ADMIN', 1, 'FORM_F005_SENT', '{\"by\":\"Demo Admin\",\"student_email\":\"romeopaolotolentino@gmail.com\",\"attachment\":\"uploads\\/nte\\/nte_1787257293_6a8761cd47e5a.pdf\",\"date_formatted\":\"August 21, 2026\",\"time_formatted\":\"04:21:35 AM\"}', '2026-08-21 04:21:35'),
(970, 64, 'ADMIN', 1, 'FORM_F005_SENT', '{\"by\":\"Demo Admin\",\"student_email\":\"romeopaolotolentino@gmail.com\",\"attachment\":\"uploads\\/nte\\/nte_1787257581_6a8762ede65b7.pdf\",\"date_formatted\":\"August 21, 2026\",\"time_formatted\":\"04:26:23 AM\"}', '2026-08-21 04:26:23'),
(971, 64, 'ADMIN', 1, 'FORM_F005_SENT', '{\"by\":\"Demo Admin\",\"student_email\":\"romeopaolotolentino@gmail.com\",\"attachment\":\"uploads\\/nte\\/nte_1787257856_6a876400d59cc.pdf\",\"date_formatted\":\"August 21, 2026\",\"time_formatted\":\"04:30:58 AM\"}', '2026-08-21 04:30:58'),
(972, 64, 'ADMIN', 1, 'FORM_F005_SENT', '{\"by\":\"Demo Admin\",\"student_email\":\"romeopaolotolentino@gmail.com\",\"attachment\":\"uploads\\/nte\\/nte_1787258161_6a87653114fbf.pdf\",\"date_formatted\":\"August 21, 2026\",\"time_formatted\":\"04:36:02 AM\"}', '2026-08-21 04:36:02'),
(973, 64, 'ADMIN', 1, 'FORM_F005_SENT', '{\"by\":\"Demo Admin\",\"student_email\":\"romeopaolotolentino@gmail.com\",\"attachment\":\"uploads\\/nte\\/nte_1787329719_6a887cb7a4076.pdf\",\"date_formatted\":\"August 22, 2026\",\"time_formatted\":\"12:28:41 AM\"}', '2026-08-22 00:28:41'),
(974, 64, 'ADMIN', 1, 'FORM_F005_SENT', '{\"by\":\"Demo Admin\",\"student_email\":\"romeopaolotolentino@gmail.com\",\"attachment\":\"uploads\\/nte\\/nte_1787329750_6a887cd607206.pdf\",\"date_formatted\":\"August 22, 2026\",\"time_formatted\":\"12:29:11 AM\"}', '2026-08-22 00:29:11'),
(975, 64, 'ADMIN', 1, 'FORM_F005_SENT', '{\"by\":\"Demo Admin\",\"student_email\":\"romeopaolotolentino@gmail.com\",\"attachment\":\"uploads\\/nte\\/nte_1787329985_6a887dc1a3360.pdf\",\"date_formatted\":\"August 22, 2026\",\"time_formatted\":\"12:33:07 AM\"}', '2026-08-22 00:33:07'),
(976, 64, 'ADMIN', 1, 'FORM_F005_SENT', '{\"by\":\"Demo Admin\",\"student_email\":\"romeopaolotolentino@gmail.com\",\"attachment\":\"uploads\\/nte\\/nte_1787330134_6a887e56623c9.pdf\",\"date_formatted\":\"August 22, 2026\",\"time_formatted\":\"12:35:36 AM\"}', '2026-08-22 00:35:36'),
(977, 64, 'ADMIN', 1, 'FORM_F005_SENT', '{\"by\":\"Demo Admin\",\"student_email\":\"romeopaolotolentino@gmail.com\",\"attachment\":\"uploads\\/nte\\/nte_1787330319_6a887f0fc78ac.pdf\",\"date_formatted\":\"August 22, 2026\",\"time_formatted\":\"12:38:41 AM\"}', '2026-08-22 00:38:41'),
(978, 64, 'ADMIN', 1, 'FORM_F005_SENT', '{\"by\":\"Demo Admin\",\"student_email\":\"romeopaolotolentino@gmail.com\",\"attachment\":\"uploads\\/nte\\/nte_1787330438_6a887f86346cf.pdf\",\"date_formatted\":\"August 22, 2026\",\"time_formatted\":\"12:40:39 AM\"}', '2026-08-22 00:40:39'),
(979, 64, 'ADMIN', 1, 'FORM_F005_SENT', '{\"by\":\"Demo Admin\",\"student_email\":\"romeopaolotolentino@gmail.com\",\"attachment\":\"uploads\\/nte\\/nte_1787330481_6a887fb15cf0d.pdf\",\"date_formatted\":\"August 22, 2026\",\"time_formatted\":\"12:41:23 AM\"}', '2026-08-22 00:41:23'),
(980, 64, 'ADMIN', 1, 'FORM_F005_SENT', '{\"by\":\"Demo Admin\",\"student_email\":\"romeopaolotolentino@gmail.com\",\"attachment\":\"uploads\\/nte\\/nte_1787330959_6a88818f9bb32.pdf\",\"date_formatted\":\"August 22, 2026\",\"time_formatted\":\"12:49:21 AM\"}', '2026-08-22 00:49:21'),
(981, 64, 'ADMIN', 1, 'FORM_F005_SENT', '{\"by\":\"Demo Admin\",\"student_email\":\"romeopaolotolentino@gmail.com\",\"attachment\":\"uploads\\/nte\\/nte_1787331141_6a888245a3d8f.pdf\",\"date_formatted\":\"August 22, 2026\",\"time_formatted\":\"12:52:23 AM\"}', '2026-08-22 00:52:23'),
(982, 64, 'ADMIN', 1, 'FORM_F005_SENT', '{\"by\":\"Demo Admin\",\"student_email\":\"romeopaolotolentino@gmail.com\",\"attachment\":\"uploads\\/nte\\/nte_1787331267_6a8882c3929b2.pdf\",\"date_formatted\":\"August 22, 2026\",\"time_formatted\":\"12:54:45 AM\"}', '2026-08-22 00:54:45');

-- --------------------------------------------------------

--
-- Table structure for table `upcc_case_discussion`
--

CREATE TABLE `upcc_case_discussion` (
  `message_id` bigint(20) NOT NULL,
  `case_id` bigint(20) NOT NULL,
  `upcc_id` int(11) DEFAULT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `reply_to_message_id` bigint(20) DEFAULT NULL,
  `message` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `upcc_case_offense`
--

CREATE TABLE `upcc_case_offense` (
  `case_id` bigint(20) NOT NULL,
  `offense_id` bigint(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `upcc_case_offense`
--

INSERT INTO `upcc_case_offense` (`case_id`, `offense_id`) VALUES
(64, 143),
(64, 144),
(64, 145);

-- --------------------------------------------------------

--
-- Table structure for table `upcc_case_panel_acceptance`
--

CREATE TABLE `upcc_case_panel_acceptance` (
  `acceptance_id` bigint(20) NOT NULL,
  `case_id` bigint(20) NOT NULL,
  `upcc_id` int(11) NOT NULL,
  `accepted_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `upcc_case_panel_member`
--

CREATE TABLE `upcc_case_panel_member` (
  `case_id` bigint(20) NOT NULL,
  `upcc_id` int(11) NOT NULL,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_reminder_sent_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `upcc_case_vote`
--

CREATE TABLE `upcc_case_vote` (
  `case_id` bigint(20) NOT NULL,
  `upcc_id` int(11) NOT NULL,
  `round_no` int(11) NOT NULL,
  `vote_category` tinyint(4) NOT NULL,
  `vote_details` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `upcc_case_vote_round`
--

CREATE TABLE `upcc_case_vote_round` (
  `case_id` bigint(20) NOT NULL,
  `round_no` int(11) NOT NULL DEFAULT 1,
  `started_at` datetime NOT NULL,
  `ends_at` datetime NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `suggested_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `upcc_hearing_presence`
--

CREATE TABLE `upcc_hearing_presence` (
  `session_id` bigint(20) NOT NULL,
  `case_id` bigint(20) NOT NULL,
  `user_type` enum('ADMIN','UPCC') NOT NULL,
  `user_id` int(11) NOT NULL,
  `status` enum('WAITING','ADMITTED','LEFT') NOT NULL DEFAULT 'WAITING',
  `last_ping` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `upcc_panel_rejoin_requests`
--

CREATE TABLE `upcc_panel_rejoin_requests` (
  `request_id` bigint(20) NOT NULL,
  `case_id` bigint(20) NOT NULL,
  `upcc_id` int(11) NOT NULL,
  `requested_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `upcc_suggestion_cooldown`
--

CREATE TABLE `upcc_suggestion_cooldown` (
  `cooldown_id` bigint(20) NOT NULL,
  `case_id` bigint(20) NOT NULL,
  `round_no` int(11) NOT NULL,
  `upcc_id` int(11) NOT NULL,
  `cooldown_until` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `upcc_user`
--

CREATE TABLE `upcc_user` (
  `upcc_id` int(11) NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `username` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `role` varchar(50) DEFAULT 'user',
  `is_active` tinyint(1) DEFAULT 1,
  `password_hash` varchar(255) NOT NULL,
  `photo_path` varchar(255) DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `department_id` int(11) DEFAULT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `upcc_user`
--

INSERT INTO `upcc_user` (`upcc_id`, `full_name`, `username`, `email`, `role`, `is_active`, `password_hash`, `photo_path`, `created_at`, `updated_at`, `department_id`, `must_change_password`) VALUES
(2, 'Test UPCC User', 'testupcc', 'tolentinoromeo549@gmail.com', 'Member', 0, '$2y$10$NuF1jfLXq3xJ8IF.ttKja.J3YymNjPdePXPnSYyclbco0V4tMQkZK', '', '2026-03-22 19:47:54', '2026-04-23 13:03:13', NULL, 1),
(3, 'UPCC Tester', 'upcctester', 'tolentinoromeo549@gmail.com', 'user', 1, 'PASTE_UPCC_HASH', '', '2026-04-06 19:08:31', '2026-04-23 01:37:36', 1, 1),
(4, 'Panel1', 'upccpanel1', 'romeopaolotolentino@gmail.com', 'Member', 1, '$2y$10$48ncJ08GIBi8yxR4OJwZSe5L/Vyve8VS/1vNVSAGT7agut0iDqfxe', '', '2026-04-06 19:13:57', '2026-07-24 16:05:43', 1, 0),
(6, 'roms', 'roms', 'romeopaolotolentino@gmail.com', 'Chairperson', 1, '$2y$10$64qHENlIjF/ih5u9CFEBx.CPzd3n21YGNXtiSrHWh9LmoKZXMb2Hm', '', '2026-04-06 20:07:03', '2026-04-23 00:49:39', NULL, 1),
(7, 'Panel2', 'upccpanel2', 'romeopaolotolentino@gmail.com', 'Member', 1, '$2y$10$21Q2hgZtuFnSN26d5aDBRemcqOu9ZqWxRnhPOSHYQlI7RGkdPCAiO', '', '2026-04-29 22:23:01', '2026-05-03 21:18:04', 198, 0);

-- --------------------------------------------------------

--
-- Table structure for table `violation_letter`
--

CREATE TABLE `violation_letter` (
  `letter_id` bigint(20) NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `generated_by` bigint(20) NOT NULL,
  `letter_type` enum('THIRD_MINOR_NOTICE','MAJOR_OFFENSE_NOTICE','CUSTOM') NOT NULL DEFAULT 'THIRD_MINOR_NOTICE',
  `subject` varchar(200) NOT NULL,
  `body` longtext NOT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `generated_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `violation_letter`
--

INSERT INTO `violation_letter` (`letter_id`, `student_id`, `generated_by`, `letter_type`, `subject`, `body`, `file_path`, `generated_at`) VALUES
(35, '2023-183482', 1, 'CUSTOM', 'Student Conduct Notice — Offense Report', '<p>Dear Guardian,</p><p> </p><p> This is to inform you that your student has been reported for a second minor conduct offense. Please see the detailed notice below for more information regarding this incident.</p><p> </p><p> CURRENT OFFENSE:</p><p> - MIN-007 — Eating in classrooms, laboratories, offices, libraries, and study areas.</p><p> - Level: MINOR</p><p> - Date: August 21, 2026 4:11 AM</p><p> - Notes: (none)</p><p> </p><p> PRIOR OFFENSE HISTORY (Most recent first):</p><p> 1. [MINOR] MIN-014 — Bypassing the student entrance in bringing any item inside the University premises. (Aug 21, 2026 4:11 AM)</p><p> </p><p> </p><p> We encourage you to support your student in maintaining proper conduct within our institution.</p><p> </p><p> Sincerely,</p><p> Student Discipline Office</p>', 'uploads/letters/minor_offense_144_20260821_041204.pdf', '2026-08-21 04:12:07'),
(36, '2023-183482', 1, 'CUSTOM', 'Student Conduct Notice — Offense Report', '<p>Dear Guardian,</p><p> </p><p> This is an official notice to inform you that your student has accumulated their 3rd minor offense, which triggers an automatic escalation to a Major Offense status under our discipline policy.</p><p> </p><p> The student\'s case has now been forwarded to the University Panel on Community Conduct (UPCC), and a formal investigation is underway. We ask for your immediate cooperation as we review these repeated infractions.</p><p> </p><p> Please see the detailed notice below for the complete offense history.</p><p> </p><p> CURRENT OFFENSE:</p><p> - MIN-014 — Bypassing the student entrance in bringing any item inside the University premises.</p><p> - Level: MINOR</p><p> - Date: August 21, 2026 4:19 AM</p><p> - Notes: (none)</p><p> </p><p> PRIOR OFFENSE HISTORY (Most recent first):</p><p> 1. [MINOR] MIN-007 — Eating in classrooms, laboratories, offices, libraries, and study areas. (Aug 21, 2026 4:11 AM)</p><p> 2. [MINOR] MIN-014 — Bypassing the student entrance in bringing any item inside the University premises. (Aug 21, 2026 4:11 AM)</p><p> </p><p> </p><p> We encourage you to support your student in maintaining proper conduct within our institution.</p><p> </p><p> Sincerely,</p><p> Student Discipline Office</p>', 'uploads/letters/minor_offense_145_20260821_041955.pdf', '2026-08-21 04:19:57');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin_user`
--
ALTER TABLE `admin_user`
  ADD PRIMARY KEY (`admin_id`),
  ADD UNIQUE KEY `uq_admin_email` (`email`),
  ADD UNIQUE KEY `uq_admin_username` (`username`),
  ADD KEY `idx_admin_role` (`role`);

--
-- Indexes for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`audit_id`),
  ADD KEY `idx_audit_actor_time` (`actor_admin_id`,`created_at`);

--
-- Indexes for table `auth_session`
--
ALTER TABLE `auth_session`
  ADD PRIMARY KEY (`session_id`),
  ADD KEY `idx_session_actor` (`actor_type`,`student_id`,`admin_id`),
  ADD KEY `idx_session_expiry` (`expires_at`),
  ADD KEY `fk_session_student` (`student_id`),
  ADD KEY `fk_session_admin` (`admin_id`);

--
-- Indexes for table `community_service_requirement`
--
ALTER TABLE `community_service_requirement`
  ADD PRIMARY KEY (`requirement_id`),
  ADD KEY `idx_csr_student_status` (`student_id`,`status`),
  ADD KEY `fk_csr_assigned_by` (`assigned_by`),
  ADD KEY `fk_csr_case` (`related_case_id`);

--
-- Indexes for table `community_service_session`
--
ALTER TABLE `community_service_session`
  ADD PRIMARY KEY (`session_id`),
  ADD KEY `idx_css_requirement_timein` (`requirement_id`,`time_in`),
  ADD KEY `idx_css_validator` (`validated_by`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`dept_id`),
  ADD UNIQUE KEY `dept_name` (`dept_name`);

--
-- Indexes for table `guardian`
--
ALTER TABLE `guardian`
  ADD PRIMARY KEY (`guardian_id`),
  ADD UNIQUE KEY `uq_guardian_student` (`student_id`),
  ADD UNIQUE KEY `uq_guardian_email` (`guardian_email`);

--
-- Indexes for table `guard_violation_report`
--
ALTER TABLE `guard_violation_report`
  ADD PRIMARY KEY (`report_id`),
  ADD KEY `idx_gvr_student` (`student_id`),
  ADD KEY `idx_gvr_status` (`status`),
  ADD KEY `idx_gvr_submitted` (`submitted_by`),
  ADD KEY `idx_gvr_reviewed` (`reviewed_by`),
  ADD KEY `fk_gvr_offense_type` (`offense_type_id`);

--
-- Indexes for table `manual_login_request`
--
ALTER TABLE `manual_login_request`
  ADD PRIMARY KEY (`request_id`),
  ADD KEY `idx_mlr_status_time` (`status`,`requested_at`),
  ADD KEY `fk_mlr_requirement` (`requirement_id`),
  ADD KEY `fk_mlr_student` (`student_id`),
  ADD KEY `fk_mlr_decided_by` (`decided_by`);

--
-- Indexes for table `notice_to_explain`
--
ALTER TABLE `notice_to_explain`
  ADD PRIMARY KEY (`nte_id`),
  ADD KEY `idx_nte_student` (`student_id`),
  ADD KEY `idx_nte_case` (`case_id`),
  ADD KEY `idx_nte_offense` (`offense_id`);

--
-- Indexes for table `notification`
--
ALTER TABLE `notification`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `idx_notification_created_at` (`created_at`),
  ADD KEY `idx_notification_is_read` (`is_read`),
  ADD KEY `idx_notification_is_deleted` (`is_deleted`),
  ADD KEY `idx_notification_student_id` (`student_id`),
  ADD KEY `idx_notification_admin_id` (`admin_id`);

--
-- Indexes for table `notification_log`
--
ALTER TABLE `notification_log`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `idx_notif_status` (`status`),
  ADD KEY `idx_notif_student_created` (`student_id`,`created_at`),
  ADD KEY `idx_notif_guardian_created` (`guardian_id`,`created_at`);

--
-- Indexes for table `offense`
--
ALTER TABLE `offense`
  ADD PRIMARY KEY (`offense_id`),
  ADD KEY `idx_offense_student_date` (`student_id`,`date_committed`),
  ADD KEY `idx_offense_level` (`level`),
  ADD KEY `idx_offense_status` (`status`),
  ADD KEY `idx_offense_type` (`offense_type_id`),
  ADD KEY `fk_offense_admin` (`recorded_by`);

--
-- Indexes for table `offense_type`
--
ALTER TABLE `offense_type`
  ADD PRIMARY KEY (`offense_type_id`),
  ADD UNIQUE KEY `uq_offense_type_code` (`code`),
  ADD KEY `idx_offense_type_level` (`level`),
  ADD KEY `idx_offense_type_active` (`is_active`);

--
-- Indexes for table `security_guard`
--
ALTER TABLE `security_guard`
  ADD PRIMARY KEY (`guard_id`),
  ADD UNIQUE KEY `uq_guard_email` (`email`),
  ADD UNIQUE KEY `uq_guard_username` (`username`),
  ADD KEY `idx_guard_active` (`is_active`);

--
-- Indexes for table `student`
--
ALTER TABLE `student`
  ADD PRIMARY KEY (`student_id`),
  ADD UNIQUE KEY `uq_student_email` (`student_email`),
  ADD UNIQUE KEY `uq_student_scanner_hash` (`scanner_id_hash`),
  ADD KEY `idx_student_name` (`student_ln`,`student_fn`);

--
-- Indexes for table `student_appeal_request`
--
ALTER TABLE `student_appeal_request`
  ADD PRIMARY KEY (`appeal_id`),
  ADD KEY `idx_student_appeal_student` (`student_id`),
  ADD KEY `idx_student_appeal_status` (`status`),
  ADD KEY `idx_student_appeal_case` (`case_id`),
  ADD KEY `idx_student_appeal_offense` (`offense_id`),
  ADD KEY `idx_student_appeal_created` (`created_at`);

--
-- Indexes for table `student_email_otp`
--
ALTER TABLE `student_email_otp`
  ADD PRIMARY KEY (`otp_id`),
  ADD KEY `idx_otp_email_time` (`email`,`created_at`),
  ADD KEY `idx_otp_expires` (`expires_at`),
  ADD KEY `fk_otp_student` (`student_id`);

--
-- Indexes for table `student_encrypted_backup`
--
ALTER TABLE `student_encrypted_backup`
  ADD PRIMARY KEY (`student_id`);

--
-- Indexes for table `system_config`
--
ALTER TABLE `system_config`
  ADD PRIMARY KEY (`config_key`);

--
-- Indexes for table `upcc_case`
--
ALTER TABLE `upcc_case`
  ADD PRIMARY KEY (`case_id`),
  ADD KEY `idx_case_student_status` (`student_id`,`status`),
  ADD KEY `idx_case_status` (`status`),
  ADD KEY `fk_case_created_by` (`created_by`),
  ADD KEY `fk_case_department` (`assigned_department_id`);

--
-- Indexes for table `upcc_case_activity`
--
ALTER TABLE `upcc_case_activity`
  ADD PRIMARY KEY (`activity_id`),
  ADD KEY `idx_case_activity_case` (`case_id`),
  ADD KEY `idx_case_activity_created` (`created_at`);

--
-- Indexes for table `upcc_case_discussion`
--
ALTER TABLE `upcc_case_discussion`
  ADD PRIMARY KEY (`message_id`),
  ADD KEY `idx_case_discussion_case` (`case_id`),
  ADD KEY `idx_case_discussion_panel` (`upcc_id`),
  ADD KEY `idx_case_discussion_admin` (`admin_id`);

--
-- Indexes for table `upcc_case_offense`
--
ALTER TABLE `upcc_case_offense`
  ADD PRIMARY KEY (`case_id`,`offense_id`),
  ADD KEY `idx_case_offense_offense` (`offense_id`);

--
-- Indexes for table `upcc_case_panel_acceptance`
--
ALTER TABLE `upcc_case_panel_acceptance`
  ADD PRIMARY KEY (`acceptance_id`),
  ADD UNIQUE KEY `uq_case_panel` (`case_id`,`upcc_id`);

--
-- Indexes for table `upcc_case_panel_member`
--
ALTER TABLE `upcc_case_panel_member`
  ADD PRIMARY KEY (`case_id`,`upcc_id`),
  ADD KEY `idx_upcc_panel_member` (`upcc_id`);

--
-- Indexes for table `upcc_case_vote`
--
ALTER TABLE `upcc_case_vote`
  ADD PRIMARY KEY (`case_id`,`upcc_id`,`round_no`),
  ADD KEY `idx_case_vote_case_round` (`case_id`,`round_no`),
  ADD KEY `idx_case_vote_member` (`upcc_id`);

--
-- Indexes for table `upcc_case_vote_round`
--
ALTER TABLE `upcc_case_vote_round`
  ADD PRIMARY KEY (`case_id`,`round_no`),
  ADD KEY `idx_vote_round_ends` (`ends_at`);

--
-- Indexes for table `upcc_hearing_presence`
--
ALTER TABLE `upcc_hearing_presence`
  ADD PRIMARY KEY (`session_id`),
  ADD UNIQUE KEY `uq_hearing_user` (`case_id`,`user_type`,`user_id`);

--
-- Indexes for table `upcc_panel_rejoin_requests`
--
ALTER TABLE `upcc_panel_rejoin_requests`
  ADD PRIMARY KEY (`request_id`),
  ADD KEY `idx_case_upcc` (`case_id`,`upcc_id`),
  ADD KEY `idx_requested_at` (`requested_at`);

--
-- Indexes for table `upcc_suggestion_cooldown`
--
ALTER TABLE `upcc_suggestion_cooldown`
  ADD PRIMARY KEY (`cooldown_id`),
  ADD KEY `idx_case_round_upcc` (`case_id`,`round_no`,`upcc_id`);

--
-- Indexes for table `upcc_user`
--
ALTER TABLE `upcc_user`
  ADD PRIMARY KEY (`upcc_id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `violation_letter`
--
ALTER TABLE `violation_letter`
  ADD PRIMARY KEY (`letter_id`),
  ADD KEY `idx_letter_student_time` (`student_id`,`generated_at`),
  ADD KEY `fk_letter_generated_by` (`generated_by`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin_user`
--
ALTER TABLE `admin_user`
  MODIFY `admin_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `audit_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `auth_session`
--
ALTER TABLE `auth_session`
  MODIFY `session_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=153;

--
-- AUTO_INCREMENT for table `community_service_requirement`
--
ALTER TABLE `community_service_requirement`
  MODIFY `requirement_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=56;

--
-- AUTO_INCREMENT for table `community_service_session`
--
ALTER TABLE `community_service_session`
  MODIFY `session_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=133;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `dept_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11800;

--
-- AUTO_INCREMENT for table `guardian`
--
ALTER TABLE `guardian`
  MODIFY `guardian_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=47;

--
-- AUTO_INCREMENT for table `guard_violation_report`
--
ALTER TABLE `guard_violation_report`
  MODIFY `report_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=100;

--
-- AUTO_INCREMENT for table `manual_login_request`
--
ALTER TABLE `manual_login_request`
  MODIFY `request_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=44;

--
-- AUTO_INCREMENT for table `notice_to_explain`
--
ALTER TABLE `notice_to_explain`
  MODIFY `nte_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `notification`
--
ALTER TABLE `notification`
  MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=292;

--
-- AUTO_INCREMENT for table `notification_log`
--
ALTER TABLE `notification_log`
  MODIFY `notification_id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `offense`
--
ALTER TABLE `offense`
  MODIFY `offense_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=146;

--
-- AUTO_INCREMENT for table `offense_type`
--
ALTER TABLE `offense_type`
  MODIFY `offense_type_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `security_guard`
--
ALTER TABLE `security_guard`
  MODIFY `guard_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `student_appeal_request`
--
ALTER TABLE `student_appeal_request`
  MODIFY `appeal_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `student_email_otp`
--
ALTER TABLE `student_email_otp`
  MODIFY `otp_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=193;

--
-- AUTO_INCREMENT for table `upcc_case`
--
ALTER TABLE `upcc_case`
  MODIFY `case_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=65;

--
-- AUTO_INCREMENT for table `upcc_case_activity`
--
ALTER TABLE `upcc_case_activity`
  MODIFY `activity_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=983;

--
-- AUTO_INCREMENT for table `upcc_case_discussion`
--
ALTER TABLE `upcc_case_discussion`
  MODIFY `message_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=603;

--
-- AUTO_INCREMENT for table `upcc_case_panel_acceptance`
--
ALTER TABLE `upcc_case_panel_acceptance`
  MODIFY `acceptance_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=58;

--
-- AUTO_INCREMENT for table `upcc_hearing_presence`
--
ALTER TABLE `upcc_hearing_presence`
  MODIFY `session_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=87438;

--
-- AUTO_INCREMENT for table `upcc_panel_rejoin_requests`
--
ALTER TABLE `upcc_panel_rejoin_requests`
  MODIFY `request_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=82;

--
-- AUTO_INCREMENT for table `upcc_suggestion_cooldown`
--
ALTER TABLE `upcc_suggestion_cooldown`
  MODIFY `cooldown_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `upcc_user`
--
ALTER TABLE `upcc_user`
  MODIFY `upcc_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `violation_letter`
--
ALTER TABLE `violation_letter`
  MODIFY `letter_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD CONSTRAINT `fk_audit_actor` FOREIGN KEY (`actor_admin_id`) REFERENCES `admin_user` (`admin_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `auth_session`
--
ALTER TABLE `auth_session`
  ADD CONSTRAINT `fk_session_admin` FOREIGN KEY (`admin_id`) REFERENCES `admin_user` (`admin_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_session_student` FOREIGN KEY (`student_id`) REFERENCES `student` (`student_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `community_service_requirement`
--
ALTER TABLE `community_service_requirement`
  ADD CONSTRAINT `fk_csr_assigned_by` FOREIGN KEY (`assigned_by`) REFERENCES `admin_user` (`admin_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_csr_case` FOREIGN KEY (`related_case_id`) REFERENCES `upcc_case` (`case_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_csr_student` FOREIGN KEY (`student_id`) REFERENCES `student` (`student_id`) ON UPDATE CASCADE;

--
-- Constraints for table `community_service_session`
--
ALTER TABLE `community_service_session`
  ADD CONSTRAINT `fk_css_requirement` FOREIGN KEY (`requirement_id`) REFERENCES `community_service_requirement` (`requirement_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_css_validated_by` FOREIGN KEY (`validated_by`) REFERENCES `admin_user` (`admin_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `guardian`
--
ALTER TABLE `guardian`
  ADD CONSTRAINT `fk_guardian_student` FOREIGN KEY (`student_id`) REFERENCES `student` (`student_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `guard_violation_report`
--
ALTER TABLE `guard_violation_report`
  ADD CONSTRAINT `fk_gvr_guard_submitted_by` FOREIGN KEY (`submitted_by`) REFERENCES `security_guard` (`guard_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_gvr_offense_type` FOREIGN KEY (`offense_type_id`) REFERENCES `offense_type` (`offense_type_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_gvr_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `admin_user` (`admin_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_gvr_student` FOREIGN KEY (`student_id`) REFERENCES `student` (`student_id`) ON UPDATE CASCADE;

--
-- Constraints for table `manual_login_request`
--
ALTER TABLE `manual_login_request`
  ADD CONSTRAINT `fk_mlr_decided_by` FOREIGN KEY (`decided_by`) REFERENCES `admin_user` (`admin_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_mlr_requirement` FOREIGN KEY (`requirement_id`) REFERENCES `community_service_requirement` (`requirement_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_mlr_student` FOREIGN KEY (`student_id`) REFERENCES `student` (`student_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `notification_log`
--
ALTER TABLE `notification_log`
  ADD CONSTRAINT `fk_notif_guardian` FOREIGN KEY (`guardian_id`) REFERENCES `guardian` (`guardian_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_notif_student` FOREIGN KEY (`student_id`) REFERENCES `student` (`student_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `offense`
--
ALTER TABLE `offense`
  ADD CONSTRAINT `fk_offense_admin` FOREIGN KEY (`recorded_by`) REFERENCES `admin_user` (`admin_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_offense_student` FOREIGN KEY (`student_id`) REFERENCES `student` (`student_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_offense_type` FOREIGN KEY (`offense_type_id`) REFERENCES `offense_type` (`offense_type_id`) ON UPDATE CASCADE;

--
-- Constraints for table `student_email_otp`
--
ALTER TABLE `student_email_otp`
  ADD CONSTRAINT `fk_otp_student` FOREIGN KEY (`student_id`) REFERENCES `student` (`student_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `upcc_case`
--
ALTER TABLE `upcc_case`
  ADD CONSTRAINT `fk_case_created_by` FOREIGN KEY (`created_by`) REFERENCES `admin_user` (`admin_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_case_department` FOREIGN KEY (`assigned_department_id`) REFERENCES `departments` (`dept_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_case_student` FOREIGN KEY (`student_id`) REFERENCES `student` (`student_id`) ON UPDATE CASCADE;

--
-- Constraints for table `upcc_case_offense`
--
ALTER TABLE `upcc_case_offense`
  ADD CONSTRAINT `fk_case_offense_case` FOREIGN KEY (`case_id`) REFERENCES `upcc_case` (`case_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_case_offense_offense` FOREIGN KEY (`offense_id`) REFERENCES `offense` (`offense_id`) ON UPDATE CASCADE;

--
-- Constraints for table `violation_letter`
--
ALTER TABLE `violation_letter`
  ADD CONSTRAINT `fk_letter_generated_by` FOREIGN KEY (`generated_by`) REFERENCES `admin_user` (`admin_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_letter_student` FOREIGN KEY (`student_id`) REFERENCES `student` (`student_id`) ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
