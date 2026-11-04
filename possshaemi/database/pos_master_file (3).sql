-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 23, 2026 at 02:24 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `pos_master_file`
--

-- --------------------------------------------------------

--
-- Table structure for table `tblactivity_log`
--

CREATE TABLE `tblactivity_log` (
  `activity_id` int(11) NOT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `actor_name` varchar(100) NOT NULL,
  `actor_role` varchar(50) NOT NULL,
  `module` varchar(50) NOT NULL,
  `action` varchar(30) NOT NULL,
  `outcome` varchar(20) NOT NULL DEFAULT 'Success',
  `record_id` int(11) DEFAULT NULL,
  `record_label` varchar(150) DEFAULT NULL,
  `details` varchar(255) DEFAULT NULL,
  `before_json` text DEFAULT NULL,
  `after_json` text DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `authorized_by` int(11) DEFAULT NULL,
  `authorized_role` varchar(50) DEFAULT NULL,
  `reference_label` varchar(100) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tblactivity_log`
--

INSERT INTO `tblactivity_log` (`activity_id`, `employee_id`, `actor_name`, `actor_role`, `module`, `action`, `outcome`, `record_id`, `record_label`, `details`, `before_json`, `after_json`, `reason`, `authorized_by`, `authorized_role`, `reference_label`, `ip_address`, `created_at`) VALUES
(1, 1, 'Maria Santos', 'Administrator', 'Employees', 'Updated', 'Success', 2, 'Juan Dela Cruz', 'Employee updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-01 09:12:17'),
(2, 1, 'Maria Santos', 'Administrator', 'Employees', 'Updated', 'Success', 4, 'Pedro Garcia', 'Employee updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-01 09:12:28'),
(3, 1, 'Maria Santos', 'Administrator', 'Roles', 'Updated', 'Success', 4, 'Cashier', 'Role updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-01 09:13:18'),
(4, 2, 'Juan Dela Cruz', 'Cashier', 'Sales', 'Created', 'Success', 1, 'OR-20261001-00001', 'Sale OR-20261001-00001 completed. Total ₱84.80.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-01 09:18:57'),
(5, 2, 'Juan Dela Cruz', 'Cashier', 'Sales', 'Created', 'Success', 2, 'OR-20261001-00002', 'Sale OR-20261001-00002 completed. Total ₱75.00.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-01 09:19:27'),
(6, 1, 'Maria Santos', 'Administrator', 'Counters', 'Updated', 'Success', 3, 'brix', 'Counter updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-01 09:46:54'),
(7, 2, 'Juan Dela Cruz', 'Cashier', 'Sales', 'Created', 'Success', 3, 'OR-20261001-00003', 'Sale OR-20261001-00003 completed. Total ₱33.00 (charged to AR).', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-01 09:51:34'),
(8, 2, 'Juan Dela Cruz', 'Cashier', 'Sales', 'Created', 'Success', 4, 'OR-20261002-00004', 'Sale OR-20261002-00004 completed. Total ₱116.00.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-02 01:03:12'),
(9, 2, 'Juan Dela Cruz', 'Cashier', 'Sales', 'Created', 'Success', 5, 'OR-20261002-00005', 'Sale OR-20261002-00005 completed. Total ₱836.00.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-02 01:05:22'),
(10, 2, 'Juan Dela Cruz', 'Cashier', 'Sales', 'Created', 'Success', 6, 'OR-20261002-00006', 'Sale OR-20261002-00006 completed. Total ₱675.00.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-02 07:48:09'),
(11, 2, 'Juan Dela Cruz', 'Cashier', 'Sales', 'Created', 'Success', 7, 'OR-20261002-00007', 'Sale OR-20261002-00007 completed. Total ₱116.00 (charged to AR).', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-02 07:49:06'),
(12, 2, 'Juan Dela Cruz', 'Cashier', 'Sales', 'Created', 'Success', 8, 'OR-20261002-00008', 'Sale OR-20261002-00008 completed. Total ₱371.00.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-02 14:08:17'),
(13, 2, 'Juan Dela Cruz', 'Cashier', 'Sales', 'Deactivated', 'Success', NULL, NULL, 'Held transaction removed.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-02 14:11:00'),
(14, 1, 'Maria Santos', 'Administrator', 'Payment Types', 'Deactivated', 'Success', 3, 'Credit Card', 'Payment type deactivated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-03 15:03:45'),
(15, 1, 'Maria Santos', 'Administrator', 'Payment Types', 'Deactivated', 'Success', 4, 'Debit Card', 'Payment type deactivated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-03 15:03:47'),
(16, 1, 'Maria Santos', 'Administrator', 'Suki Cards', 'Updated', 'Success', 2, 'SUKI-0002', 'Suki card updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-03 15:05:51'),
(17, 2, 'Juan Dela Cruz', 'Cashier', 'Sales', 'Created', 'Success', 9, 'OR-20261004-00009', 'Sale OR-20261004-00009 completed. Total ₱280.00. Used 200 Suki Point(s).', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-04 03:36:52'),
(18, 1, 'Maria Santos', 'Administrator', 'Employees', 'Created', 'Success', 7, 'Junil Salvatiera', 'Employee added successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-04 03:38:46'),
(19, 1, 'Maria Santos', 'Administrator', 'Employees', 'Updated', 'Success', 7, 'Junil Salvatiera', 'Employee updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-05 14:39:38'),
(20, 1, 'Maria Santos', 'Administrator', 'Employees', 'Updated', 'Success', 7, 'Junil Salvatiera', 'Employee updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-05 14:51:53'),
(21, 1, 'Maria Santos', 'Administrator', 'Price Tiers', 'Updated', 'Success', 3, 'Retailer', 'Price tier updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-05 15:25:16'),
(22, 1, 'Maria Santos', 'Administrator', 'Employees', 'Updated', 'Success', 4, 'Pedro Garcia', 'Employee updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-08 01:50:02'),
(23, 1, 'Maria Santos', 'Administrator', 'Products', 'Updated', 'Success', 8, 'Argentina Corned Beef', 'Product updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-08 01:51:06'),
(24, 1, 'Maria Santos', 'Administrator', 'Products', 'Updated', 'Success', 12, 'Bear Brand Powdered Milk', 'Product updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-08 01:51:10'),
(25, 1, 'Maria Santos', 'Administrator', 'Products', 'Updated', 'Success', 7, 'Century Tuna Flakes', 'Product updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-08 01:51:18'),
(26, 1, 'Maria Santos', 'Administrator', 'Products', 'Updated', 'Success', 7, 'Century Tuna Flakes', 'Product updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-08 01:51:23'),
(27, 1, 'Maria Santos', 'Administrator', 'Products', 'Updated', 'Success', 1, 'Coca-Cola 1.5L', 'Product updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-08 01:51:28'),
(28, 2, 'Juan Dela Cruz', 'Cashier', 'Sales', 'Created', 'Success', 10, 'OR-20261008-00010', 'Sale OR-20261008-00010 completed. Total ₱570.00.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-08 02:05:41'),
(29, 2, 'Juan Dela Cruz', 'Cashier', 'Sales', 'Deactivated', 'Success', NULL, NULL, 'Held transaction removed.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-09 10:53:20'),
(30, 2, 'Juan Dela Cruz', 'Cashier', 'Sales', 'Deactivated', 'Success', NULL, NULL, 'Held transaction removed.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-09 10:53:25'),
(31, 2, 'Juan Dela Cruz', 'Cashier', 'Sales', 'Deactivated', 'Success', NULL, NULL, 'Held transaction removed.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-09 11:02:25'),
(32, 2, 'Juan Dela Cruz', 'Cashier', 'Sales', 'Created', 'Success', 11, 'OR-20261009-00011', 'Sale OR-20261009-00011 completed. Total ₱144.00.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-09 11:04:06'),
(33, 2, 'Juan Dela Cruz', 'Cashier', 'Sales', 'Created', 'Success', 12, 'OR-20261009-00012', 'Sale OR-20261009-00012 completed and charged to AR.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-09 11:05:27'),
(34, 4, 'Pedro Garcia', 'Manager', 'AR Payments', 'Recorded', 'Success', 13, 'Payment #13 - Elena Bautista', 'Payment of ₱446.00 recorded.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-11 13:01:06'),
(35, 4, 'Pedro Garcia', 'Manager', 'AR Payments', 'Reversed', 'Success', 13, 'Payment #13 - Elena Bautista', 'Payment reversed (₱446.00 returned to balance). Approved by Maria Santos. Reason: I run a test', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-11 13:06:48'),
(36, 2, 'Juan Dela Cruz', 'Cashier', 'Sales', 'Created', 'Success', 13, 'OR-20261011-00013', 'Sale OR-20261011-00013 completed and charged to AR.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-11 13:30:52'),
(37, 4, 'Pedro Garcia', 'Manager', 'AR Payments', 'Recorded', 'Success', 14, 'Payment #14 - Carlos Mendoza', 'Payment of ₱33.00 recorded.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-11 13:43:34'),
(38, 1, 'Maria Santos', 'Administrator', 'Counters', 'Updated', 'Success', 3, 'W', 'Counter updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-13 02:41:49'),
(39, 1, 'Maria Santos', 'Administrator', 'Counters', 'Updated', 'Success', 3, 'Wholesale', 'Counter updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-13 02:41:57'),
(40, 2, 'Juan Dela Cruz', 'Cashier', 'Sales', 'Deactivated', 'Success', NULL, NULL, 'Held transaction removed.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-13 12:19:42'),
(41, 1, 'Maria Santos', 'Administrator', 'Products', 'Created', 'Success', 14, 'Argentina Corned Beef - 1 Box(27)', 'Product and price tiers added successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-13 12:27:42'),
(42, 1, 'Maria Santos', 'Administrator', 'Roles', 'Updated', 'Success', 4, 'Clerk', 'Role updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-13 12:37:55'),
(43, 1, 'Maria Santos', 'Administrator', 'Employees', 'Created', 'Success', 8, 'Kent Bonita', 'Employee added successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-16 03:27:16'),
(44, 1, 'Maria Santos', 'Administrator', 'Employees', 'Updated', 'Success', 8, 'Kent Bonita', 'Employee updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-16 03:27:34'),
(45, 1, 'Maria Santos', 'Administrator', 'Employees', 'Updated', 'Success', 8, 'Kent Bonita', 'Employee updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-16 03:28:14'),
(46, 1, 'Maria Santos', 'Administrator', 'AR Accounts', 'Updated', 'Success', 3, 'AR #3 - Miguel Torres', 'AR account updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-16 06:11:23'),
(47, 1, 'Maria Santos', 'Administrator', 'AR Accounts', 'Updated', 'Success', 3, 'AR #3 - Miguel Torres', 'AR account updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-16 06:11:29'),
(48, 2, 'Juan Dela Cruz', 'Cashier', 'Sales', 'Deactivated', 'Success', NULL, NULL, 'Held transaction removed.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-16 08:22:13'),
(49, 2, 'Juan Dela Cruz', 'Cashier', 'Sales', 'Created', 'Success', 14, 'OR-20261016-00014', 'Sale OR-20261016-00014 completed. Total ₱1,742.00.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-16 08:22:53'),
(50, 2, 'Juan Dela Cruz', 'Cashier', 'Sales', 'Created', 'Success', 15, 'OR-20261016-00015', 'Sale OR-20261016-00015 completed and charged to AR.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-16 08:24:42'),
(51, 1, 'Maria Santos', 'Administrator', 'Products', 'Updated', 'Success', 10, 'Colgate Toothpaste', 'Product updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-17 12:24:50'),
(52, 1, 'Maria Santos', 'Administrator', 'Products', 'Updated', 'Success', 11, 'Lucky Me Pancit Canton', 'Product updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-17 12:24:54'),
(53, 1, 'Maria Santos', 'Administrator', 'Products', 'Updated', 'Success', 4, 'Nescafe 3-in-1', 'Product updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-17 12:25:05'),
(54, 1, 'Maria Santos', 'Administrator', 'Products', 'Updated', 'Success', 6, 'Nova Multigrain', 'Product updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-17 12:25:09'),
(55, 1, 'Maria Santos', 'Administrator', 'Products', 'Updated', 'Success', 2, 'Pepsi 1.5L', 'Product updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-17 12:25:13'),
(56, 1, 'Maria Santos', 'Administrator', 'Products', 'Updated', 'Success', 5, 'Piattos Cheese', 'Product updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-17 12:25:17'),
(57, 2, 'Juan Dela Cruz', 'Cashier', 'Sales', 'Deactivated', 'Success', NULL, NULL, 'Held transaction removed.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-17 12:31:37'),
(58, 1, 'Maria Santos', 'Administrator', 'Payment Types', 'Restored', 'Success', 5, 'Store Credit', 'Payment type restored successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-17 12:54:29'),
(59, 2, 'Juan Dela Cruz', 'Cashier', 'Sales', 'Created', 'Success', 16, 'OR-20261017-00016', 'Sale OR-20261017-00016 completed. Total ₱2,244.00.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-17 16:38:40'),
(60, 2, 'Juan Dela Cruz', 'Cashier', 'Sales', 'Deactivated', 'Success', NULL, NULL, 'Held transaction removed.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-17 17:14:56'),
(61, 2, 'Juan Dela Cruz', 'Cashier', 'Sales', 'Created', 'Success', 17, 'OR-20261017-00017', 'Sale OR-20261017-00017 completed. Total ₱2,750.00.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-17 17:52:45'),
(62, 8, 'Kent Bonita', 'Cashier', 'Sales', 'Created', 'Success', 18, 'OR-20261017-00018', 'Sale OR-20261017-00018 completed. Total ₱3,850.00.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-17 18:09:24'),
(63, 8, 'Kent Bonita', 'Cashier', 'Sales', 'Created', 'Success', 19, 'OR-20261017-00019', 'Sale OR-20261017-00019 completed. Total ₱1,760.00.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-17 18:15:02'),
(64, 2, 'Juan Dela Cruz', 'Cashier', 'Sales', 'Created', 'Success', 20, 'OR-20261018-00020', 'Sale OR-20261018-00020 completed. Total ₱1,575.00.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-18 11:59:40'),
(65, 1, 'Maria Santos', 'Administrator', 'Employees', 'Created', 'Success', 9, 'Krystal Morales', 'Employee added successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-18 12:03:03'),
(66, 1, 'Maria Santos', 'Administrator', 'AR Accounts', 'Updated', 'Success', 3, 'AR #3 - Miguel Torres', 'AR account updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-18 12:29:01'),
(67, 1, 'Maria Santos', 'Administrator', 'Payment Types', 'Deactivated', 'Success', 5, 'Store Credit', 'Payment type deactivated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-18 12:54:29'),
(68, 1, 'Maria Santos', 'Administrator', 'Employees', 'Deactivated', 'Success', 5, 'Liza Fernandez', 'Employee deactivated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-18 12:54:49'),
(69, 1, 'Maria Santos', 'Administrator', 'GC Codes', 'Created', 'Success', NULL, NULL, '10 gift certificate code(s) generated.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-18 16:06:50'),
(70, 1, 'Maria Santos', 'Administrator', 'Roles', 'Deactivated', 'Success', 4, 'Clerk', 'Role deactivated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-18 16:14:22'),
(71, 1, 'Maria Santos', 'Administrator', 'Employees', 'Updated', 'Success', 8, 'Kent Bonita', 'Employee updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-21 02:32:43'),
(72, 1, 'Maria Santos', 'Administrator', 'Employees', 'Updated', 'Success', 8, 'Kent Bonita', 'Employee updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-21 02:35:17'),
(73, 1, 'Maria Santos', 'Administrator', 'Employees', 'Updated', 'Success', 2, 'Juan Dela Cruz', 'Employee updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-21 02:35:31'),
(74, 1, 'Maria Santos', 'Administrator', 'Employees', 'Updated', 'Success', 4, 'Pedro Garcia', 'Employee updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-21 02:35:42'),
(75, 1, 'Maria Santos', 'Administrator', 'Employees', 'Updated', 'Success', 9, 'Krystal Morales', 'Employee updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-21 02:35:57'),
(76, 1, 'Maria Santos', 'Administrator', 'Employees', 'Updated', 'Success', 3, 'Ana Reyes', 'Employee updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-21 02:36:07'),
(77, 1, 'Maria Santos', 'Administrator', 'Employees', 'Updated', 'Success', 7, 'Junil Salvatiera', 'Employee updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-21 02:36:17'),
(78, 1, 'Maria Santos', 'Administrator', 'Employees', 'Updated', 'Success', 1, 'Maria Santos', 'Employee updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-21 02:36:29'),
(79, 8, 'Kent Bonita', 'Cashier', 'Sales', 'Created', 'Success', 21, 'OR-20261021-00021', 'Sale OR-20261021-00021 completed. Total ₱370.00.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-21 07:50:07'),
(80, 8, 'Kent Bonita', 'Cashier', 'Sales', 'Created', 'Success', 22, 'OR-20261021-00022', 'Sale OR-20261021-00022 completed and charged to AR.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-21 07:53:00'),
(81, 8, 'Kent Bonita', 'Cashier', 'Sales', 'Created', 'Success', 23, 'OR-20261021-00023', 'Sale OR-20261021-00023 completed and charged to AR.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-21 07:53:59'),
(82, 2, 'Juan Dela Cruz', 'Cashier', 'Sales', 'Created', 'Success', 24, 'OR-20261021-00024', 'Sale OR-20261021-00024 completed. Total ₱760.00.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-21 14:09:46'),
(83, 1, 'Maria Santos', 'Administrator', 'Products', 'Updated', 'Success', 14, 'Argentina Corned Beef - 1 Box(27)', 'Product updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-21 17:07:41'),
(84, 1, 'Maria Santos', 'Administrator', 'Products', 'Updated', 'Success', 1, 'Coca-Cola 1.5L', 'Product updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-21 17:35:58'),
(85, 1, 'Maria Santos', 'Administrator', 'Products', 'Updated', 'Success', 8, 'Argentina Corned Beef', 'Product and price tiers updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-21 17:40:30'),
(86, 1, 'Maria Santos', 'Administrator', 'Products', 'Updated', 'Success', 2, 'Pepsi 1.5L', 'Product and price tiers updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-21 17:40:53'),
(87, 1, 'Maria Santos', 'Administrator', 'Products', 'Updated', 'Success', 14, 'Argentina Corned Beef - 1 Box(27)', 'Product and price tiers updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-21 17:41:18'),
(88, 1, 'Maria Santos', 'Administrator', 'Categories', 'Created', 'Success', 7, 'Dairy', 'Category added successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-21 17:44:24'),
(89, 1, 'Maria Santos', 'Administrator', 'Categories', 'Created', 'Success', 8, 'Powdered Milk', 'Category added successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-21 17:45:13'),
(90, 1, 'Maria Santos', 'Administrator', 'Products', 'Updated', 'Success', 12, 'Bear Brand Powdered Milk', 'Product and price tiers updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-21 17:45:36'),
(91, 1, 'Maria Santos', 'Administrator', 'Products', 'Updated', 'Success', 7, 'Century Tuna Flakes', 'Product and price tiers updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-21 17:46:08'),
(92, 1, 'Maria Santos', 'Administrator', 'Products', 'Updated', 'Success', 10, 'Colgate Toothpaste', 'Product and price tiers updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-21 17:46:24'),
(93, 1, 'Maria Santos', 'Administrator', 'Products', 'Updated', 'Success', 11, 'Lucky Me Pancit Canton', 'Product and price tiers updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-21 17:46:46'),
(94, 1, 'Maria Santos', 'Administrator', 'Products', 'Updated', 'Success', 4, 'Nescafe 3-in-1', 'Product and price tiers updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-21 17:49:02'),
(95, 1, 'Maria Santos', 'Administrator', 'Products', 'Updated', 'Success', 6, 'Nova Multigrain', 'Product and price tiers updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-21 17:49:38'),
(96, 1, 'Maria Santos', 'Administrator', 'Products', 'Updated', 'Success', 5, 'Piattos Cheese', 'Product and price tiers updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-21 17:49:46'),
(97, 1, 'Maria Santos', 'Administrator', 'Products', 'Updated', 'Success', 9, 'Safeguard Soap', 'Product and price tiers updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-21 17:50:02'),
(98, 1, 'Maria Santos', 'Administrator', 'Products', 'Updated', 'Success', 2, 'Pepsi 1.5L', 'Product and price tiers updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-21 17:50:56'),
(99, 1, 'Maria Santos', 'Administrator', 'Products', 'Updated', 'Success', 3, 'Sprite 1.5L', 'Product and price tiers updated successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-21 17:51:05'),
(100, 1, 'Maria Santos', 'Administrator', 'Products', 'Created', 'Success', 15, 'Coca-Cola 1.5L - Case of 12', 'Product and price tiers added successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-21 17:53:13'),
(101, 1, 'Maria Santos', 'Administrator', 'Products', 'Created', 'Success', 16, 'Lucky Me Pancit Canton - Box of 24', 'Product and price tiers added successfully.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-21 17:54:35'),
(102, 8, 'Kent Bonita', 'Cashier', 'Sales', 'Created', 'Success', 25, 'OR-20261021-00025', 'Sale OR-20261021-00025 completed. Total ₱445.00.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-21 18:01:48'),
(103, 8, 'Kent Bonita', 'Cashier', 'Sales', 'Created', 'Success', 26, 'OR-20261021-00026', 'Sale OR-20261021-00026 completed. Total ₱741.00.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-21 18:02:50'),
(104, 8, 'Kent Bonita', 'Cashier', 'Sales', 'Created', 'Success', 27, 'OR-20261021-00027', 'Sale OR-20261021-00027 completed. Total ₱2,125.00.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-21 18:18:19'),
(105, 8, 'Kent Bonita', 'Cashier', 'Sales', 'Created', 'Success', 28, 'OR-20261022-00028', 'Sale OR-20261022-00028 completed. Total ₱576.00.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-22 14:23:46'),
(106, 8, 'Kent Bonita', 'Cashier', 'Sales', 'Created', 'Success', 29, 'OR-20261022-00029', 'Sale OR-20261022-00029 completed. Total ₱997.00.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-22 14:42:47'),
(107, 1, 'Maria Santos', 'Administrator', 'Suki Cards', 'Updated', 'Success', 3, 'SUKI-0003', 'Suki Points updated from 120 to 100.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-22 15:32:41'),
(108, 1, 'Maria Santos', 'Administrator', 'Security', 'Logout', 'Success', NULL, NULL, 'Logged out.', NULL, NULL, NULL, NULL, NULL, NULL, '::1', '2026-10-22 16:22:19'),
(109, 4, 'Pedro Garcia', 'Manager', 'Security', 'Login', 'Success', NULL, NULL, 'Login successful.', NULL, NULL, NULL, NULL, NULL, NULL, '::1', '2026-10-22 16:22:25'),
(110, 4, 'Pedro Garcia', 'Manager', 'Security', 'Logout', 'Success', NULL, NULL, 'Logged out.', NULL, NULL, NULL, NULL, NULL, NULL, '::1', '2026-10-22 16:27:39'),
(111, 1, 'Maria Santos', 'Administrator', 'Security', 'Login', 'Success', NULL, NULL, 'Login successful.', NULL, NULL, NULL, NULL, NULL, NULL, '::1', '2026-10-22 16:27:46'),
(112, 1, 'Maria Santos', 'Administrator', 'Product Prices', 'Updated', 'Success', 8, 'Argentina Corned Beef', 'Product tier prices updated.', '{\"tier_prices\":{\"1\":55,\"2\":48,\"3\":50}}', '{\"tier_prices\":{\"1\":53,\"2\":48,\"3\":50}}', NULL, NULL, NULL, 'Argentina Corned Beef', '::1', '2026-10-22 16:30:09'),
(113, 1, 'Maria Santos', 'Administrator', 'Security', 'Logout', 'Success', NULL, NULL, 'Logged out.', NULL, NULL, NULL, NULL, NULL, NULL, '::1', '2026-10-22 16:51:33'),
(114, 4, 'Pedro Garcia', 'Manager', 'Security', 'Login', 'Success', NULL, NULL, 'Login successful.', NULL, NULL, NULL, NULL, NULL, NULL, '::1', '2026-10-22 16:51:38'),
(115, 4, 'Pedro Garcia', 'Manager', 'AR Payments', 'Recorded', 'Success', 29, 'payment #29', 'Recorded payment #29 (AR account #1)', '{\"balance\":10024}', '{\"balance\":9908,\"amount\":116,\"method\":\"Cash\",\"reference\":\"\",\"allocations\":[{\"invoice_id\":7,\"amount\":116}]}', NULL, NULL, NULL, 'AR Audit #4', '::1', '2026-10-22 17:09:14'),
(116, 2, 'Juan Dela Cruz', 'Cashier', 'Security', 'LoginFailed', 'Failed', NULL, 'jdelacruz', 'Failed login attempt.', NULL, NULL, 'Wrong password', NULL, NULL, NULL, '::1', '2026-10-23 00:23:47'),
(117, 2, 'Juan Dela Cruz', 'Cashier', 'Security', 'Login', 'Success', NULL, NULL, 'Login successful.', NULL, NULL, NULL, NULL, NULL, NULL, '::1', '2026-10-23 00:24:01'),
(118, 2, 'Juan Dela Cruz', 'Cashier', 'Security', 'Logout', 'Success', NULL, NULL, 'Logged out.', NULL, NULL, NULL, NULL, NULL, NULL, '::1', '2026-10-23 00:24:13');

-- --------------------------------------------------------

--
-- Table structure for table `tblar_account`
--

CREATE TABLE `tblar_account` (
  `ar_account_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `approved_by` int(11) NOT NULL,
  `credit_term_id` int(11) NOT NULL,
  `credit_limit` decimal(12,2) NOT NULL DEFAULT 0.00,
  `current_balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `account_status` varchar(20) NOT NULL DEFAULT 'Active',
  `date_opened` date NOT NULL,
  `credit_status` varchar(20) NOT NULL DEFAULT 'Good',
  `credit_hold_source` varchar(20) DEFAULT NULL,
  `credit_hold_at` datetime DEFAULT NULL,
  `credit_hold_invoice_id` int(11) DEFAULT NULL,
  `credit_hold_released_at` datetime DEFAULT NULL,
  `credit_hold_released_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tblar_account`
--

INSERT INTO `tblar_account` (`ar_account_id`, `customer_id`, `approved_by`, `credit_term_id`, `credit_limit`, `current_balance`, `account_status`, `date_opened`, `credit_status`, `credit_hold_source`, `credit_hold_at`, `credit_hold_invoice_id`, `credit_hold_released_at`, `credit_hold_released_by`) VALUES
(1, 2, 1, 3, 50000.00, 9908.00, 'Active', '2025-01-15', 'Good', NULL, NULL, NULL, NULL, NULL),
(2, 3, 1, 2, 20000.00, 6226.00, 'Active', '2025-03-10', 'Good', NULL, NULL, NULL, NULL, NULL),
(3, 4, 1, 2, 10000.00, 0.00, 'Pending', '2026-10-01', 'Good', NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `tblar_audit`
--

CREATE TABLE `tblar_audit` (
  `audit_id` int(11) NOT NULL,
  `entity_type` varchar(30) NOT NULL,
  `entity_id` int(11) NOT NULL,
  `action` varchar(30) NOT NULL,
  `ar_account_id` int(11) DEFAULT NULL,
  `before_json` text DEFAULT NULL,
  `after_json` text DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `employee_id` int(11) NOT NULL,
  `authorized_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tblar_audit`
--

INSERT INTO `tblar_audit` (`audit_id`, `entity_type`, `entity_id`, `action`, `ar_account_id`, `before_json`, `after_json`, `reason`, `employee_id`, `authorized_by`, `created_at`) VALUES
(1, 'payment', 13, 'Recorded', 2, '{\"balance\":3446}', '{\"balance\":3000,\"amount\":446,\"method\":\"Cash\",\"reference\":\"\",\"allocations\":[{\"invoice_id\":12,\"amount\":446}]}', NULL, 4, NULL, '2026-10-11 21:01:06'),
(2, 'reversal', 1, 'Reversed', 2, '{\"balance\":3000,\"payment_status\":\"Posted\",\"applied\":446}', '{\"balance\":3446,\"payment_status\":\"Reversed\",\"applied\":0,\"taken_back\":[{\"invoice_id\":12,\"amount\":446}]}', 'I run a test', 4, 1, '2026-10-11 21:06:48'),
(3, 'payment', 14, 'Recorded', 1, '{\"balance\":937}', '{\"balance\":904,\"amount\":33,\"method\":\"Cash\",\"reference\":\"\",\"allocations\":[{\"invoice_id\":3,\"amount\":33}]}', NULL, 4, NULL, '2026-10-11 21:43:34'),
(4, 'payment', 29, 'Recorded', 1, '{\"balance\":10024}', '{\"balance\":9908,\"amount\":116,\"method\":\"Cash\",\"reference\":\"\",\"allocations\":[{\"invoice_id\":7,\"amount\":116}]}', NULL, 4, NULL, '2026-10-23 01:09:14');

-- --------------------------------------------------------

--
-- Table structure for table `tblcashier_shift`
--

CREATE TABLE `tblcashier_shift` (
  `shift_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `counter_id` int(11) NOT NULL,
  `shift_date` date NOT NULL,
  `opened_at` datetime NOT NULL,
  `closed_at` datetime DEFAULT NULL,
  `opening_cash` decimal(12,2) NOT NULL DEFAULT 0.00,
  `expected_cash` decimal(12,2) DEFAULT NULL,
  `actual_cash` decimal(12,2) DEFAULT NULL,
  `cash_difference` decimal(12,2) DEFAULT NULL,
  `closing_notes` varchar(255) DEFAULT NULL,
  `shift_status` enum('Open','Closed') NOT NULL DEFAULT 'Open',
  `closed_by` int(11) DEFAULT NULL,
  `authorized_by` int(11) DEFAULT NULL,
  `deposit_bag_no` varchar(50) DEFAULT NULL,
  `deposit_amount` decimal(12,2) DEFAULT NULL,
  `deposit_verified_amount` decimal(12,2) DEFAULT NULL,
  `deposit_difference` decimal(12,2) DEFAULT NULL,
  `deposit_verified_by` int(11) DEFAULT NULL,
  `deposit_verified_at` datetime DEFAULT NULL,
  `active_counter_key` int(11) GENERATED ALWAYS AS (case when `shift_status` = 'Open' then `counter_id` else NULL end) STORED,
  `active_employee_key` int(11) GENERATED ALWAYS AS (case when `shift_status` = 'Open' then `employee_id` else NULL end) STORED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tblcashier_shift`
--

INSERT INTO `tblcashier_shift` (`shift_id`, `employee_id`, `counter_id`, `shift_date`, `opened_at`, `closed_at`, `opening_cash`, `expected_cash`, `actual_cash`, `cash_difference`, `closing_notes`, `shift_status`, `closed_by`, `authorized_by`, `deposit_bag_no`, `deposit_amount`, `deposit_verified_amount`, `deposit_difference`, `deposit_verified_by`, `deposit_verified_at`) VALUES
(1, 8, 1, '2026-10-18', '2026-10-18 02:07:42', '2026-10-18 02:27:01', 500.00, 0.00, 0.00, 0.00, NULL, 'Closed', 8, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(2, 9, 1, '2026-10-18', '2026-10-18 20:03:53', '2026-10-21 12:33:27', 500.00, NULL, NULL, NULL, NULL, 'Closed', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(3, 2, 1, '2026-10-21', '2026-10-21 10:37:16', '2026-10-22 01:56:02', 500.00, 1260.00, 1260.00, 0.00, NULL, 'Closed', 2, 7, 'OR-1130', 1260.00, 1260.00, 0.00, 7, '2026-10-22 01:56:02'),
(4, 8, 2, '2026-10-21', '2026-10-21 15:39:12', '2026-10-21 16:02:25', 500.00, 870.00, 860.00, -10.00, 'Missed calculated change.', 'Closed', 8, 7, 'OR-1113', 860.00, 860.00, 0.00, 7, '2026-10-21 16:02:25'),
(5, 8, 2, '2026-10-22', '2026-10-22 01:59:11', '2026-10-22 02:03:55', 500.00, 1686.00, 1686.00, 0.00, NULL, 'Closed', 8, 7, 'OR-2134', 1686.00, 1686.00, 0.00, 7, '2026-10-22 02:03:55'),
(6, 8, 2, '2026-10-22', '2026-10-22 02:17:11', '2026-10-22 02:19:47', 500.00, 2625.00, 2625.00, 0.00, NULL, 'Closed', 8, 7, 'OR-1899', 2625.00, 2625.00, 0.00, 7, '2026-10-22 02:19:47'),
(7, 8, 1, '2026-10-22', '2026-10-22 21:45:03', '2026-10-22 22:44:01', 500.00, 1573.00, 1573.00, 0.00, NULL, 'Closed', 8, 1, 'OR-3242', 1573.00, 1573.00, 0.00, 1, '2026-10-22 22:44:01');

-- --------------------------------------------------------

--
-- Table structure for table `tblcash_float`
--

CREATE TABLE `tblcash_float` (
  `float_id` int(11) NOT NULL,
  `counter_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `shift_id` int(11) DEFAULT NULL,
  `float_type` enum('Beginning','Lift') NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `supervisor_id` int(11) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tblcash_float`
--

INSERT INTO `tblcash_float` (`float_id`, `counter_id`, `employee_id`, `shift_id`, `float_type`, `amount`, `supervisor_id`, `notes`, `created_at`) VALUES
(1, 1, 2, NULL, 'Beginning', 500.00, NULL, NULL, '2026-10-04 03:42:20'),
(2, 1, 2, NULL, 'Beginning', 500.00, NULL, NULL, '2026-10-05 14:59:31'),
(3, 1, 2, NULL, 'Lift', 500.00, 1, 'Cash lift', '2026-10-05 15:04:22'),
(4, 1, 2, NULL, 'Beginning', 500.00, NULL, NULL, '2026-10-08 02:02:06'),
(5, 1, 2, NULL, 'Lift', 100.00, 1, 'Cash lift', '2026-10-08 02:06:10'),
(6, 1, 2, NULL, 'Beginning', 1000.00, NULL, NULL, '2026-10-09 10:54:23'),
(7, 1, 2, NULL, 'Lift', 500.00, 1, 'Cash lift', '2026-10-09 11:01:56'),
(8, 1, 2, NULL, 'Beginning', 500.00, NULL, NULL, '2026-10-11 13:30:41'),
(9, 1, 2, NULL, 'Beginning', 500.00, NULL, NULL, '2026-10-13 12:19:53'),
(10, 1, 2, NULL, 'Lift', 500.00, 1, 'Cash lift', '2026-10-13 12:20:27'),
(11, 1, 2, NULL, 'Beginning', 500.00, NULL, NULL, '2026-10-17 12:09:30'),
(12, 1, 2, NULL, 'Beginning', 500.00, NULL, NULL, '2026-10-17 16:12:48'),
(13, 1, 2, NULL, 'Lift', 2744.00, 7, 'Cash lift', '2026-10-17 17:50:14'),
(14, 1, 2, NULL, 'Lift', 2750.00, 7, 'Cash lift', '2026-10-17 17:54:15'),
(15, 1, 8, NULL, 'Beginning', 500.00, NULL, NULL, '2026-10-17 18:07:42'),
(16, 1, 8, NULL, 'Lift', 6110.00, 7, 'Cash lift', '2026-10-17 18:26:41'),
(17, 1, 2, NULL, 'Lift', 1575.00, 7, 'Cash lift', '2026-10-18 12:00:55'),
(18, 1, 9, NULL, 'Beginning', 500.00, NULL, NULL, '2026-10-18 12:03:53'),
(19, 1, 2, NULL, 'Beginning', 500.00, NULL, NULL, '2026-10-21 02:37:16'),
(20, 2, 8, 4, 'Beginning', 500.00, NULL, NULL, '2026-10-21 07:39:12'),
(21, 2, 8, 5, 'Beginning', 500.00, NULL, NULL, '2026-10-21 17:59:11'),
(22, 2, 8, 6, 'Beginning', 500.00, NULL, NULL, '2026-10-21 18:17:11'),
(23, 1, 8, 7, 'Beginning', 500.00, NULL, NULL, '2026-10-22 13:45:03');

-- --------------------------------------------------------

--
-- Table structure for table `tblcounter`
--

CREATE TABLE `tblcounter` (
  `counter_id` int(11) NOT NULL,
  `counter_name` varchar(50) NOT NULL,
  `counter_number` int(11) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Closed',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_date` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tblcounter`
--

INSERT INTO `tblcounter` (`counter_id`, `counter_name`, `counter_number`, `status`, `is_active`, `created_date`) VALUES
(1, 'Main Counter', 1, 'Open', 1, '2026-10-01 08:49:20'),
(2, 'Express Lane', 2, 'Open', 1, '2026-10-01 08:49:20'),
(3, 'Wholesale', 3, 'Open', 1, '2026-10-01 08:49:20'),
(4, 'Old Counter', 4, 'Closed', 0, '2026-10-01 08:49:20');

-- --------------------------------------------------------

--
-- Table structure for table `tblcredit_memo`
--

CREATE TABLE `tblcredit_memo` (
  `credit_memo_id` int(11) NOT NULL,
  `reference_no` varchar(30) DEFAULT NULL,
  `invoice_id` int(11) NOT NULL,
  `category` varchar(50) DEFAULT NULL,
  `memo_date` datetime NOT NULL DEFAULT current_timestamp(),
  `amount` decimal(12,2) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tblcredit_term`
--

CREATE TABLE `tblcredit_term` (
  `credit_term_id` int(11) NOT NULL,
  `term_name` varchar(50) NOT NULL,
  `due_days` int(11) NOT NULL DEFAULT 30,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tblcredit_term`
--

INSERT INTO `tblcredit_term` (`credit_term_id`, `term_name`, `due_days`, `is_active`) VALUES
(1, 'COD', 0, 1),
(2, 'Net 15', 15, 1),
(3, 'Net 30', 30, 1),
(4, 'Net 60', 60, 1),
(5, 'Discontinued Term', 45, 0);

-- --------------------------------------------------------

--
-- Table structure for table `tblcustomer`
--

CREATE TABLE `tblcustomer` (
  `customer_id` int(11) NOT NULL,
  `price_tier_id` int(11) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `street` varchar(100) DEFAULT NULL,
  `barangay` varchar(100) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `province` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `customer_type` varchar(30) DEFAULT 'Regular',
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tblcustomer`
--

INSERT INTO `tblcustomer` (`customer_id`, `price_tier_id`, `first_name`, `last_name`, `contact_number`, `street`, `barangay`, `city`, `province`, `email`, `customer_type`, `is_active`) VALUES
(1, 1, 'Rosa', 'Villanueva', '09171112222', '123 Mabini St.', 'Carmen', 'Cagayan de Oro', 'Misamis Oriental', 'rosa.villanueva@email.com', 'Regular', 1),
(2, 2, 'Carlos', 'Mendoza', '09182223333', '45 Rizal Ave.', 'Nazareth', 'Cagayan de Oro', 'Misamis Oriental', 'carlos.mendoza@email.com', 'Wholesale', 1),
(3, 3, 'Elena', 'Bautista', '09193334444', '78 Corrales Ave.', 'Lapasan', 'Cagayan de Oro', 'Misamis Oriental', 'elena.bautista@email.com', 'Retailer', 1),
(4, 1, 'Miguel', 'Torres', '09204445555', '12 Velez St.', 'Kauswagan', 'Cagayan de Oro', 'Misamis Oriental', 'miguel.torres@email.com', 'Regular', 1),
(5, 1, 'Old', 'Customer', '09215556666', '99 Old St.', 'Bulua', 'Cagayan de Oro', 'Misamis Oriental', 'old.customer@email.com', 'Regular', 0);

-- --------------------------------------------------------

--
-- Table structure for table `tbldebit_memo`
--

CREATE TABLE `tbldebit_memo` (
  `debit_memo_id` int(11) NOT NULL,
  `reference_no` varchar(30) DEFAULT NULL,
  `invoice_id` int(11) NOT NULL,
  `category` varchar(50) DEFAULT NULL,
  `memo_date` datetime NOT NULL DEFAULT current_timestamp(),
  `amount` decimal(12,2) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbldiscount`
--

CREATE TABLE `tbldiscount` (
  `discount_id` int(11) NOT NULL,
  `discount_name` varchar(100) NOT NULL,
  `discount_type` varchar(20) NOT NULL DEFAULT 'Percentage',
  `discount_value` decimal(10,2) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbldiscount`
--

INSERT INTO `tbldiscount` (`discount_id`, `discount_name`, `discount_type`, `discount_value`, `is_active`) VALUES
(1, 'Senior Citizen Discount', 'Percentage', 20.00, 1),
(2, 'PWD Discount', 'Percentage', 20.00, 1),
(3, 'Employee Discount', 'Percentage', 10.00, 1),
(4, 'Seasonal Promo ₱50 Off', 'Fixed', 50.00, 1),
(5, 'Expired Holiday Promo', 'Percentage', 15.00, 0);

-- --------------------------------------------------------

--
-- Table structure for table `tblemployee`
--

CREATE TABLE `tblemployee` (
  `employee_id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tblemployee`
--

INSERT INTO `tblemployee` (`employee_id`, `role_id`, `first_name`, `last_name`, `contact_number`, `username`, `password`, `is_active`) VALUES
(1, 1, 'Maria', 'Santos', '09171234567', 'msantos', '$2y$10$2gsAbe/.AtTqrrxmOmylGOgqaIkl7bqJZ10YhiinwEGzkBfScbLr6', 1),
(2, 2, 'Juan', 'Dela Cruz', '09181234567', 'jdelacruz', '$2y$10$9JGc8eSZ//2yLDkCWfm6fesVVQc/nCe/j8vhQSRpr4ImxvZH41mt.', 1),
(3, 2, 'Ana', 'Reyes', '09191234567', 'areyes', '$2y$10$IBa4kp4yZx/318MPKRFC6uYkujOt.J7KTVtGciuit3xkczZ2Y4oNi', 1),
(4, 3, 'Pedro', 'Garcia', '09201234567', 'pgarcia', '$2y$10$ha/0yVPIUp0Hz/dqiQu8au7.Fn8UBoPY84XcBM3n/RBSC2GO3Kw1i', 1),
(5, 4, 'Liza', 'Fernandez', '09211234567', 'lfernandez', '$2y$10$examplehashvalueforfernandez123456789', 0),
(6, 2, 'Old', 'Cashier', '09221234567', 'ocashier', '$2y$10$examplehashvalueforoldcashier1234567', 0),
(7, 5, 'Junil', 'Salvatiera', '09664169456', 'jsalva', '$2y$10$E8lcJSgOM2k7cQxa5eEn1O/zXoIK7pjJer4jrOG7a3p2DZ2f9tQLe', 1),
(8, 2, 'Kent', 'Bonita', '09664756713', 'kbonita', '$2y$10$BR/GqxmQqUrLmkC1l1UcFeP1SbUzpBaARcU2iud/2rLG0gKDHpLBW', 1),
(9, 2, 'Krystal', 'Morales', '09664160660', 'kmorales', '$2y$10$mUvNj2Y5lc.bLdOAShWDQOaVB3H/z1mAEDaVLg0bShCvYz4TQmqTC', 1);

-- --------------------------------------------------------

--
-- Table structure for table `tblend_day`
--

CREATE TABLE `tblend_day` (
  `end_day_id` int(11) NOT NULL,
  `business_date` date NOT NULL,
  `day_status` enum('Open','Finalized') NOT NULL DEFAULT 'Open',
  `shifts_count` int(11) NOT NULL DEFAULT 0,
  `total_transactions` int(11) NOT NULL DEFAULT 0,
  `gross_sales` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_discounts` decimal(14,2) NOT NULL DEFAULT 0.00,
  `net_sales` decimal(14,2) NOT NULL DEFAULT 0.00,
  `ar_credit_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `voided_count` int(11) NOT NULL DEFAULT 0,
  `voided_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_opening_cash` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_cash_lifts` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_expected_cash` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_actual_cash` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_cash_difference` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_deposit_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_deposit_verified` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_deposit_difference` decimal(14,2) NOT NULL DEFAULT 0.00,
  `discrepancy_count` int(11) NOT NULL DEFAULT 0,
  `payments_json` text DEFAULT NULL,
  `finalized_by` int(11) DEFAULT NULL,
  `finalized_at` datetime DEFAULT NULL,
  `finalize_count` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tblend_day`
--

INSERT INTO `tblend_day` (`end_day_id`, `business_date`, `day_status`, `shifts_count`, `total_transactions`, `gross_sales`, `total_discounts`, `net_sales`, `ar_credit_total`, `voided_count`, `voided_total`, `total_opening_cash`, `total_cash_lifts`, `total_expected_cash`, `total_actual_cash`, `total_cash_difference`, `total_deposit_amount`, `total_deposit_verified`, `total_deposit_difference`, `discrepancy_count`, `payments_json`, `finalized_by`, `finalized_at`, `finalize_count`, `created_at`, `updated_at`) VALUES
(1, '2026-10-21', 'Finalized', 2, 4, 13390.00, 0.00, 13390.00, 12260.00, 0, 0.00, 1000.00, 0.00, 2130.00, 2120.00, -10.00, 2120.00, 2120.00, 0.00, 1, '{\"Cash\":1130}', 1, '2026-10-22 02:05:13', 1, '2026-10-21 18:05:13', '2026-10-21 18:05:13');

-- --------------------------------------------------------

--
-- Table structure for table `tblend_day_event`
--

CREATE TABLE `tblend_day_event` (
  `event_id` int(11) NOT NULL,
  `end_day_id` int(11) NOT NULL,
  `event_type` enum('Finalized','Reopened') NOT NULL,
  `actor_id` int(11) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tblend_day_event`
--

INSERT INTO `tblend_day_event` (`event_id`, `end_day_id`, `event_type`, `actor_id`, `reason`, `created_at`) VALUES
(1, 1, 'Finalized', 1, NULL, '2026-10-21 18:05:13');

-- --------------------------------------------------------

--
-- Table structure for table `tblgift_certificate`
--

CREATE TABLE `tblgift_certificate` (
  `gc_id` int(11) NOT NULL,
  `suki_card_id` int(11) NOT NULL,
  `gc_value` decimal(10,2) NOT NULL,
  `points_used` int(11) NOT NULL DEFAULT 0,
  `date_issued` date NOT NULL,
  `expiration_date` date DEFAULT NULL,
  `date_redeemed` date DEFAULT NULL,
  `status` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tblgift_certificate_code`
--

CREATE TABLE `tblgift_certificate_code` (
  `gcc_id` int(11) NOT NULL,
  `code` varchar(24) NOT NULL,
  `face_value` decimal(10,2) NOT NULL,
  `expiration_date` date NOT NULL,
  `status` enum('Available','Redeemed','Voided') NOT NULL DEFAULT 'Available',
  `date_issued` date NOT NULL,
  `issued_by` int(11) DEFAULT NULL,
  `redeemed_sale_id` int(11) DEFAULT NULL,
  `redeemed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tblgift_certificate_code`
--

INSERT INTO `tblgift_certificate_code` (`gcc_id`, `code`, `face_value`, `expiration_date`, `status`, `date_issued`, `issued_by`, `redeemed_sale_id`, `redeemed_at`, `created_at`) VALUES
(1, 'GC-TUWK6MD6', 500.00, '2026-12-31', 'Available', '2026-10-18', 1, NULL, NULL, '2026-10-18 16:06:50'),
(2, 'GC-8WM8M4XU', 500.00, '2026-12-31', 'Available', '2026-10-18', 1, NULL, NULL, '2026-10-18 16:06:50'),
(3, 'GC-FA2K3K4S', 500.00, '2026-12-31', 'Available', '2026-10-18', 1, NULL, NULL, '2026-10-18 16:06:50'),
(4, 'GC-YNUCBPTD', 500.00, '2026-12-31', 'Available', '2026-10-18', 1, NULL, NULL, '2026-10-18 16:06:50'),
(5, 'GC-2QDMQ4VF', 500.00, '2026-12-31', 'Available', '2026-10-18', 1, NULL, NULL, '2026-10-18 16:06:50'),
(6, 'GC-7PCJ5QGU', 500.00, '2026-12-31', 'Available', '2026-10-18', 1, NULL, NULL, '2026-10-18 16:06:50'),
(7, 'GC-PH34PVYA', 500.00, '2026-12-31', 'Available', '2026-10-18', 1, NULL, NULL, '2026-10-18 16:06:50'),
(8, 'GC-6ZD6KQGE', 500.00, '2026-12-31', 'Available', '2026-10-18', 1, NULL, NULL, '2026-10-18 16:06:50'),
(9, 'GC-6VZN952D', 500.00, '2026-12-31', 'Available', '2026-10-18', 1, NULL, NULL, '2026-10-18 16:06:50'),
(10, 'GC-RJWF8XHR', 500.00, '2026-12-31', 'Redeemed', '2026-10-18', 1, 28, '2026-10-22 22:23:46', '2026-10-18 16:06:50');

-- --------------------------------------------------------

--
-- Table structure for table `tblheld_transaction`
--

CREATE TABLE `tblheld_transaction` (
  `held_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `counter_id` int(11) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `discount_id` int(11) DEFAULT NULL,
  `held_label` varchar(50) NOT NULL DEFAULT 'Held',
  `cart_json` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tblinvoice`
--

CREATE TABLE `tblinvoice` (
  `invoice_id` int(11) NOT NULL,
  `ar_account_id` int(11) DEFAULT NULL,
  `sale_id` int(11) DEFAULT NULL,
  `transaction_type` varchar(30) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `due_date` date DEFAULT NULL,
  `transaction_date` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tblinvoice`
--

INSERT INTO `tblinvoice` (`invoice_id`, `ar_account_id`, `sale_id`, `transaction_type`, `amount`, `due_date`, `transaction_date`) VALUES
(1, NULL, 1, 'Cash', 84.80, NULL, '2026-10-01 17:18:57'),
(2, NULL, 2, 'Cash', 75.00, NULL, '2026-10-01 17:19:27'),
(3, 1, 3, 'Credit', 33.00, '2026-10-31', '2026-10-01 17:51:34'),
(4, NULL, 4, 'Cash', 116.00, NULL, '2026-10-02 09:03:12'),
(5, NULL, 5, 'Cash', 836.00, NULL, '2026-10-02 09:05:22'),
(6, NULL, 6, 'Cash', 675.00, NULL, '2026-10-02 15:48:09'),
(7, 1, 7, 'Credit', 116.00, '2026-11-01', '2026-10-02 15:49:06'),
(8, NULL, 8, 'Cash', 371.00, NULL, '2026-10-02 22:08:17'),
(9, NULL, 9, 'Cash', 280.00, NULL, '2026-10-04 11:36:52'),
(10, NULL, 10, 'Cash', 570.00, NULL, '2026-10-08 10:05:41'),
(11, NULL, 11, 'Cash', 144.00, NULL, '2026-10-09 19:04:06'),
(12, 2, 12, 'Credit', 446.00, '2026-10-24', '2026-10-09 19:05:27'),
(13, 2, NULL, 'Payment', 446.00, NULL, '2026-10-11 21:01:06'),
(14, 1, 13, 'Credit', 788.00, '2026-11-10', '2026-10-11 21:30:52'),
(15, 1, NULL, 'Payment', 33.00, NULL, '2026-10-11 21:43:34'),
(16, NULL, 14, 'Cash', 1742.00, NULL, '2026-10-16 16:22:53'),
(17, 1, 15, 'Credit', 2640.00, '2026-11-15', '2026-10-16 16:24:42'),
(18, NULL, 16, 'Cash', 2244.00, NULL, '2026-10-18 00:38:40'),
(19, NULL, 17, 'Cash', 2750.00, NULL, '2026-10-18 01:52:45'),
(20, NULL, 18, 'Cash', 3850.00, NULL, '2026-10-18 02:09:24'),
(21, NULL, 19, 'Cash', 1760.00, NULL, '2026-10-18 02:15:02'),
(22, NULL, 20, 'Cash', 1575.00, NULL, '2026-10-18 19:59:40'),
(23, NULL, 21, 'Cash', 370.00, NULL, '2026-10-21 15:50:07'),
(24, 2, 22, 'Credit', 5780.00, '2026-11-05', '2026-10-21 15:53:00'),
(25, 1, 23, 'Credit', 6480.00, '2026-11-20', '2026-10-21 15:53:59'),
(26, NULL, 24, 'Cash', 760.00, NULL, '2026-10-21 22:09:46'),
(27, NULL, 25, 'Cash', 445.00, NULL, '2026-10-22 02:01:48'),
(28, NULL, 26, 'Cash', 741.00, NULL, '2026-10-22 02:02:50'),
(29, NULL, 27, 'Cash', 2125.00, NULL, '2026-10-22 02:18:19'),
(30, NULL, 28, 'Cash', 576.00, NULL, '2026-10-22 22:23:46'),
(31, NULL, 29, 'Cash', 997.00, NULL, '2026-10-22 22:42:47'),
(32, 1, NULL, 'Payment', 116.00, NULL, '2026-10-23 01:09:14');

-- --------------------------------------------------------

--
-- Table structure for table `tblpayment`
--

CREATE TABLE `tblpayment` (
  `payment_id` int(11) NOT NULL,
  `ar_account_id` int(11) DEFAULT NULL,
  `payment_type_id` int(11) NOT NULL,
  `gc_id` int(11) DEFAULT NULL,
  `gcc_id` int(11) DEFAULT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `payment_date` datetime NOT NULL DEFAULT current_timestamp(),
  `amount_paid` decimal(12,2) NOT NULL,
  `reference_no` varchar(50) DEFAULT NULL,
  `payment_status` varchar(20) NOT NULL DEFAULT 'Posted',
  `created_by` int(11) DEFAULT NULL,
  `idempotency_token` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tblpayment`
--

INSERT INTO `tblpayment` (`payment_id`, `ar_account_id`, `payment_type_id`, `gc_id`, `gcc_id`, `invoice_id`, `payment_date`, `amount_paid`, `reference_no`, `payment_status`, `created_by`, `idempotency_token`) VALUES
(1, NULL, 1, NULL, NULL, 1, '2026-10-01 17:18:57', 84.80, NULL, 'Posted', NULL, NULL),
(2, NULL, 1, NULL, NULL, 2, '2026-10-01 17:19:27', 75.00, NULL, 'Posted', NULL, NULL),
(4, NULL, 1, NULL, NULL, 4, '2026-10-02 09:03:12', 116.00, NULL, 'Posted', NULL, NULL),
(5, NULL, 1, NULL, NULL, 5, '2026-10-02 09:05:22', 836.00, NULL, 'Posted', NULL, NULL),
(6, NULL, 1, NULL, NULL, 6, '2026-10-02 15:48:09', 675.00, NULL, 'Posted', NULL, NULL),
(7, NULL, 1, NULL, NULL, 8, '2026-10-02 22:08:17', 371.00, NULL, 'Posted', NULL, NULL),
(8, NULL, 1, NULL, NULL, 9, '2026-10-04 11:36:52', 100.00, NULL, 'Posted', NULL, NULL),
(9, NULL, 6, NULL, NULL, 9, '2026-10-04 11:36:52', 100.00, NULL, 'Posted', NULL, NULL),
(10, NULL, 6, NULL, NULL, 9, '2026-10-04 11:36:52', 100.00, NULL, 'Posted', NULL, NULL),
(11, NULL, 1, NULL, NULL, 10, '2026-10-08 10:05:41', 600.00, NULL, 'Posted', NULL, NULL),
(12, NULL, 1, NULL, NULL, 11, '2026-10-09 19:04:06', 150.00, NULL, 'Posted', NULL, NULL),
(13, 2, 1, NULL, NULL, 13, '2026-10-11 21:01:06', 446.00, NULL, 'Reversed', 4, '7434eb97-10d1-49f7-9935-5735f19f42fe'),
(14, 1, 1, NULL, NULL, 15, '2026-10-11 21:43:34', 33.00, NULL, 'Posted', 4, 'bbdd7ee6-5d11-41cd-9ae5-6026cca93bd1'),
(15, NULL, 1, NULL, NULL, 16, '2026-10-16 16:22:53', 2000.00, NULL, 'Posted', NULL, NULL),
(16, NULL, 1, NULL, NULL, 18, '2026-10-18 00:38:40', 2244.00, NULL, 'Posted', NULL, NULL),
(17, NULL, 1, NULL, NULL, 19, '2026-10-18 01:52:45', 2750.00, NULL, 'Posted', NULL, NULL),
(18, NULL, 1, NULL, NULL, 20, '2026-10-18 02:09:24', 3850.00, NULL, 'Posted', NULL, NULL),
(19, NULL, 1, NULL, NULL, 21, '2026-10-18 02:15:02', 1800.00, NULL, 'Posted', NULL, NULL),
(20, NULL, 1, NULL, NULL, 22, '2026-10-18 19:59:40', 1600.00, NULL, 'Posted', NULL, NULL),
(21, NULL, 1, NULL, NULL, 23, '2026-10-21 15:50:07', 400.00, NULL, 'Posted', NULL, NULL),
(22, NULL, 1, NULL, NULL, 26, '2026-10-21 22:09:46', 800.00, NULL, 'Posted', NULL, NULL),
(23, NULL, 1, NULL, NULL, 27, '2026-10-22 02:01:48', 445.00, NULL, 'Posted', NULL, NULL),
(24, NULL, 1, NULL, NULL, 28, '2026-10-22 02:02:50', 800.00, NULL, 'Posted', NULL, NULL),
(25, NULL, 1, NULL, NULL, 29, '2026-10-22 02:18:19', 2150.00, NULL, 'Posted', NULL, NULL),
(26, NULL, 7, NULL, 10, 30, '2026-10-22 22:23:46', 500.00, NULL, 'Posted', NULL, NULL),
(27, NULL, 1, NULL, NULL, 30, '2026-10-22 22:23:46', 76.00, NULL, 'Posted', NULL, NULL),
(28, NULL, 1, NULL, NULL, 31, '2026-10-22 22:42:47', 1002.00, NULL, 'Posted', NULL, NULL),
(29, 1, 1, NULL, NULL, 32, '2026-10-23 01:09:14', 116.00, NULL, 'Posted', 4, '4f2baad6-5ef5-434e-8291-b484596ad5fc');

-- --------------------------------------------------------

--
-- Table structure for table `tblpayment_allocation`
--

CREATE TABLE `tblpayment_allocation` (
  `allocation_id` int(11) NOT NULL,
  `payment_id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `reversal_id` int(11) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tblpayment_allocation`
--

INSERT INTO `tblpayment_allocation` (`allocation_id`, `payment_id`, `invoice_id`, `amount`, `reversal_id`, `created_by`, `created_at`) VALUES
(1, 13, 12, 446.00, NULL, 4, '2026-10-11 21:01:06'),
(2, 13, 12, -446.00, 1, 4, '2026-10-11 21:06:48'),
(3, 14, 3, 33.00, NULL, 4, '2026-10-11 21:43:34'),
(4, 29, 7, 116.00, NULL, 4, '2026-10-23 01:09:14');

-- --------------------------------------------------------

--
-- Table structure for table `tblpayment_reversal`
--

CREATE TABLE `tblpayment_reversal` (
  `reversal_id` int(11) NOT NULL,
  `payment_id` int(11) NOT NULL,
  `reversal_type` varchar(20) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `reason` varchar(255) NOT NULL,
  `authorized_by` int(11) NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tblpayment_reversal`
--

INSERT INTO `tblpayment_reversal` (`reversal_id`, `payment_id`, `reversal_type`, `amount`, `reason`, `authorized_by`, `created_by`, `created_at`) VALUES
(1, 13, 'Reversal', 446.00, 'I run a test', 1, 4, '2026-10-11 21:06:48');

-- --------------------------------------------------------

--
-- Table structure for table `tblpayment_type`
--

CREATE TABLE `tblpayment_type` (
  `payment_type_id` int(11) NOT NULL,
  `type_name` varchar(50) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tblpayment_type`
--

INSERT INTO `tblpayment_type` (`payment_type_id`, `type_name`, `is_active`) VALUES
(1, 'Cash', 1),
(2, 'GCash', 1),
(3, 'Credit Card', 0),
(4, 'Debit Card', 0),
(5, 'Store Credit', 0),
(6, 'Suki Points', 1),
(7, 'Gift Certificate', 1);

-- --------------------------------------------------------

--
-- Table structure for table `tblpoints_redemption`
--

CREATE TABLE `tblpoints_redemption` (
  `redemption_id` int(11) NOT NULL,
  `suki_card_id` int(11) NOT NULL,
  `payment_id` int(11) DEFAULT NULL,
  `points_used` int(11) NOT NULL,
  `redemption_date` datetime NOT NULL DEFAULT current_timestamp(),
  `cash_value` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tblpoints_redemption`
--

INSERT INTO `tblpoints_redemption` (`redemption_id`, `suki_card_id`, `payment_id`, `points_used`, `redemption_date`, `cash_value`) VALUES
(1, 3, 9, 100, '2026-10-04 00:00:00', 100.00),
(2, 3, 10, 100, '2026-10-04 00:00:00', 100.00);

-- --------------------------------------------------------

--
-- Table structure for table `tblprice_tier`
--

CREATE TABLE `tblprice_tier` (
  `price_tier_id` int(11) NOT NULL,
  `tier_name` varchar(50) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tblprice_tier`
--

INSERT INTO `tblprice_tier` (`price_tier_id`, `tier_name`, `is_active`) VALUES
(1, 'Regular', 1),
(2, 'Wholesale', 1),
(3, 'Retailer', 1),
(4, 'Old Promo Tier', 0);

-- --------------------------------------------------------

--
-- Table structure for table `tblproduct`
--

CREATE TABLE `tblproduct` (
  `product_id` int(11) NOT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `category_id` int(11) NOT NULL,
  `unit_id` int(11) NOT NULL,
  `product_name` varchar(150) NOT NULL,
  `unit` varchar(30) NOT NULL,
  `is_vatable` tinyint(1) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_wholesale_barcode` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tblproduct`
--

INSERT INTO `tblproduct` (`product_id`, `barcode`, `category_id`, `unit_id`, `product_name`, `unit`, `is_vatable`, `is_active`, `is_wholesale_barcode`) VALUES
(1, '2900000000018', 1, 1, 'Coca-Cola 1.5L', 'Bottle', 1, 1, 0),
(2, '2900000000025', 1, 1, 'Pepsi 1.5L', 'Bottle', 1, 1, 0),
(3, '2900000000032', 1, 1, 'Sprite 1.5L', 'Bottle', 1, 1, 0),
(4, '2900000000049', 1, 2, 'Nescafe 3-in-1', 'Sachet', 1, 1, 0),
(5, '2900000000056', 2, 3, 'Piattos Cheese', 'Pack', 1, 1, 0),
(6, '2900000000063', 2, 3, 'Nova Multigrain', 'Pack', 1, 1, 0),
(7, '2900000000070', 3, 4, 'Century Tuna Flakes', 'Can', 1, 1, 0),
(8, '2900000000087', 3, 4, 'Argentina Corned Beef', 'Can', 1, 1, 0),
(9, '2900000000094', 4, 5, 'Safeguard Soap', 'Bar', 1, 1, 0),
(10, '2900000000100', 4, 6, 'Colgate Toothpaste', 'Tube', 1, 1, 0),
(11, '2900000000117', 5, 3, 'Lucky Me Pancit Canton', 'Pack', 1, 1, 0),
(12, '2900000000124', 8, 2, 'Bear Brand Powdered Milk', 'Sachet', 1, 1, 0),
(13, NULL, 1, 1, 'Discontinued Soda', 'Bottle', 1, 0, 0),
(14, '2900000001015', 3, 4, 'Argentina Corned Beef - 1 Box(27)', 'Can', 1, 1, 1),
(15, '2900000001022', 1, 1, 'Coca-Cola 1.5L - Case of 12', 'Bottle', 1, 1, 1),
(16, '2900000001053', 5, 21, 'Lucky Me Pancit Canton - Box of 24', 'Box', 1, 1, 1);

-- --------------------------------------------------------

--
-- Table structure for table `tblproduct_category`
--

CREATE TABLE `tblproduct_category` (
  `category_id` int(11) NOT NULL,
  `category_name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tblproduct_category`
--

INSERT INTO `tblproduct_category` (`category_id`, `category_name`, `description`, `is_active`) VALUES
(1, 'Beverages', 'Soft drinks, juices, and water', 1),
(2, 'Snacks', 'Chips, crackers, and finger foods', 1),
(3, 'Canned Goods', 'Canned meat, fish, and vegetables', 1),
(4, 'Personal Care', 'Soap, shampoo, toothpaste, and hygiene items', 1),
(5, 'Instant Noodles', 'Pancit canton and instant noodle products', 1),
(6, 'Discontinued Category', 'No longer used', 0),
(7, 'Dairy', 'Products derived from the milk of mammals like cows, goats, sheep, and water buffalo', 1),
(8, 'Powdered Milk', 'whole milk, skimmed milk, partly skimmed milk, and cream powder', 1);

-- --------------------------------------------------------

--
-- Table structure for table `tblproduct_price`
--

CREATE TABLE `tblproduct_price` (
  `product_price_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `price_tier_id` int(11) NOT NULL,
  `price` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tblproduct_price`
--

INSERT INTO `tblproduct_price` (`product_price_id`, `product_id`, `price_tier_id`, `price`) VALUES
(1, 1, 1, 75.00),
(2, 1, 2, 68.00),
(3, 1, 3, 70.00),
(4, 2, 1, 72.00),
(5, 2, 2, 65.00),
(6, 2, 3, 68.00),
(7, 3, 1, 75.00),
(8, 3, 2, 68.00),
(9, 3, 3, 70.00),
(10, 4, 1, 9.00),
(11, 4, 2, 7.50),
(12, 4, 3, 8.00),
(13, 5, 1, 25.00),
(14, 5, 2, 21.00),
(15, 5, 3, 23.00),
(16, 6, 1, 20.00),
(17, 6, 2, 17.00),
(18, 6, 3, 18.50),
(19, 7, 1, 38.00),
(20, 7, 2, 33.00),
(21, 7, 3, 35.00),
(22, 8, 1, 53.00),
(23, 8, 2, 48.00),
(24, 8, 3, 50.00),
(25, 9, 1, 45.00),
(26, 9, 2, 39.00),
(27, 9, 3, 42.00),
(28, 10, 1, 62.00),
(29, 10, 2, 55.00),
(30, 10, 3, 58.00),
(31, 11, 1, 15.00),
(32, 11, 2, 12.50),
(33, 11, 3, 13.50),
(34, 12, 1, 12.00),
(35, 12, 2, 10.00),
(36, 12, 3, 11.00),
(37, 14, 1, 48.00),
(38, 14, 2, 1296.00),
(39, 14, 3, 50.00),
(79, 15, 1, 70.00),
(80, 15, 2, 816.00),
(81, 15, 3, 68.00),
(82, 16, 1, 12.00),
(83, 16, 2, 300.00),
(84, 16, 3, 10.00);

-- --------------------------------------------------------

--
-- Table structure for table `tblproduct_price_legacy_backup`
--

CREATE TABLE `tblproduct_price_legacy_backup` (
  `product_price_id` int(11) NOT NULL DEFAULT 0,
  `product_id` int(11) NOT NULL,
  `price_tier_id` int(11) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tblproduct_price_legacy_backup`
--

INSERT INTO `tblproduct_price_legacy_backup` (`product_price_id`, `product_id`, `price_tier_id`, `price`, `is_active`, `created_at`) VALUES
(1, 1, 1, 75.00, 1, '2026-10-01 08:49:20'),
(2, 1, 2, 68.00, 1, '2026-10-01 08:49:20'),
(3, 1, 3, 70.00, 1, '2026-10-01 08:49:20'),
(4, 2, 1, 72.00, 1, '2026-10-01 08:49:20'),
(5, 2, 2, 65.00, 1, '2026-10-01 08:49:20'),
(6, 2, 3, 68.00, 1, '2026-10-01 08:49:20'),
(7, 3, 1, 75.00, 1, '2026-10-01 08:49:20'),
(8, 3, 2, 68.00, 1, '2026-10-01 08:49:20'),
(9, 3, 3, 70.00, 1, '2026-10-01 08:49:20'),
(10, 4, 1, 9.00, 1, '2026-10-01 08:49:20'),
(11, 4, 2, 7.50, 1, '2026-10-01 08:49:20'),
(12, 4, 3, 8.00, 1, '2026-10-01 08:49:20'),
(13, 5, 1, 25.00, 1, '2026-10-01 08:49:20'),
(14, 5, 2, 21.00, 1, '2026-10-01 08:49:20'),
(15, 5, 3, 23.00, 1, '2026-10-01 08:49:20'),
(16, 6, 1, 20.00, 1, '2026-10-01 08:49:20'),
(17, 6, 2, 17.00, 1, '2026-10-01 08:49:20'),
(18, 6, 3, 18.50, 1, '2026-10-01 08:49:20'),
(19, 7, 1, 38.00, 1, '2026-10-01 08:49:20'),
(20, 7, 2, 33.00, 1, '2026-10-01 08:49:20'),
(21, 7, 3, 35.00, 1, '2026-10-01 08:49:20'),
(22, 8, 1, 55.00, 1, '2026-10-01 08:49:20'),
(23, 8, 2, 48.00, 1, '2026-10-01 08:49:20'),
(24, 8, 3, 50.00, 1, '2026-10-01 08:49:20'),
(25, 9, 1, 45.00, 1, '2026-10-01 08:49:20'),
(26, 9, 2, 39.00, 1, '2026-10-01 08:49:20'),
(27, 9, 3, 42.00, 1, '2026-10-01 08:49:20'),
(28, 10, 1, 62.00, 1, '2026-10-01 08:49:20'),
(29, 10, 2, 55.00, 1, '2026-10-01 08:49:20'),
(30, 10, 3, 58.00, 1, '2026-10-01 08:49:20'),
(31, 11, 1, 15.00, 1, '2026-10-01 08:49:20'),
(32, 11, 2, 12.50, 1, '2026-10-01 08:49:20'),
(33, 11, 3, 13.50, 1, '2026-10-01 08:49:20'),
(34, 12, 1, 12.00, 1, '2026-10-01 08:49:20'),
(35, 12, 2, 10.00, 1, '2026-10-01 08:49:20'),
(36, 12, 3, 11.00, 1, '2026-10-01 08:49:20');

-- --------------------------------------------------------

--
-- Table structure for table `tblproduct_unit`
--

CREATE TABLE `tblproduct_unit` (
  `unit_id` int(11) NOT NULL,
  `unit_name` varchar(50) NOT NULL,
  `unit_code` varchar(10) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tblproduct_unit`
--

INSERT INTO `tblproduct_unit` (`unit_id`, `unit_name`, `unit_code`, `is_active`) VALUES
(1, 'Bottle', 'BOTTLE', 1),
(2, 'Sachet', 'SACHET', 1),
(3, 'Pack', 'PACK', 1),
(4, 'Can', 'CAN', 1),
(5, 'Bar', 'BAR', 1),
(6, 'Tube', 'TUBE', 1),
(8, 'Bottle', 'BOTTLE', 0),
(9, 'Sachet', 'SACHET', 0),
(10, 'Pack', 'PACK', 0),
(11, 'Can', 'CAN', 0),
(12, 'Bar', 'BAR', 0),
(13, 'Tube', 'TUBE', 0),
(15, 'Bottle', 'BOTTLE', 0),
(16, 'Sachet', 'SACHET', 1),
(17, 'Pack', 'PACK', 0),
(18, 'Can', 'CAN', 0),
(19, 'Bar', 'BAR', 0),
(20, 'Tube', 'TUBE', 0),
(21, 'Box', 'BX', 1);

-- --------------------------------------------------------

--
-- Table structure for table `tblrole`
--

CREATE TABLE `tblrole` (
  `role_id` int(11) NOT NULL,
  `role_name` varchar(50) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tblrole`
--

INSERT INTO `tblrole` (`role_id`, `role_name`, `is_active`) VALUES
(1, 'Administrator', 1),
(2, 'Cashier', 1),
(3, 'Manager', 1),
(4, 'Clerk', 0),
(5, 'Supervisor', 1);

-- --------------------------------------------------------

--
-- Table structure for table `tblsale`
--

CREATE TABLE `tblsale` (
  `sale_id` int(11) NOT NULL,
  `receipt_number` varchar(30) NOT NULL,
  `counter_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `suki_card_id` int(11) DEFAULT NULL,
  `vatable_sales` decimal(12,2) NOT NULL DEFAULT 0.00,
  `vat_exempt_sales` decimal(12,2) NOT NULL DEFAULT 0.00,
  `vat_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount_tendered` decimal(12,2) NOT NULL DEFAULT 0.00,
  `change_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `sale_status` varchar(20) NOT NULL DEFAULT 'Completed',
  `void_reason` varchar(255) DEFAULT NULL,
  `voided_by` int(11) DEFAULT NULL,
  `voided_at` datetime DEFAULT NULL,
  `sale_date` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tblsale`
--

INSERT INTO `tblsale` (`sale_id`, `receipt_number`, `counter_id`, `employee_id`, `customer_id`, `suki_card_id`, `vatable_sales`, `vat_exempt_sales`, `vat_amount`, `total_amount`, `amount_tendered`, `change_amount`, `sale_status`, `void_reason`, `voided_by`, `voided_at`, `sale_date`) VALUES
(1, 'OR-20261001-00001', 1, 2, NULL, 2, 75.71, 0.00, 9.09, 84.80, 100.00, 15.20, 'Completed', NULL, NULL, NULL, '2026-10-01 17:18:57'),
(2, 'OR-20261001-00002', 1, 2, NULL, NULL, 66.96, 0.00, 8.04, 75.00, 78.00, 3.00, 'Completed', NULL, NULL, NULL, '2026-10-01 17:19:27'),
(3, 'OR-20261001-00003', 1, 2, NULL, 2, 29.46, 0.00, 3.54, 33.00, 0.00, 0.00, 'Completed', NULL, NULL, NULL, '2026-10-01 17:51:34'),
(4, 'OR-20261002-00004', 1, 2, NULL, NULL, 103.57, 0.00, 12.43, 116.00, 150.00, 34.00, 'Completed', NULL, NULL, NULL, '2026-10-02 09:03:12'),
(5, 'OR-20261002-00005', 1, 2, NULL, 1, 746.43, 0.00, 89.57, 836.00, 1000.02, 164.02, 'Completed', NULL, NULL, NULL, '2026-10-02 09:05:22'),
(6, 'OR-20261002-00006', 1, 2, NULL, NULL, 602.68, 0.00, 72.32, 675.00, 700.00, 25.00, 'Completed', NULL, NULL, NULL, '2026-10-02 15:48:09'),
(7, 'OR-20261002-00007', 1, 2, NULL, 2, 103.57, 0.00, 12.43, 116.00, 0.00, 0.00, 'Completed', NULL, NULL, NULL, '2026-10-02 15:49:06'),
(8, 'OR-20261002-00008', 1, 2, NULL, NULL, 331.25, 0.00, 39.75, 371.00, 400.00, 29.00, 'Completed', NULL, NULL, NULL, '2026-10-02 22:08:17'),
(9, 'OR-20261004-00009', 1, 2, NULL, 3, 250.00, 0.00, 30.00, 280.00, 300.00, 20.00, 'Completed', NULL, NULL, NULL, '2026-10-04 11:36:52'),
(10, 'OR-20261008-00010', 1, 2, NULL, NULL, 508.93, 0.00, 61.07, 570.00, 600.00, 30.00, 'Completed', NULL, NULL, NULL, '2026-10-08 10:05:41'),
(11, 'OR-20261009-00011', 1, 2, NULL, NULL, 128.57, 0.00, 15.43, 144.00, 150.00, 6.00, 'Completed', NULL, NULL, NULL, '2026-10-09 19:04:06'),
(12, 'OR-20261009-00012', 1, 2, NULL, 3, 398.21, 0.00, 47.79, 446.00, 0.00, 0.00, 'Completed', NULL, NULL, NULL, '2026-10-09 19:05:27'),
(13, 'OR-20261011-00013', 1, 2, NULL, 2, 703.57, 0.00, 84.43, 788.00, 0.00, 0.00, 'Completed', NULL, NULL, NULL, '2026-10-11 21:30:52'),
(14, 'OR-20261016-00014', 1, 2, NULL, NULL, 1555.36, 0.00, 186.64, 1742.00, 2000.00, 258.00, 'Completed', NULL, NULL, NULL, '2026-10-16 16:22:53'),
(15, 'OR-20261016-00015', 1, 2, NULL, 2, 2357.14, 0.00, 282.86, 2640.00, 0.00, 0.00, 'Completed', NULL, NULL, NULL, '2026-10-16 16:24:42'),
(16, 'OR-20261017-00016', 1, 2, NULL, NULL, 2003.57, 0.00, 240.43, 2244.00, 2244.00, 0.00, 'Completed', NULL, NULL, NULL, '2026-10-18 00:38:40'),
(17, 'OR-20261017-00017', 1, 2, NULL, NULL, 2455.36, 0.00, 294.64, 2750.00, 2750.00, 0.00, 'Completed', NULL, NULL, NULL, '2026-10-18 01:52:45'),
(18, 'OR-20261017-00018', 1, 8, NULL, NULL, 3437.50, 0.00, 412.50, 3850.00, 3850.00, 0.00, 'Completed', NULL, NULL, NULL, '2026-10-18 02:09:24'),
(19, 'OR-20261017-00019', 1, 8, NULL, NULL, 1571.43, 0.00, 188.57, 1760.00, 1800.00, 40.00, 'Completed', NULL, NULL, NULL, '2026-10-18 02:15:02'),
(20, 'OR-20261018-00020', 1, 2, NULL, NULL, 1406.25, 0.00, 168.75, 1575.00, 1600.00, 25.00, 'Completed', NULL, NULL, NULL, '2026-10-18 19:59:40'),
(21, 'OR-20261021-00021', 2, 8, NULL, NULL, 330.36, 0.00, 39.64, 370.00, 400.00, 30.00, 'Completed', NULL, NULL, NULL, '2026-10-21 15:50:07'),
(22, 'OR-20261021-00022', 2, 8, NULL, 3, 5160.71, 0.00, 619.29, 5780.00, 0.00, 0.00, 'Completed', NULL, NULL, NULL, '2026-10-21 15:53:00'),
(23, 'OR-20261021-00023', 2, 8, NULL, 2, 5785.71, 0.00, 694.29, 6480.00, 0.00, 0.00, 'Completed', NULL, NULL, NULL, '2026-10-21 15:53:59'),
(24, 'OR-20261021-00024', 1, 2, NULL, NULL, 678.57, 0.00, 81.43, 760.00, 800.00, 40.00, 'Completed', NULL, NULL, NULL, '2026-10-21 22:09:46'),
(25, 'OR-20261021-00025', 2, 8, NULL, NULL, 397.32, 0.00, 47.68, 445.00, 445.00, 0.00, 'Completed', NULL, NULL, NULL, '2026-10-22 02:01:48'),
(26, 'OR-20261021-00026', 2, 8, NULL, NULL, 661.61, 0.00, 79.39, 741.00, 800.00, 59.00, 'Completed', NULL, NULL, NULL, '2026-10-22 02:02:50'),
(27, 'OR-20261021-00027', 2, 8, NULL, NULL, 1897.32, 0.00, 227.68, 2125.00, 2150.00, 25.00, 'Completed', NULL, NULL, NULL, '2026-10-22 02:18:19'),
(28, 'OR-20261022-00028', 1, 8, NULL, NULL, 514.29, 0.00, 61.71, 576.00, 576.00, 0.00, 'Completed', NULL, NULL, NULL, '2026-10-22 22:23:46'),
(29, 'OR-20261022-00029', 1, 8, NULL, NULL, 890.18, 0.00, 106.82, 997.00, 1002.00, 5.00, 'Completed', NULL, NULL, NULL, '2026-10-22 22:42:47');

-- --------------------------------------------------------

--
-- Table structure for table `tblsale_item`
--

CREATE TABLE `tblsale_item` (
  `sale_item_id` int(11) NOT NULL,
  `sale_id` int(11) NOT NULL,
  `discount_id` int(11) DEFAULT NULL,
  `product_id` int(11) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `quantity` int(11) NOT NULL,
  `subtotal` decimal(12,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tblsale_item`
--

INSERT INTO `tblsale_item` (`sale_item_id`, `sale_id`, `discount_id`, `product_id`, `unit_price`, `quantity`, `subtotal`) VALUES
(1, 1, 2, 8, 48.00, 2, 96.00),
(2, 1, 2, 12, 10.00, 1, 10.00),
(3, 2, NULL, 1, 75.00, 1, 75.00),
(4, 3, NULL, 7, 33.00, 1, 33.00),
(5, 4, NULL, 4, 9.00, 3, 27.00),
(6, 4, NULL, 11, 15.00, 1, 15.00),
(7, 4, NULL, 10, 62.00, 1, 62.00),
(8, 4, NULL, 12, 12.00, 1, 12.00),
(9, 5, NULL, 4, 9.00, 1, 9.00),
(10, 5, NULL, 11, 15.00, 1, 15.00),
(11, 5, NULL, 10, 62.00, 1, 62.00),
(12, 5, NULL, 1, 75.00, 10, 750.00),
(13, 6, NULL, 1, 75.00, 4, 300.00),
(14, 6, NULL, 3, 75.00, 5, 375.00),
(15, 7, NULL, 8, 48.00, 1, 48.00),
(16, 7, NULL, 1, 68.00, 1, 68.00),
(17, 8, NULL, 8, 55.00, 2, 110.00),
(18, 8, NULL, 4, 9.00, 1, 9.00),
(19, 8, NULL, 11, 15.00, 1, 15.00),
(20, 8, NULL, 1, 75.00, 1, 75.00),
(21, 8, NULL, 5, 25.00, 1, 25.00),
(22, 8, NULL, 9, 45.00, 1, 45.00),
(23, 8, NULL, 6, 20.00, 1, 20.00),
(24, 8, NULL, 2, 72.00, 1, 72.00),
(25, 9, NULL, 1, 70.00, 4, 280.00),
(26, 10, NULL, 8, 55.00, 4, 220.00),
(27, 10, NULL, 12, 12.00, 4, 48.00),
(28, 10, NULL, 7, 38.00, 4, 152.00),
(29, 10, NULL, 1, 75.00, 2, 150.00),
(30, 11, 1, 8, 55.00, 1, 55.00),
(31, 11, 1, 12, 12.00, 1, 12.00),
(32, 11, 1, 7, 38.00, 1, 38.00),
(33, 11, 1, 1, 75.00, 1, 75.00),
(34, 12, NULL, 8, 50.00, 1, 50.00),
(35, 12, NULL, 12, 11.00, 1, 11.00),
(36, 12, NULL, 7, 35.00, 3, 105.00),
(37, 12, NULL, 1, 70.00, 4, 280.00),
(38, 13, NULL, 8, 48.00, 15, 720.00),
(39, 13, NULL, 1, 68.00, 1, 68.00),
(40, 14, NULL, 8, 55.00, 1, 55.00),
(41, 14, NULL, 12, 12.00, 3, 36.00),
(42, 14, NULL, 7, 38.00, 2, 76.00),
(43, 14, NULL, 1, 75.00, 21, 1575.00),
(44, 15, NULL, 14, 1296.00, 2, 2592.00),
(45, 15, NULL, 8, 48.00, 1, 48.00),
(46, 16, 1, 8, 55.00, 51, 2805.00),
(47, 17, NULL, 8, 55.00, 50, 2750.00),
(48, 18, NULL, 11, 15.00, 50, 750.00),
(49, 18, NULL, 10, 62.00, 50, 3100.00),
(50, 19, NULL, 8, 55.00, 32, 1760.00),
(51, 20, NULL, 1, 75.00, 21, 1575.00),
(52, 21, NULL, 8, 55.00, 1, 55.00),
(53, 21, NULL, 12, 12.00, 20, 240.00),
(54, 21, NULL, 1, 75.00, 1, 75.00),
(55, 22, NULL, 8, 50.00, 20, 1000.00),
(56, 22, NULL, 1, 70.00, 40, 2800.00),
(57, 22, NULL, 10, 58.00, 10, 580.00),
(58, 22, NULL, 7, 35.00, 40, 1400.00),
(59, 23, NULL, 14, 1296.00, 5, 6480.00),
(60, 24, NULL, 7, 38.00, 20, 760.00),
(61, 25, NULL, 7, 38.00, 5, 190.00),
(62, 25, NULL, 10, 62.00, 1, 62.00),
(63, 25, NULL, 11, 15.00, 5, 75.00),
(64, 25, NULL, 6, 20.00, 5, 100.00),
(65, 25, NULL, 4, 9.00, 2, 18.00),
(66, 26, NULL, 12, 12.00, 12, 144.00),
(67, 26, NULL, 7, 38.00, 4, 152.00),
(68, 26, NULL, 8, 55.00, 4, 220.00),
(69, 26, NULL, 1, 75.00, 3, 225.00),
(70, 27, NULL, 1, 75.00, 8, 600.00),
(71, 27, NULL, 6, 20.00, 20, 400.00),
(72, 27, NULL, 3, 75.00, 15, 1125.00),
(73, 28, NULL, 2, 72.00, 8, 576.00),
(74, 29, NULL, 8, 55.00, 8, 440.00),
(75, 29, NULL, 12, 12.00, 11, 132.00),
(76, 29, NULL, 7, 38.00, 10, 380.00),
(77, 29, NULL, 4, 9.00, 5, 45.00);

-- --------------------------------------------------------

--
-- Table structure for table `tblsale_item_legacy_backup`
--

CREATE TABLE `tblsale_item_legacy_backup` (
  `sale_item_id` int(11) NOT NULL DEFAULT 0,
  `sale_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `product_name` varchar(150) NOT NULL,
  `unit` varchar(30) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `quantity` int(11) NOT NULL,
  `is_vatable` tinyint(1) NOT NULL DEFAULT 1,
  `line_total` decimal(12,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tblsale_item_legacy_backup`
--

INSERT INTO `tblsale_item_legacy_backup` (`sale_item_id`, `sale_id`, `product_id`, `product_name`, `unit`, `unit_price`, `quantity`, `is_vatable`, `line_total`) VALUES
(1, 1, 8, 'Argentina Corned Beef', 'Can', 48.00, 2, 1, 96.00),
(2, 1, 12, 'Bear Brand Powdered Milk', 'Sachet', 10.00, 1, 1, 10.00),
(3, 2, 1, 'Coca-Cola 1.5L', 'Bottle', 75.00, 1, 1, 75.00),
(4, 3, 7, 'Century Tuna Flakes', 'Can', 33.00, 1, 1, 33.00);

-- --------------------------------------------------------

--
-- Table structure for table `tblsale_legacy_backup`
--

CREATE TABLE `tblsale_legacy_backup` (
  `sale_id` int(11) NOT NULL DEFAULT 0,
  `receipt_number` varchar(30) NOT NULL,
  `counter_id` int(11) NOT NULL,
  `cashier_id` int(11) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `ar_account_id` int(11) DEFAULT NULL,
  `suki_card_id` int(11) DEFAULT NULL,
  `discount_id` int(11) DEFAULT NULL,
  `payment_type_id` int(11) DEFAULT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `vat_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount_tendered` decimal(12,2) NOT NULL DEFAULT 0.00,
  `change_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `points_earned` int(11) NOT NULL DEFAULT 0,
  `sale_status` varchar(20) NOT NULL DEFAULT 'Completed',
  `void_reason` varchar(255) DEFAULT NULL,
  `voided_by` int(11) DEFAULT NULL,
  `voided_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tblsale_legacy_backup`
--

INSERT INTO `tblsale_legacy_backup` (`sale_id`, `receipt_number`, `counter_id`, `cashier_id`, `customer_id`, `ar_account_id`, `suki_card_id`, `discount_id`, `payment_type_id`, `subtotal`, `discount_amount`, `vat_amount`, `total_amount`, `amount_tendered`, `change_amount`, `points_earned`, `sale_status`, `void_reason`, `voided_by`, `voided_at`, `created_at`) VALUES
(1, 'OR-20261001-00001', 1, 2, 2, NULL, 2, 2, 1, 106.00, 21.20, 9.09, 84.80, 100.00, 15.20, 0, 'Completed', NULL, NULL, NULL, '2026-10-01 09:18:57'),
(2, 'OR-20261001-00002', 1, 2, NULL, NULL, NULL, NULL, 1, 75.00, 0.00, 8.04, 75.00, 78.00, 3.00, 0, 'Completed', NULL, NULL, NULL, '2026-10-01 09:19:27'),
(3, 'OR-20261001-00003', 1, 2, 2, 1, 2, NULL, NULL, 33.00, 0.00, 3.54, 33.00, 0.00, 0.00, 0, 'Completed', NULL, NULL, NULL, '2026-10-01 09:51:34');

-- --------------------------------------------------------

--
-- Table structure for table `tblsuki_card`
--

CREATE TABLE `tblsuki_card` (
  `suki_card_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `card_number` varchar(30) NOT NULL,
  `points_balance` int(11) NOT NULL DEFAULT 0,
  `date_issued` date NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tblsuki_card`
--

INSERT INTO `tblsuki_card` (`suki_card_id`, `customer_id`, `card_number`, `points_balance`, `date_issued`, `is_active`) VALUES
(1, 1, 'SUKI-0001', 158, '2025-02-01', 1),
(2, 2, 'SUKI-0002', 1, '2025-01-20', 1),
(3, 3, 'SUKI-0003', 100, '2025-03-05', 1);

-- --------------------------------------------------------

--
-- Table structure for table `tblsuki_point_adjustment`
--

CREATE TABLE `tblsuki_point_adjustment` (
  `adjustment_id` int(11) NOT NULL,
  `suki_card_id` int(11) NOT NULL,
  `previous_balance` int(11) NOT NULL,
  `adjustment_amount` int(11) NOT NULL,
  `new_balance` int(11) NOT NULL,
  `reason` varchar(255) NOT NULL,
  `adjusted_by` int(11) NOT NULL,
  `adjusted_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tblsuki_point_adjustment`
--

INSERT INTO `tblsuki_point_adjustment` (`adjustment_id`, `suki_card_id`, `previous_balance`, `adjustment_amount`, `new_balance`, `reason`, `adjusted_by`, `adjusted_at`) VALUES
(1, 3, 120, -20, 100, 'testing', 1, '2026-10-22 15:32:41');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `tblactivity_log`
--
ALTER TABLE `tblactivity_log`
  ADD PRIMARY KEY (`activity_id`),
  ADD KEY `idx_activity_created` (`created_at`),
  ADD KEY `fk_activity_employee` (`employee_id`),
  ADD KEY `idx_audit_module_action` (`module`,`action`),
  ADD KEY `idx_audit_employee_created` (`employee_id`,`created_at`),
  ADD KEY `idx_audit_outcome_created` (`outcome`,`created_at`),
  ADD KEY `fk_activity_authorized_by` (`authorized_by`);

--
-- Indexes for table `tblar_account`
--
ALTER TABLE `tblar_account`
  ADD PRIMARY KEY (`ar_account_id`),
  ADD UNIQUE KEY `uq_ar_customer` (`customer_id`),
  ADD KEY `fk_ar_employee` (`approved_by`),
  ADD KEY `fk_ar_credit_term` (`credit_term_id`),
  ADD KEY `fk_ar_hold_invoice` (`credit_hold_invoice_id`),
  ADD KEY `fk_ar_hold_released_by` (`credit_hold_released_by`);

--
-- Indexes for table `tblar_audit`
--
ALTER TABLE `tblar_audit`
  ADD PRIMARY KEY (`audit_id`),
  ADD KEY `idx_ar_audit_entity` (`entity_type`,`entity_id`),
  ADD KEY `idx_ar_audit_account` (`ar_account_id`),
  ADD KEY `idx_ar_audit_created` (`created_at`),
  ADD KEY `fk_ar_audit_employee` (`employee_id`),
  ADD KEY `fk_ar_audit_auth` (`authorized_by`);

--
-- Indexes for table `tblcashier_shift`
--
ALTER TABLE `tblcashier_shift`
  ADD PRIMARY KEY (`shift_id`),
  ADD UNIQUE KEY `uq_active_counter` (`active_counter_key`),
  ADD UNIQUE KEY `uq_active_employee` (`active_employee_key`),
  ADD KEY `idx_shift_lookup` (`employee_id`,`counter_id`,`shift_status`),
  ADD KEY `fk_shift_counter` (`counter_id`),
  ADD KEY `fk_shift_authorizer` (`authorized_by`),
  ADD KEY `fk_shift_deposit_verifier` (`deposit_verified_by`),
  ADD KEY `idx_shift_day` (`shift_date`,`counter_id`,`shift_status`);

--
-- Indexes for table `tblcash_float`
--
ALTER TABLE `tblcash_float`
  ADD PRIMARY KEY (`float_id`),
  ADD KEY `idx_cash_float_counter` (`counter_id`),
  ADD KEY `idx_cash_float_employee` (`employee_id`),
  ADD KEY `idx_cash_float_created` (`created_at`),
  ADD KEY `fk_cash_float_supervisor` (`supervisor_id`),
  ADD KEY `idx_float_shift` (`shift_id`);

--
-- Indexes for table `tblcounter`
--
ALTER TABLE `tblcounter`
  ADD PRIMARY KEY (`counter_id`),
  ADD UNIQUE KEY `counter_number` (`counter_number`);

--
-- Indexes for table `tblcredit_memo`
--
ALTER TABLE `tblcredit_memo`
  ADD PRIMARY KEY (`credit_memo_id`),
  ADD UNIQUE KEY `uq_credit_memo_reference` (`reference_no`),
  ADD KEY `fk_credit_memo_invoice` (`invoice_id`);

--
-- Indexes for table `tblcredit_term`
--
ALTER TABLE `tblcredit_term`
  ADD PRIMARY KEY (`credit_term_id`);

--
-- Indexes for table `tblcustomer`
--
ALTER TABLE `tblcustomer`
  ADD PRIMARY KEY (`customer_id`),
  ADD KEY `fk_customer_price_tier` (`price_tier_id`);

--
-- Indexes for table `tbldebit_memo`
--
ALTER TABLE `tbldebit_memo`
  ADD PRIMARY KEY (`debit_memo_id`),
  ADD UNIQUE KEY `uq_debit_memo_reference` (`reference_no`),
  ADD KEY `fk_debit_memo_invoice` (`invoice_id`);

--
-- Indexes for table `tbldiscount`
--
ALTER TABLE `tbldiscount`
  ADD PRIMARY KEY (`discount_id`);

--
-- Indexes for table `tblemployee`
--
ALTER TABLE `tblemployee`
  ADD PRIMARY KEY (`employee_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `fk_employee_role` (`role_id`);

--
-- Indexes for table `tblend_day`
--
ALTER TABLE `tblend_day`
  ADD PRIMARY KEY (`end_day_id`),
  ADD UNIQUE KEY `uq_end_day_date` (`business_date`),
  ADD KEY `fk_end_day_finalizer` (`finalized_by`);

--
-- Indexes for table `tblend_day_event`
--
ALTER TABLE `tblend_day_event`
  ADD PRIMARY KEY (`event_id`),
  ADD KEY `idx_end_day_event_day` (`end_day_id`,`created_at`),
  ADD KEY `fk_end_day_event_actor` (`actor_id`);

--
-- Indexes for table `tblgift_certificate`
--
ALTER TABLE `tblgift_certificate`
  ADD PRIMARY KEY (`gc_id`),
  ADD KEY `fk_gc_suki_card` (`suki_card_id`);

--
-- Indexes for table `tblgift_certificate_code`
--
ALTER TABLE `tblgift_certificate_code`
  ADD PRIMARY KEY (`gcc_id`),
  ADD UNIQUE KEY `uq_gcc_code` (`code`),
  ADD KEY `idx_gcc_status` (`status`),
  ADD KEY `fk_gcc_issued_by` (`issued_by`),
  ADD KEY `fk_gcc_redeemed_sale` (`redeemed_sale_id`);

--
-- Indexes for table `tblheld_transaction`
--
ALTER TABLE `tblheld_transaction`
  ADD PRIMARY KEY (`held_id`),
  ADD KEY `idx_held_employee` (`employee_id`),
  ADD KEY `idx_held_counter` (`counter_id`),
  ADD KEY `fk_held_customer` (`customer_id`),
  ADD KEY `fk_held_discount` (`discount_id`);

--
-- Indexes for table `tblinvoice`
--
ALTER TABLE `tblinvoice`
  ADD PRIMARY KEY (`invoice_id`),
  ADD UNIQUE KEY `uq_invoice_sale` (`sale_id`),
  ADD KEY `fk_invoice_ar_account` (`ar_account_id`),
  ADD KEY `idx_invoice_ar_due` (`ar_account_id`,`due_date`);

--
-- Indexes for table `tblpayment`
--
ALTER TABLE `tblpayment`
  ADD PRIMARY KEY (`payment_id`),
  ADD UNIQUE KEY `uq_payment_gc` (`gc_id`),
  ADD UNIQUE KEY `uq_payment_token` (`idempotency_token`),
  ADD KEY `fk_payment_ar_account` (`ar_account_id`),
  ADD KEY `fk_payment_type` (`payment_type_id`),
  ADD KEY `fk_payment_invoice` (`invoice_id`),
  ADD KEY `fk_payment_created_by` (`created_by`),
  ADD KEY `idx_payment_ar_date` (`ar_account_id`,`payment_date`),
  ADD KEY `fk_payment_gcc` (`gcc_id`);

--
-- Indexes for table `tblpayment_allocation`
--
ALTER TABLE `tblpayment_allocation`
  ADD PRIMARY KEY (`allocation_id`),
  ADD KEY `idx_alloc_invoice` (`invoice_id`),
  ADD KEY `idx_alloc_payment` (`payment_id`),
  ADD KEY `idx_alloc_created` (`created_at`),
  ADD KEY `fk_alloc_reversal` (`reversal_id`),
  ADD KEY `fk_alloc_created` (`created_by`);

--
-- Indexes for table `tblpayment_reversal`
--
ALTER TABLE `tblpayment_reversal`
  ADD PRIMARY KEY (`reversal_id`),
  ADD KEY `idx_reversal_payment` (`payment_id`),
  ADD KEY `fk_reversal_authorized` (`authorized_by`),
  ADD KEY `fk_reversal_created` (`created_by`);

--
-- Indexes for table `tblpayment_type`
--
ALTER TABLE `tblpayment_type`
  ADD PRIMARY KEY (`payment_type_id`);

--
-- Indexes for table `tblpoints_redemption`
--
ALTER TABLE `tblpoints_redemption`
  ADD PRIMARY KEY (`redemption_id`),
  ADD UNIQUE KEY `uq_redemption_payment` (`payment_id`),
  ADD KEY `fk_redemption_suki_card` (`suki_card_id`);

--
-- Indexes for table `tblprice_tier`
--
ALTER TABLE `tblprice_tier`
  ADD PRIMARY KEY (`price_tier_id`);

--
-- Indexes for table `tblproduct`
--
ALTER TABLE `tblproduct`
  ADD PRIMARY KEY (`product_id`),
  ADD UNIQUE KEY `uq_product_barcode` (`barcode`),
  ADD KEY `fk_product_category` (`category_id`),
  ADD KEY `fk_product_unit` (`unit_id`);

--
-- Indexes for table `tblproduct_category`
--
ALTER TABLE `tblproduct_category`
  ADD PRIMARY KEY (`category_id`);

--
-- Indexes for table `tblproduct_price`
--
ALTER TABLE `tblproduct_price`
  ADD PRIMARY KEY (`product_price_id`),
  ADD UNIQUE KEY `uq_product_tier` (`product_id`,`price_tier_id`),
  ADD KEY `fk_price_tier` (`price_tier_id`);

--
-- Indexes for table `tblproduct_unit`
--
ALTER TABLE `tblproduct_unit`
  ADD PRIMARY KEY (`unit_id`);

--
-- Indexes for table `tblrole`
--
ALTER TABLE `tblrole`
  ADD PRIMARY KEY (`role_id`);

--
-- Indexes for table `tblsale`
--
ALTER TABLE `tblsale`
  ADD PRIMARY KEY (`sale_id`),
  ADD UNIQUE KEY `receipt_number` (`receipt_number`),
  ADD KEY `idx_sale_created` (`sale_date`),
  ADD KEY `fk_sale_counter` (`counter_id`),
  ADD KEY `fk_sale_suki` (`suki_card_id`),
  ADD KEY `fk_sale_voided_by` (`voided_by`),
  ADD KEY `fk_sale_employee` (`employee_id`),
  ADD KEY `fk_sale_customer` (`customer_id`);

--
-- Indexes for table `tblsale_item`
--
ALTER TABLE `tblsale_item`
  ADD PRIMARY KEY (`sale_item_id`),
  ADD KEY `fk_saleitem_sale` (`sale_id`),
  ADD KEY `fk_saleitem_product` (`product_id`),
  ADD KEY `fk_saleitem_discount` (`discount_id`);

--
-- Indexes for table `tblsuki_card`
--
ALTER TABLE `tblsuki_card`
  ADD PRIMARY KEY (`suki_card_id`),
  ADD UNIQUE KEY `card_number` (`card_number`),
  ADD UNIQUE KEY `uq_suki_customer` (`customer_id`);

--
-- Indexes for table `tblsuki_point_adjustment`
--
ALTER TABLE `tblsuki_point_adjustment`
  ADD PRIMARY KEY (`adjustment_id`),
  ADD KEY `fk_spa_card` (`suki_card_id`),
  ADD KEY `fk_spa_employee` (`adjusted_by`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `tblactivity_log`
--
ALTER TABLE `tblactivity_log`
  MODIFY `activity_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=119;

--
-- AUTO_INCREMENT for table `tblar_account`
--
ALTER TABLE `tblar_account`
  MODIFY `ar_account_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `tblar_audit`
--
ALTER TABLE `tblar_audit`
  MODIFY `audit_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `tblcashier_shift`
--
ALTER TABLE `tblcashier_shift`
  MODIFY `shift_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `tblcash_float`
--
ALTER TABLE `tblcash_float`
  MODIFY `float_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `tblcounter`
--
ALTER TABLE `tblcounter`
  MODIFY `counter_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `tblcredit_memo`
--
ALTER TABLE `tblcredit_memo`
  MODIFY `credit_memo_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tblcredit_term`
--
ALTER TABLE `tblcredit_term`
  MODIFY `credit_term_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `tblcustomer`
--
ALTER TABLE `tblcustomer`
  MODIFY `customer_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `tbldebit_memo`
--
ALTER TABLE `tbldebit_memo`
  MODIFY `debit_memo_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tbldiscount`
--
ALTER TABLE `tbldiscount`
  MODIFY `discount_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `tblemployee`
--
ALTER TABLE `tblemployee`
  MODIFY `employee_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `tblend_day`
--
ALTER TABLE `tblend_day`
  MODIFY `end_day_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `tblend_day_event`
--
ALTER TABLE `tblend_day_event`
  MODIFY `event_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `tblgift_certificate`
--
ALTER TABLE `tblgift_certificate`
  MODIFY `gc_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tblgift_certificate_code`
--
ALTER TABLE `tblgift_certificate_code`
  MODIFY `gcc_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `tblheld_transaction`
--
ALTER TABLE `tblheld_transaction`
  MODIFY `held_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `tblinvoice`
--
ALTER TABLE `tblinvoice`
  MODIFY `invoice_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT for table `tblpayment`
--
ALTER TABLE `tblpayment`
  MODIFY `payment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT for table `tblpayment_allocation`
--
ALTER TABLE `tblpayment_allocation`
  MODIFY `allocation_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `tblpayment_reversal`
--
ALTER TABLE `tblpayment_reversal`
  MODIFY `reversal_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `tblpayment_type`
--
ALTER TABLE `tblpayment_type`
  MODIFY `payment_type_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `tblpoints_redemption`
--
ALTER TABLE `tblpoints_redemption`
  MODIFY `redemption_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `tblprice_tier`
--
ALTER TABLE `tblprice_tier`
  MODIFY `price_tier_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `tblproduct`
--
ALTER TABLE `tblproduct`
  MODIFY `product_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `tblproduct_category`
--
ALTER TABLE `tblproduct_category`
  MODIFY `category_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `tblproduct_price`
--
ALTER TABLE `tblproduct_price`
  MODIFY `product_price_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=88;

--
-- AUTO_INCREMENT for table `tblproduct_unit`
--
ALTER TABLE `tblproduct_unit`
  MODIFY `unit_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `tblrole`
--
ALTER TABLE `tblrole`
  MODIFY `role_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `tblsale`
--
ALTER TABLE `tblsale`
  MODIFY `sale_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT for table `tblsale_item`
--
ALTER TABLE `tblsale_item`
  MODIFY `sale_item_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=78;

--
-- AUTO_INCREMENT for table `tblsuki_card`
--
ALTER TABLE `tblsuki_card`
  MODIFY `suki_card_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `tblsuki_point_adjustment`
--
ALTER TABLE `tblsuki_point_adjustment`
  MODIFY `adjustment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `tblactivity_log`
--
ALTER TABLE `tblactivity_log`
  ADD CONSTRAINT `fk_activity_authorized_by` FOREIGN KEY (`authorized_by`) REFERENCES `tblemployee` (`employee_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_activity_employee` FOREIGN KEY (`employee_id`) REFERENCES `tblemployee` (`employee_id`);

--
-- Constraints for table `tblar_account`
--
ALTER TABLE `tblar_account`
  ADD CONSTRAINT `fk_ar_credit_term` FOREIGN KEY (`credit_term_id`) REFERENCES `tblcredit_term` (`credit_term_id`),
  ADD CONSTRAINT `fk_ar_customer` FOREIGN KEY (`customer_id`) REFERENCES `tblcustomer` (`customer_id`),
  ADD CONSTRAINT `fk_ar_employee` FOREIGN KEY (`approved_by`) REFERENCES `tblemployee` (`employee_id`),
  ADD CONSTRAINT `fk_ar_hold_invoice` FOREIGN KEY (`credit_hold_invoice_id`) REFERENCES `tblinvoice` (`invoice_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_ar_hold_released_by` FOREIGN KEY (`credit_hold_released_by`) REFERENCES `tblemployee` (`employee_id`) ON DELETE SET NULL;

--
-- Constraints for table `tblar_audit`
--
ALTER TABLE `tblar_audit`
  ADD CONSTRAINT `fk_ar_audit_auth` FOREIGN KEY (`authorized_by`) REFERENCES `tblemployee` (`employee_id`),
  ADD CONSTRAINT `fk_ar_audit_employee` FOREIGN KEY (`employee_id`) REFERENCES `tblemployee` (`employee_id`);

--
-- Constraints for table `tblcashier_shift`
--
ALTER TABLE `tblcashier_shift`
  ADD CONSTRAINT `fk_shift_authorizer` FOREIGN KEY (`authorized_by`) REFERENCES `tblemployee` (`employee_id`),
  ADD CONSTRAINT `fk_shift_counter` FOREIGN KEY (`counter_id`) REFERENCES `tblcounter` (`counter_id`),
  ADD CONSTRAINT `fk_shift_deposit_verifier` FOREIGN KEY (`deposit_verified_by`) REFERENCES `tblemployee` (`employee_id`),
  ADD CONSTRAINT `fk_shift_employee` FOREIGN KEY (`employee_id`) REFERENCES `tblemployee` (`employee_id`);

--
-- Constraints for table `tblcash_float`
--
ALTER TABLE `tblcash_float`
  ADD CONSTRAINT `fk_cash_float_counter` FOREIGN KEY (`counter_id`) REFERENCES `tblcounter` (`counter_id`),
  ADD CONSTRAINT `fk_cash_float_employee` FOREIGN KEY (`employee_id`) REFERENCES `tblemployee` (`employee_id`),
  ADD CONSTRAINT `fk_cash_float_supervisor` FOREIGN KEY (`supervisor_id`) REFERENCES `tblemployee` (`employee_id`),
  ADD CONSTRAINT `fk_float_shift` FOREIGN KEY (`shift_id`) REFERENCES `tblcashier_shift` (`shift_id`);

--
-- Constraints for table `tblcredit_memo`
--
ALTER TABLE `tblcredit_memo`
  ADD CONSTRAINT `fk_credit_memo_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `tblinvoice` (`invoice_id`);

--
-- Constraints for table `tblcustomer`
--
ALTER TABLE `tblcustomer`
  ADD CONSTRAINT `fk_customer_price_tier` FOREIGN KEY (`price_tier_id`) REFERENCES `tblprice_tier` (`price_tier_id`);

--
-- Constraints for table `tbldebit_memo`
--
ALTER TABLE `tbldebit_memo`
  ADD CONSTRAINT `fk_debit_memo_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `tblinvoice` (`invoice_id`);

--
-- Constraints for table `tblemployee`
--
ALTER TABLE `tblemployee`
  ADD CONSTRAINT `fk_employee_role` FOREIGN KEY (`role_id`) REFERENCES `tblrole` (`role_id`);

--
-- Constraints for table `tblend_day`
--
ALTER TABLE `tblend_day`
  ADD CONSTRAINT `fk_end_day_finalizer` FOREIGN KEY (`finalized_by`) REFERENCES `tblemployee` (`employee_id`);

--
-- Constraints for table `tblend_day_event`
--
ALTER TABLE `tblend_day_event`
  ADD CONSTRAINT `fk_end_day_event_actor` FOREIGN KEY (`actor_id`) REFERENCES `tblemployee` (`employee_id`),
  ADD CONSTRAINT `fk_end_day_event_day` FOREIGN KEY (`end_day_id`) REFERENCES `tblend_day` (`end_day_id`);

--
-- Constraints for table `tblgift_certificate`
--
ALTER TABLE `tblgift_certificate`
  ADD CONSTRAINT `fk_gc_suki_card` FOREIGN KEY (`suki_card_id`) REFERENCES `tblsuki_card` (`suki_card_id`);

--
-- Constraints for table `tblgift_certificate_code`
--
ALTER TABLE `tblgift_certificate_code`
  ADD CONSTRAINT `fk_gcc_issued_by` FOREIGN KEY (`issued_by`) REFERENCES `tblemployee` (`employee_id`),
  ADD CONSTRAINT `fk_gcc_redeemed_sale` FOREIGN KEY (`redeemed_sale_id`) REFERENCES `tblsale` (`sale_id`);

--
-- Constraints for table `tblheld_transaction`
--
ALTER TABLE `tblheld_transaction`
  ADD CONSTRAINT `fk_held_counter` FOREIGN KEY (`counter_id`) REFERENCES `tblcounter` (`counter_id`),
  ADD CONSTRAINT `fk_held_customer` FOREIGN KEY (`customer_id`) REFERENCES `tblcustomer` (`customer_id`),
  ADD CONSTRAINT `fk_held_discount` FOREIGN KEY (`discount_id`) REFERENCES `tbldiscount` (`discount_id`),
  ADD CONSTRAINT `fk_held_employee` FOREIGN KEY (`employee_id`) REFERENCES `tblemployee` (`employee_id`);

--
-- Constraints for table `tblinvoice`
--
ALTER TABLE `tblinvoice`
  ADD CONSTRAINT `fk_invoice_ar_account` FOREIGN KEY (`ar_account_id`) REFERENCES `tblar_account` (`ar_account_id`),
  ADD CONSTRAINT `fk_invoice_sale` FOREIGN KEY (`sale_id`) REFERENCES `tblsale` (`sale_id`);

--
-- Constraints for table `tblpayment`
--
ALTER TABLE `tblpayment`
  ADD CONSTRAINT `fk_payment_ar_account` FOREIGN KEY (`ar_account_id`) REFERENCES `tblar_account` (`ar_account_id`),
  ADD CONSTRAINT `fk_payment_created_by` FOREIGN KEY (`created_by`) REFERENCES `tblemployee` (`employee_id`),
  ADD CONSTRAINT `fk_payment_gc` FOREIGN KEY (`gc_id`) REFERENCES `tblgift_certificate` (`gc_id`),
  ADD CONSTRAINT `fk_payment_gcc` FOREIGN KEY (`gcc_id`) REFERENCES `tblgift_certificate_code` (`gcc_id`),
  ADD CONSTRAINT `fk_payment_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `tblinvoice` (`invoice_id`),
  ADD CONSTRAINT `fk_payment_type` FOREIGN KEY (`payment_type_id`) REFERENCES `tblpayment_type` (`payment_type_id`);

--
-- Constraints for table `tblpayment_allocation`
--
ALTER TABLE `tblpayment_allocation`
  ADD CONSTRAINT `fk_alloc_created` FOREIGN KEY (`created_by`) REFERENCES `tblemployee` (`employee_id`),
  ADD CONSTRAINT `fk_alloc_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `tblinvoice` (`invoice_id`),
  ADD CONSTRAINT `fk_alloc_payment` FOREIGN KEY (`payment_id`) REFERENCES `tblpayment` (`payment_id`),
  ADD CONSTRAINT `fk_alloc_reversal` FOREIGN KEY (`reversal_id`) REFERENCES `tblpayment_reversal` (`reversal_id`);

--
-- Constraints for table `tblpayment_reversal`
--
ALTER TABLE `tblpayment_reversal`
  ADD CONSTRAINT `fk_reversal_authorized` FOREIGN KEY (`authorized_by`) REFERENCES `tblemployee` (`employee_id`),
  ADD CONSTRAINT `fk_reversal_created` FOREIGN KEY (`created_by`) REFERENCES `tblemployee` (`employee_id`),
  ADD CONSTRAINT `fk_reversal_payment` FOREIGN KEY (`payment_id`) REFERENCES `tblpayment` (`payment_id`);

--
-- Constraints for table `tblpoints_redemption`
--
ALTER TABLE `tblpoints_redemption`
  ADD CONSTRAINT `fk_redemption_payment` FOREIGN KEY (`payment_id`) REFERENCES `tblpayment` (`payment_id`),
  ADD CONSTRAINT `fk_redemption_suki_card` FOREIGN KEY (`suki_card_id`) REFERENCES `tblsuki_card` (`suki_card_id`);

--
-- Constraints for table `tblproduct`
--
ALTER TABLE `tblproduct`
  ADD CONSTRAINT `fk_product_category` FOREIGN KEY (`category_id`) REFERENCES `tblproduct_category` (`category_id`),
  ADD CONSTRAINT `fk_product_unit` FOREIGN KEY (`unit_id`) REFERENCES `tblproduct_unit` (`unit_id`);

--
-- Constraints for table `tblproduct_price`
--
ALTER TABLE `tblproduct_price`
  ADD CONSTRAINT `fk_price_product` FOREIGN KEY (`product_id`) REFERENCES `tblproduct` (`product_id`),
  ADD CONSTRAINT `fk_price_tier` FOREIGN KEY (`price_tier_id`) REFERENCES `tblprice_tier` (`price_tier_id`);

--
-- Constraints for table `tblsale`
--
ALTER TABLE `tblsale`
  ADD CONSTRAINT `fk_sale_counter` FOREIGN KEY (`counter_id`) REFERENCES `tblcounter` (`counter_id`),
  ADD CONSTRAINT `fk_sale_customer` FOREIGN KEY (`customer_id`) REFERENCES `tblcustomer` (`customer_id`),
  ADD CONSTRAINT `fk_sale_employee` FOREIGN KEY (`employee_id`) REFERENCES `tblemployee` (`employee_id`),
  ADD CONSTRAINT `fk_sale_suki` FOREIGN KEY (`suki_card_id`) REFERENCES `tblsuki_card` (`suki_card_id`),
  ADD CONSTRAINT `fk_sale_voided_by` FOREIGN KEY (`voided_by`) REFERENCES `tblemployee` (`employee_id`);

--
-- Constraints for table `tblsale_item`
--
ALTER TABLE `tblsale_item`
  ADD CONSTRAINT `fk_saleitem_discount` FOREIGN KEY (`discount_id`) REFERENCES `tbldiscount` (`discount_id`),
  ADD CONSTRAINT `fk_saleitem_product` FOREIGN KEY (`product_id`) REFERENCES `tblproduct` (`product_id`),
  ADD CONSTRAINT `fk_saleitem_sale` FOREIGN KEY (`sale_id`) REFERENCES `tblsale` (`sale_id`);

--
-- Constraints for table `tblsuki_card`
--
ALTER TABLE `tblsuki_card`
  ADD CONSTRAINT `fk_suki_customer` FOREIGN KEY (`customer_id`) REFERENCES `tblcustomer` (`customer_id`);

--
-- Constraints for table `tblsuki_point_adjustment`
--
ALTER TABLE `tblsuki_point_adjustment`
  ADD CONSTRAINT `fk_spa_card` FOREIGN KEY (`suki_card_id`) REFERENCES `tblsuki_card` (`suki_card_id`),
  ADD CONSTRAINT `fk_spa_employee` FOREIGN KEY (`adjusted_by`) REFERENCES `tblemployee` (`employee_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
