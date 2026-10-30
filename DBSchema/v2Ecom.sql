-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 30, 2026 at 10:45 PM
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
-- Database: `v2ecom`
--

-- --------------------------------------------------------

--
-- Table structure for table `accounting_coa`
--

CREATE TABLE `accounting_coa` (
  `id` int(10) UNSIGNED NOT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `type` enum('asset','liability','equity','revenue','expense') NOT NULL,
  `sub_type` varchar(50) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `accounting_coa`
--

INSERT INTO `accounting_coa` (`id`, `code`, `name`, `type`, `sub_type`, `description`, `is_active`, `created_at`) VALUES
(1, '1010', 'Main Operating Bank Account', 'asset', 'bank', 'Primary commercial bank account for store operations', 1, '2026-10-31 00:36:37'),
(2, '1020', 'Cash on Hand (Petty Cash)', 'asset', 'cash', 'Physical cash float for day-to-day minor office expenses', 1, '2026-10-31 00:36:37'),
(3, '1030', 'Courier COD Receivable', 'asset', 'receivable', 'Undisbursed funds currently held by courier delivery partners', 1, '2026-10-31 00:36:37'),
(4, '1040', 'Inventory Asset', 'asset', 'inventory', 'Current cost valuation of physical inventory in stock', 1, '2026-10-31 00:36:37'),
(5, '2010', 'Accounts Payable (Vendors)', 'liability', 'payable', 'Unpaid balance owed to product manufacturers and suppliers', 1, '2026-10-31 00:36:37'),
(6, '2020', 'Short-Term Loans & Credit Lines', 'liability', 'loan', 'Short term borrowings or business credit lines', 1, '2026-10-31 00:36:37'),
(7, '3010', 'Founder / Owner Initial Capital', 'equity', 'capital', 'Initial seed capital invested into the business', 1, '2026-10-31 00:36:37'),
(8, '3020', 'Stakeholder Capital Accounts', 'equity', 'capital', 'Equity contributions from other active investors/partners', 1, '2026-10-31 00:36:37'),
(9, '3030', 'Retained Earnings', 'equity', 'equity', 'Cumulative net business earnings preserved in the company', 1, '2026-10-31 00:36:37'),
(10, '4010', 'Gross Product Sales Revenue', 'revenue', 'sales', 'Raw revenue derived from fulfilled storefront orders', 1, '2026-10-31 00:36:37'),
(11, '4020', 'Shipping Revenue Collected', 'revenue', 'shipping', 'Delivery charges collected from customer orders', 1, '2026-10-31 00:36:37'),
(12, '5010', 'Cost of Goods Sold (COGS)', 'expense', 'cogs', 'Purchase and manufacturing cost of units sold to customers', 1, '2026-10-31 00:36:37'),
(13, '6010', 'Advertising & Marketing (Meta/Google)', 'expense', 'opex', 'Online and offline promotional expenditure', 1, '2026-10-31 00:36:37'),
(14, '6020', 'Office & Warehouse Rent', 'expense', 'opex', 'Monthly rental obligations for operational facilities', 1, '2026-10-31 00:36:37'),
(15, '6030', 'Utilities, Software & Hosting', 'expense', 'opex', 'Internet, cloud infrastructure, domain, SaaS tools, and power', 1, '2026-10-31 00:36:37'),
(16, '6040', 'Courier Freight & Shipping Loss', 'expense', 'opex', 'Net return fees, courier service charges, or transport overhead', 1, '2026-10-31 00:36:37'),
(17, '6050', 'Packaging & Dispatch Supplies', 'expense', 'opex', 'Poly mailers, boxes, bubble wrap, tape, and printing labels', 1, '2026-10-31 00:36:37'),
(18, '6060', 'Employee Salaries & Sales Wages', 'expense', 'opex', 'Regular staff payroll and field performance stipends', 1, '2026-10-31 00:36:37'),
(19, '6070', 'General Office & Petty Expenses', 'expense', 'opex', 'Refreshments, minor repairs, and incidental operational charges', 1, '2026-10-31 00:36:37');

-- --------------------------------------------------------

--
-- Table structure for table `accounting_courier_settlements`
--

CREATE TABLE `accounting_courier_settlements` (
  `id` int(10) UNSIGNED NOT NULL,
  `settlement_ref` varchar(100) NOT NULL,
  `courier_name` varchar(100) NOT NULL,
  `settlement_date` date NOT NULL,
  `gross_collected` decimal(12,2) NOT NULL DEFAULT 0.00,
  `courier_fees` decimal(12,2) NOT NULL DEFAULT 0.00,
  `net_received` decimal(12,2) NOT NULL DEFAULT 0.00,
  `destination_account` enum('bank','petty_cash') NOT NULL DEFAULT 'bank',
  `notes` text DEFAULT NULL,
  `recorded_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `accounting_expenses`
--

CREATE TABLE `accounting_expenses` (
  `id` int(10) UNSIGNED NOT NULL,
  `account_code` varchar(20) NOT NULL,
  `title` varchar(150) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `payment_source` enum('bank','petty_cash') NOT NULL DEFAULT 'petty_cash',
  `expense_date` date NOT NULL,
  `receipt_reference` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `recorded_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `accounting_journals`
--

CREATE TABLE `accounting_journals` (
  `id` int(10) UNSIGNED NOT NULL,
  `entry_number` varchar(50) NOT NULL,
  `entry_date` date NOT NULL,
  `reference_type` enum('manual','order_sale','cogs','vendor_bill','vendor_payment','expense','courier_settlement') NOT NULL,
  `reference_id` int(10) UNSIGNED DEFAULT NULL,
  `memo` varchar(255) NOT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `accounting_journal_lines`
--

CREATE TABLE `accounting_journal_lines` (
  `id` int(10) UNSIGNED NOT NULL,
  `journal_id` int(10) UNSIGNED NOT NULL,
  `account_code` varchar(20) NOT NULL,
  `debit` decimal(12,2) NOT NULL DEFAULT 0.00,
  `credit` decimal(12,2) NOT NULL DEFAULT 0.00,
  `description` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `accounting_stakeholders`
--

CREATE TABLE `accounting_stakeholders` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `role` varchar(50) DEFAULT 'Shareholder',
  `equity_percentage` decimal(5,2) NOT NULL DEFAULT 0.00,
  `total_contributed` decimal(12,2) NOT NULL DEFAULT 0.00,
  `phone` varchar(30) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `role` varchar(50) NOT NULL DEFAULT 'admin',
  `is_super_admin` tinyint(1) NOT NULL DEFAULT 0,
  `permissions` text DEFAULT NULL,
  `email` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `admins`
--

INSERT INTO `admins` (`id`, `name`, `role`, `is_super_admin`, `permissions`, `email`, `password_hash`, `created_at`) VALUES
(1, 'DiverseAdmin', 'Super Admin', 1, NULL, 'alam.office.my@gmail.com', '$2y$10$yOiaQpKA30BaoYjO1y0DmuxcxGn7Cq.DfmB9O7DkrSrkiMwuv19OK', '2026-10-18 10:25:09'),
(2, 'Shihab', 'UserOnly', 0, '[\"products\",\"orders\"]', 'shihab@az.com', '$2y$10$wXsorNAYQN/5s11ZWSYtruBKKLzAXpSnUS6fov9wGuDz9tJ7Mb7Xe', '2026-10-20 12:43:38'),
(4, 'Ashraful Alam Shihab', 'Order Processor', 0, '[\"products\",\"categories\"]', 'alamshihab526@gmail.com', '$2y$10$fWyq3vrkf1pQAWM61Vbzq.dELG11NdOb5WMmaJWxsUbQXeL40Dss6', '2026-10-20 18:35:47');

-- --------------------------------------------------------

--
-- Table structure for table `admin_activity_logs`
--

CREATE TABLE `admin_activity_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `admin_id` bigint(20) UNSIGNED DEFAULT NULL,
  `admin_name` varchar(120) DEFAULT NULL,
  `admin_email` varchar(190) DEFAULT NULL,
  `module` varchar(60) NOT NULL,
  `action` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `admin_login_logs`
--

CREATE TABLE `admin_login_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `admin_id` bigint(20) UNSIGNED DEFAULT NULL,
  `email_attempted` varchar(180) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` text NOT NULL,
  `status` enum('success','failed') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `admin_sessions`
--

CREATE TABLE `admin_sessions` (
  `id` varchar(128) NOT NULL,
  `admin_id` bigint(20) UNSIGNED NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` text NOT NULL,
  `last_activity` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `api_keys`
--

CREATE TABLE `api_keys` (
  `id` int(11) NOT NULL,
  `key_name` varchar(100) NOT NULL,
  `api_key` varchar(64) NOT NULL,
  `secret_token` varchar(255) NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `scopes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`scopes`)),
  `rate_limit` int(11) DEFAULT 1000,
  `requests_count` int(11) DEFAULT 0,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(120) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`id`, `name`, `slug`, `created_at`) VALUES
(1, 'Men Casual', 'men-casual', '2026-10-26 10:53:04'),
(2, 'Women Ethnic', 'women-ethnic', '2026-10-26 10:53:04'),
(3, 'Men Formal', 'men-formal', '2026-10-29 22:50:10'),
(4, 'Women Western', 'women-western', '2026-10-29 22:50:10'),
(5, 'Winter Collection', 'winter-collection', '2026-10-29 22:50:10'),
(6, 'Footwear & Accessories', 'footwear-accessories', '2026-10-29 22:50:10');

-- --------------------------------------------------------

--
-- Table structure for table `contacts`
--

CREATE TABLE `contacts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(160) NOT NULL,
  `email` varchar(190) NOT NULL,
  `subject` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `status` enum('new','read','replied','closed') NOT NULL DEFAULT 'new',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `coupons`
--

CREATE TABLE `coupons` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `code` varchar(60) NOT NULL,
  `discount_type` enum('percent','fixed') NOT NULL,
  `discount_value` decimal(10,2) NOT NULL,
  `min_order` decimal(10,2) NOT NULL DEFAULT 0.00,
  `max_discount` decimal(10,2) DEFAULT NULL,
  `usage_limit` int(10) UNSIGNED DEFAULT NULL,
  `used_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `starts_at` datetime DEFAULT NULL,
  `ends_at` datetime DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `coupons`
--

INSERT INTO `coupons` (`id`, `code`, `discount_type`, `discount_value`, `min_order`, `max_discount`, `usage_limit`, `used_count`, `starts_at`, `ends_at`, `active`, `created_at`) VALUES
(1, 'WELCOME10', 'percent', 10.00, 1000.00, 300.00, 500, 0, '2026-10-01 00:00:00', '2026-12-31 23:59:59', 1, '2026-10-29 22:50:10'),
(2, 'FESTIVE200', 'fixed', 200.00, 2000.00, 200.00, 200, 0, '2026-10-15 00:00:00', '2026-11-30 23:59:59', 1, '2026-10-29 22:50:10'),
(3, 'WINTERSALE', 'percent', 15.00, 2500.00, 600.00, 100, 0, '2026-10-20 00:00:00', '2026-12-15 23:59:59', 1, '2026-10-29 22:50:10');

-- --------------------------------------------------------

--
-- Table structure for table `coupon_redemptions`
--

CREATE TABLE `coupon_redemptions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `coupon_id` bigint(20) UNSIGNED NOT NULL,
  `order_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `discount_amount` decimal(10,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `courier_services`
--

CREATE TABLE `courier_services` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `courier_services`
--

INSERT INTO `courier_services` (`id`, `name`, `is_active`, `created_at`) VALUES
(1, 'Steadfast Courier', 1, '2026-10-31 01:59:08'),
(2, 'Pathao Courier', 1, '2026-10-31 01:59:08'),
(3, 'RedX Logistics', 1, '2026-10-31 01:59:08'),
(4, 'Paperfly', 1, '2026-10-31 01:59:08'),
(5, 'eCourier', 1, '2026-10-31 01:59:08'),
(6, 'Sundarban Courier', 1, '2026-10-31 01:59:08');

-- --------------------------------------------------------

--
-- Table structure for table `customer_addresses`
--

CREATE TABLE `customer_addresses` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `full_name` varchar(160) NOT NULL,
  `phone` varchar(50) NOT NULL,
  `street_address` text NOT NULL,
  `city_id` int(11) DEFAULT NULL,
  `is_default` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `hero_slides`
--

CREATE TABLE `hero_slides` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `title` varchar(180) NOT NULL,
  `subtitle` text DEFAULT NULL,
  `eyebrow` varchar(100) DEFAULT NULL,
  `button_text` varchar(80) DEFAULT 'Explore Collection',
  `button_url` varchar(255) DEFAULT 'index.php',
  `image_url` varchar(500) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `hero_slides`
--

INSERT INTO `hero_slides` (`id`, `title`, `subtitle`, `eyebrow`, `button_text`, `button_url`, `image_url`, `sort_order`, `active`, `created_at`) VALUES
(1, '', '', '', '', '', 'uploads/hero_1793393961_9f037c.png', 0, 1, '2026-10-30 20:59:21');

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `order_number` varchar(40) NOT NULL,
  `customer_name` varchar(160) NOT NULL,
  `customer_email` varchar(190) NOT NULL,
  `customer_phone` varchar(50) NOT NULL,
  `address` text NOT NULL,
  `shipping_address_id` bigint(20) UNSIGNED DEFAULT NULL,
  `city` varchar(120) NOT NULL,
  `city_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `payment_method` enum('COD') NOT NULL DEFAULT 'COD',
  `status` enum('pending','confirmed','processing','shipped','delivered','cancelled','returned') NOT NULL DEFAULT 'pending',
  `tracking_number` varchar(100) DEFAULT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `shipping_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total` decimal(10,2) NOT NULL,
  `coupon_code` varchar(60) DEFAULT NULL,
  `coupon_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `order_id` bigint(20) UNSIGNED NOT NULL,
  `product_id` bigint(20) UNSIGNED DEFAULT NULL,
  `size` varchar(32) DEFAULT NULL,
  `color` varchar(50) DEFAULT NULL,
  `product_name` varchar(180) NOT NULL,
  `sku` varchar(80) DEFAULT NULL,
  `quantity` int(10) UNSIGNED NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `cost_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `order_status_history`
--

CREATE TABLE `order_status_history` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `order_id` bigint(20) UNSIGNED NOT NULL,
  `status` varchar(30) NOT NULL,
  `note` varchar(500) DEFAULT NULL,
  `changed_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `category_id` bigint(20) UNSIGNED DEFAULT NULL,
  `name` varchar(180) NOT NULL,
  `slug` varchar(200) NOT NULL,
  `sku` varchar(80) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `cost_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `sizes` varchar(255) DEFAULT '',
  `colors` varchar(255) DEFAULT NULL,
  `compare_price` decimal(10,2) DEFAULT NULL,
  `image_url` varchar(500) DEFAULT NULL,
  `stock` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_images`
--

CREATE TABLE `product_images` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `product_id` bigint(20) UNSIGNED NOT NULL,
  `image_url` varchar(500) DEFAULT NULL,
  `is_primary` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_variants`
--

CREATE TABLE `product_variants` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `product_id` bigint(20) UNSIGNED NOT NULL,
  `sku` varchar(80) NOT NULL,
  `size` varchar(30) DEFAULT NULL,
  `color` varchar(50) DEFAULT NULL,
  `additional_price` decimal(10,2) DEFAULT 0.00,
  `stock` int(10) UNSIGNED DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `shipping_cities`
--

CREATE TABLE `shipping_cities` (
  `id` int(11) NOT NULL,
  `city_name` varchar(100) NOT NULL,
  `zone_code` enum('inside_dhaka','outside_dhaka') DEFAULT 'outside_dhaka',
  `shipping_cost` decimal(10,2) NOT NULL DEFAULT 120.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `shipping_cities`
--

INSERT INTO `shipping_cities` (`id`, `city_name`, `zone_code`, `shipping_cost`, `created_at`) VALUES
(1, 'Dhaka', 'inside_dhaka', 60.00, '2026-10-21 22:03:30'),
(2, 'Chittagong', 'outside_dhaka', 120.00, '2026-10-21 22:03:30'),
(3, 'Sylhet', 'outside_dhaka', 120.00, '2026-10-21 22:03:30'),
(11, 'Chattogram', 'outside_dhaka', 120.00, '2026-10-23 06:36:12'),
(12, 'Rajshahi', 'outside_dhaka', 120.00, '2026-10-23 06:36:12'),
(13, 'Khulna', 'outside_dhaka', 120.00, '2026-10-23 06:36:12'),
(14, 'Barishal', 'outside_dhaka', 120.00, '2026-10-23 06:36:12'),
(15, 'Rangpur', 'outside_dhaka', 120.00, '2026-10-23 06:36:12'),
(16, 'Mymensingh', 'outside_dhaka', 120.00, '2026-10-23 06:36:12');

-- --------------------------------------------------------

--
-- Table structure for table `site_settings`
--

CREATE TABLE `site_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `site_settings`
--

INSERT INTO `site_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES
('admin_email', 'hello.divers.almarez@gmail.com', '2026-10-20 17:53:38'),
('admin_notification_email', 'alam.office.my@gmail.com', '2026-10-25 19:27:15'),
('announcement', 'Test Header Announcement', '2026-10-19 19:36:37'),
('app_debug', 'true', '2026-10-23 07:04:25'),
('app_name', 'DiverseBD', '2026-10-23 07:04:43'),
('app_timezone', 'Asia/Dhaka', '2026-10-23 07:04:25'),
('app_url', 'http://localhost/v2ecom/', '2026-10-23 07:04:25'),
('currency', '৳', '2026-10-23 07:04:25'),
('currency_symbol', '৳', '2026-10-18 12:02:43'),
('db_host', 'localhost', '2026-10-23 07:04:25'),
('db_name', 'v2ecom', '2026-10-23 07:04:25'),
('db_pass', '', '2026-10-23 07:04:25'),
('db_port', '3306', '2026-10-23 07:04:25'),
('db_user', 'root', '2026-10-23 07:04:25'),
('email_invoice_subject', 'Official Tax Invoice - Order #{order_number}', '2026-10-20 08:34:08'),
('email_order_placed_intro', 'We have received your order #{order_number}. Our team is reviewing the details and will confirm your order shortly.', '2026-10-20 08:33:26'),
('email_order_placed_subject', 'Order Received - #{order_number}', '2026-10-20 08:33:26'),
('font_badge_family', 'Inter, sans-serif', '2026-10-20 19:07:12'),
('font_badge_size', '14', '2026-10-20 19:07:12'),
('font_catalog_family', 'Inter, sans-serif', '2026-10-20 19:07:12'),
('font_catalog_size', '15', '2026-10-20 19:07:12'),
('font_family', 'system-ui, -apple-system, sans-serif', '2026-10-20 18:54:34'),
('font_footer_family', 'Inter, sans-serif', '2026-10-20 19:07:12'),
('font_footer_size', '14', '2026-10-20 19:07:12'),
('font_hero_family', 'Playfair Display, serif', '2026-10-20 19:07:12'),
('font_hero_size', '32', '2026-10-20 19:07:12'),
('font_nav_family', 'Inter, sans-serif', '2026-10-20 19:07:12'),
('font_nav_size', '15', '2026-10-20 19:07:12'),
('font_size_base', '12', '2026-10-20 18:54:34'),
('font_social_family', 'Playfair Display, serif', '2026-10-20 19:07:12'),
('font_social_size', '28', '2026-10-20 19:07:12'),
('footer_about', 'Modern wardrobe staples, everyday fits, and premium quality crafted for style.dddddddeee', '2026-10-19 19:41:10'),
('footer_address', 'Dhaka, Bangladesh', '2026-10-18 17:41:41'),
('footer_copyright', '© 2026 Diverse. All rights reserved.', '2026-10-18 17:41:41'),
('footer_email', 'hello.divers.almarez@gmail.com', '2026-10-18 17:41:41'),
('footer_phone', '+880 1700-000000', '2026-10-18 17:41:41'),
('hero_description', 'Modern wardrobe staples. Easy fits, thoughtful details, everyday confidence.', '2026-10-18 10:22:06'),
('hero_eyebrow', 'Made for your everyday', '2026-10-18 10:22:06'),
('hero_title', 'Wear what feels like you.', '2026-10-18 10:22:06'),
('invoice_color', '#a94f28', '2026-10-20 14:11:51'),
('invoice_footer_note', 'Thank you for shopping with us', '2026-10-26 18:35:45'),
('logo_height', '150', '2026-10-25 18:45:24'),
('logo_url', 'uploads/logo-da26d865e89750d5d646c847.png', '2026-10-26 18:31:04'),
('mail_encryption', 'tls', '2026-10-18 17:14:14'),
('mail_from_address', 'alam.office.my@gmail.com', '2026-10-25 19:27:15'),
('mail_from_name', '\"DiverseBD Store\"', '2026-10-23 07:04:25'),
('mail_host', 'smtp.gmail.com', '2026-10-18 17:14:14'),
('mail_password', 'cbkk hghv ybom dooq', '2026-10-25 19:27:15'),
('mail_port', '587', '2026-10-18 17:14:14'),
('mail_username', 'alam.office.my@gmail.com', '2026-10-25 19:27:15'),
('maintenance_message', 'Our website is currently undergoing scheduled maintenance. We should be back online shortly. Thank you for your patience!', '2026-10-22 19:36:55'),
('maintenance_mode', '0', '2026-10-29 22:53:48'),
('maintenance_title', 'We’ll Be Right Back!', '2026-10-22 19:36:55'),
('max_admin_sessions', '1', '2026-10-20 17:49:54'),
('popup_button_text', 'Shop The Sale', '2026-10-21 20:46:01'),
('popup_button_url', 'index.php?category=new-arrivals#shop', '2026-10-21 20:46:01'),
('popup_enabled', '1', '2026-10-21 20:46:01'),
('popup_image_url', 'uploads/popup_1792616715.jpg', '2026-10-21 21:05:15'),
('popup_message', 'Get up to 30% OFF on all new traditional & casual arrivals. Limited time offer!', '2026-10-21 20:46:01'),
('popup_title', '🎉 Mega Season Sale!', '2026-10-21 20:46:01'),
('seo_meta_description', 'Discover modern clothing, premium wardrobe staples, and new season drops with nationwide Cash on Delivery in Bangladesh.', '2026-10-21 10:13:14'),
('seo_meta_keywords', 'clothing, fashion, online shop, bangladesh, modern fit, wardrobe', '2026-10-21 10:13:14'),
('seo_meta_title', 'Shop Modern Clothing & Wardrobe Essentials', '2026-10-21 10:13:14'),
('seo_og_image', '', '2026-10-21 10:13:14'),
('site_name', 'Diverse Clothings', '2026-10-19 19:37:28'),
('social_facebook', '', '2026-10-20 14:22:15'),
('social_instagram', '', '2026-10-19 19:39:42'),
('social_tiktok', '', '2026-10-19 19:39:42'),
('social_whatsapp', '', '2026-10-19 19:39:42'),
('social_youtube', '', '2026-10-19 19:39:42'),
('theme_announcement_text', '#ffffff', '2026-10-20 14:21:43'),
('theme_body_bg', '#f8f9fa', '2026-10-25 11:03:18'),
('theme_border_color', '#e0e0e0', '2026-10-25 11:03:18'),
('theme_card_bg', '#ffffff', '2026-10-25 11:03:18'),
('theme_footer_bg', '#111111', '2026-10-25 11:03:18'),
('theme_footer_text', '#ffffff', '2026-10-20 14:21:43'),
('theme_header_bg', '#1a252f', '2026-10-25 11:03:18'),
('theme_primary_color', '#2c3e50', '2026-10-25 11:03:18'),
('trust_badge_1_icon', '🚀', '2026-10-25 11:20:01'),
('trust_badge_1_sub', 'Fast delivery across Bangladesh', '2026-10-20 08:33:26'),
('trust_badge_1_title', 'Nationwide Shipping', '2026-10-20 08:33:26'),
('trust_badge_2_icon', '💵', '2026-10-25 11:20:01'),
('trust_badge_2_sub', 'Pay safely at your doorstep', '2026-10-20 08:33:26'),
('trust_badge_2_title', 'Cash on Delivery', '2026-10-20 08:33:26'),
('trust_badge_3_icon', '✨', '2026-10-25 11:20:01'),
('trust_badge_3_sub', 'Crafted for long-lasting comfort', '2026-10-20 17:53:24'),
('trust_badge_3_title', 'Premium Fabric', '2026-10-20 17:53:24'),
('trust_badge_4_icon', '🔄', '2026-10-25 11:20:01'),
('trust_badge_4_sub', 'Easy returns & order support', '2026-10-20 17:53:24'),
('trust_badge_4_title', 'Hassle-Free Exchange', '2026-10-20 17:53:24');

-- --------------------------------------------------------

--
-- Table structure for table `social_links`
--

CREATE TABLE `social_links` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `platform` varchar(50) NOT NULL,
  `title` varchar(100) DEFAULT NULL,
  `post_url` varchar(255) NOT NULL,
  `image_url` varchar(500) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stock_logs`
--

CREATE TABLE `stock_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `product_id` bigint(20) UNSIGNED NOT NULL,
  `admin_id` bigint(20) UNSIGNED DEFAULT NULL,
  `change_type` enum('add','subtract','set') NOT NULL,
  `quantity` int(11) NOT NULL,
  `old_stock` int(11) NOT NULL,
  `new_stock` int(11) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `testimonials`
--

CREATE TABLE `testimonials` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `customer_name` varchar(120) NOT NULL,
  `review_text` text NOT NULL,
  `image_url` varchar(500) DEFAULT NULL,
  `rating` tinyint(1) UNSIGNED DEFAULT 5,
  `sort_order` int(11) DEFAULT 0,
  `active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `status` enum('active','blocked') NOT NULL DEFAULT 'active',
  `reset_token` varchar(64) DEFAULT NULL,
  `reset_token_expires` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vendors`
--

CREATE TABLE `vendors` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `company_name` varchar(150) DEFAULT NULL,
  `phone` varchar(50) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `opening_balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vendor_invoices`
--

CREATE TABLE `vendor_invoices` (
  `id` int(10) UNSIGNED NOT NULL,
  `vendor_id` int(10) UNSIGNED NOT NULL,
  `invoice_number` varchar(50) NOT NULL,
  `issue_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('unpaid','partially_paid','paid','cancelled') NOT NULL DEFAULT 'unpaid',
  `notes` text DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vendor_payments`
--

CREATE TABLE `vendor_payments` (
  `id` int(10) UNSIGNED NOT NULL,
  `invoice_id` int(10) UNSIGNED NOT NULL,
  `vendor_id` int(10) UNSIGNED NOT NULL,
  `payment_date` date NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `payment_method` enum('bank','cash','other') NOT NULL DEFAULT 'bank',
  `reference_no` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `recorded_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `visitor_logs`
--

CREATE TABLE `visitor_logs` (
  `id` bigint(20) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `page_url` varchar(255) NOT NULL,
  `user_agent` text DEFAULT NULL,
  `referrer_url` varchar(500) DEFAULT NULL,
  `device_type` varchar(20) DEFAULT 'Desktop',
  `browser` varchar(50) DEFAULT NULL,
  `os` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `accounting_coa`
--
ALTER TABLE `accounting_coa`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `accounting_courier_settlements`
--
ALTER TABLE `accounting_courier_settlements`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `accounting_expenses`
--
ALTER TABLE `accounting_expenses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `account_code` (`account_code`);

--
-- Indexes for table `accounting_journals`
--
ALTER TABLE `accounting_journals`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `entry_number` (`entry_number`);

--
-- Indexes for table `accounting_journal_lines`
--
ALTER TABLE `accounting_journal_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `journal_id` (`journal_id`),
  ADD KEY `account_code` (`account_code`);

--
-- Indexes for table `accounting_stakeholders`
--
ALTER TABLE `accounting_stakeholders`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `admin_activity_logs`
--
ALTER TABLE `admin_activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_activity_admin` (`admin_id`),
  ADD KEY `idx_module` (`module`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `admin_login_logs`
--
ALTER TABLE `admin_login_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_login_logs_admin` (`admin_id`);

--
-- Indexes for table `admin_sessions`
--
ALTER TABLE `admin_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_admin_sessions_admin` (`admin_id`);

--
-- Indexes for table `api_keys`
--
ALTER TABLE `api_keys`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `api_key` (`api_key`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`);

--
-- Indexes for table `contacts`
--
ALTER TABLE `contacts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_contacts_status` (`status`);

--
-- Indexes for table `coupons`
--
ALTER TABLE `coupons`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `coupon_redemptions`
--
ALTER TABLE `coupon_redemptions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `coupon_id` (`coupon_id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `courier_services`
--
ALTER TABLE `courier_services`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `customer_addresses`
--
ALTER TABLE `customer_addresses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `city_id` (`city_id`);

--
-- Indexes for table `hero_slides`
--
ALTER TABLE `hero_slides`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_number` (`order_number`),
  ADD KEY `idx_orders_status` (`status`),
  ADD KEY `idx_orders_created` (`created_at`),
  ADD KEY `idx_orders_email` (`customer_email`),
  ADD KEY `fk_orders_city` (`city_id`),
  ADD KEY `fk_orders_address` (`shipping_address_id`),
  ADD KEY `fk_orders_coupon` (`coupon_id`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_order_item_order` (`order_id`),
  ADD KEY `fk_order_item_product` (`product_id`);

--
-- Indexes for table `order_status_history`
--
ALTER TABLE `order_status_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_status_order` (`order_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD UNIQUE KEY `sku` (`sku`),
  ADD KEY `fk_product_category` (`category_id`),
  ADD KEY `idx_products_active` (`active`),
  ADD KEY `idx_products_created` (`created_at`);

--
-- Indexes for table `product_images`
--
ALTER TABLE `product_images`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_product_images_product` (`product_id`);

--
-- Indexes for table `product_variants`
--
ALTER TABLE `product_variants`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `sku` (`sku`),
  ADD UNIQUE KEY `unique_product_color_size` (`product_id`,`color`,`size`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `shipping_cities`
--
ALTER TABLE `shipping_cities`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `city_name` (`city_name`);

--
-- Indexes for table `site_settings`
--
ALTER TABLE `site_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `social_links`
--
ALTER TABLE `social_links`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `stock_logs`
--
ALTER TABLE `stock_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_stock_logs_product` (`product_id`);

--
-- Indexes for table `testimonials`
--
ALTER TABLE `testimonials`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `vendors`
--
ALTER TABLE `vendors`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `vendor_invoices`
--
ALTER TABLE `vendor_invoices`
  ADD PRIMARY KEY (`id`),
  ADD KEY `vendor_id` (`vendor_id`);

--
-- Indexes for table `vendor_payments`
--
ALTER TABLE `vendor_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `invoice_id` (`invoice_id`),
  ADD KEY `vendor_id` (`vendor_id`);

--
-- Indexes for table `visitor_logs`
--
ALTER TABLE `visitor_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_ip_time` (`ip_address`,`created_at`),
  ADD KEY `idx_ip_created` (`ip_address`,`created_at`),
  ADD KEY `idx_device` (`device_type`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `accounting_coa`
--
ALTER TABLE `accounting_coa`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `accounting_courier_settlements`
--
ALTER TABLE `accounting_courier_settlements`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `accounting_expenses`
--
ALTER TABLE `accounting_expenses`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `accounting_journals`
--
ALTER TABLE `accounting_journals`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `accounting_journal_lines`
--
ALTER TABLE `accounting_journal_lines`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `accounting_stakeholders`
--
ALTER TABLE `accounting_stakeholders`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `admin_activity_logs`
--
ALTER TABLE `admin_activity_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `admin_login_logs`
--
ALTER TABLE `admin_login_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `api_keys`
--
ALTER TABLE `api_keys`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `contacts`
--
ALTER TABLE `contacts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `coupons`
--
ALTER TABLE `coupons`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `coupon_redemptions`
--
ALTER TABLE `coupon_redemptions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `courier_services`
--
ALTER TABLE `courier_services`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `customer_addresses`
--
ALTER TABLE `customer_addresses`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hero_slides`
--
ALTER TABLE `hero_slides`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `order_status_history`
--
ALTER TABLE `order_status_history`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `product_images`
--
ALTER TABLE `product_images`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `product_variants`
--
ALTER TABLE `product_variants`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `shipping_cities`
--
ALTER TABLE `shipping_cities`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `social_links`
--
ALTER TABLE `social_links`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stock_logs`
--
ALTER TABLE `stock_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `testimonials`
--
ALTER TABLE `testimonials`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vendors`
--
ALTER TABLE `vendors`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vendor_invoices`
--
ALTER TABLE `vendor_invoices`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vendor_payments`
--
ALTER TABLE `vendor_payments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `visitor_logs`
--
ALTER TABLE `visitor_logs`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `accounting_journal_lines`
--
ALTER TABLE `accounting_journal_lines`
  ADD CONSTRAINT `accounting_journal_lines_ibfk_1` FOREIGN KEY (`journal_id`) REFERENCES `accounting_journals` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `admin_activity_logs`
--
ALTER TABLE `admin_activity_logs`
  ADD CONSTRAINT `fk_activity_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `admin_login_logs`
--
ALTER TABLE `admin_login_logs`
  ADD CONSTRAINT `fk_login_logs_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `admin_sessions`
--
ALTER TABLE `admin_sessions`
  ADD CONSTRAINT `fk_admin_sessions_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `coupon_redemptions`
--
ALTER TABLE `coupon_redemptions`
  ADD CONSTRAINT `coupon_redemptions_ibfk_1` FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `coupon_redemptions_ibfk_2` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `coupon_redemptions_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `customer_addresses`
--
ALTER TABLE `customer_addresses`
  ADD CONSTRAINT `customer_addresses_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `customer_addresses_ibfk_2` FOREIGN KEY (`city_id`) REFERENCES `shipping_cities` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `fk_orders_address` FOREIGN KEY (`shipping_address_id`) REFERENCES `customer_addresses` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_orders_city` FOREIGN KEY (`city_id`) REFERENCES `shipping_cities` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_orders_coupon` FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `fk_order_item_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_order_item_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `order_status_history`
--
ALTER TABLE `order_status_history`
  ADD CONSTRAINT `fk_status_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `fk_product_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `product_images`
--
ALTER TABLE `product_images`
  ADD CONSTRAINT `fk_product_images_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `product_variants`
--
ALTER TABLE `product_variants`
  ADD CONSTRAINT `product_variants_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `stock_logs`
--
ALTER TABLE `stock_logs`
  ADD CONSTRAINT `fk_stock_logs_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `vendor_invoices`
--
ALTER TABLE `vendor_invoices`
  ADD CONSTRAINT `vendor_invoices_ibfk_1` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`);

--
-- Constraints for table `vendor_payments`
--
ALTER TABLE `vendor_payments`
  ADD CONSTRAINT `vendor_payments_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `vendor_invoices` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `vendor_payments_ibfk_2` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
