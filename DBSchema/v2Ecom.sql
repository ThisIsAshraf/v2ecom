-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 25, 2026 at 02:30 PM
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

--
-- Dumping data for table `admin_activity_logs`
--

INSERT INTO `admin_activity_logs` (`id`, `admin_id`, `admin_name`, `admin_email`, `module`, `action`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, 1, 'DiverseAdmin', 'alam.office.my@gmail.com', 'categories', 'auto_create', 'Auto-created category \'Men Casual\' during bulk CSV import', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 11:47:45'),
(2, 1, 'DiverseAdmin', 'alam.office.my@gmail.com', 'categories', 'auto_create', 'Auto-created category \'Women Ethnic\' during bulk CSV import', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 11:47:45'),
(3, 1, 'DiverseAdmin', 'alam.office.my@gmail.com', 'products', 'import_csv', 'Bulk CSV Import: 2 new products inserted, 0 updated, 0 skipped', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 11:47:45'),
(4, 1, 'DiverseAdmin', 'alam.office.my@gmail.com', 'settings', 'save_settings', 'Updated store branding (Store: \'Diverse Clothings\', Logo Height: 122px), trust badges, SEO, and mail settings', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 11:51:26'),
(5, 1, 'DiverseAdmin', 'alam.office.my@gmail.com', 'settings', 'update_maintenance_mode', 'State changed from DISABLED to ENABLED (Storefront Closed). Headline: \'We’ll Be Right Back!\', Notice: \'Our website is currently undergoing scheduled maintenance. We should be back online shortly. Thank you for your patience!\'', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 12:20:50'),
(6, 1, 'DiverseAdmin', 'alam.office.my@gmail.com', 'settings', 'update_maintenance_mode', 'State changed from ENABLED to DISABLED (Storefront Live). Headline: \'We’ll Be Right Back!\', Notice: \'Our website is currently undergoing scheduled maintenance. We should be back online shortly. Thank you for your patience!\'', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 12:21:08'),
(7, 1, 'DiverseAdmin', 'alam.office.my@gmail.com', 'settings', 'save_settings', 'Updated store branding, SEO, and SMTP credentials and synchronized with root .env file', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 12:31:36'),
(8, 1, 'DiverseAdmin', 'alam.office.my@gmail.com', 'settings', 'test_email', 'Sent test verification email to alamshihab526@gmail.com (Delivered successfully)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 12:32:36'),
(9, 1, 'DiverseAdmin', 'alam.office.my@gmail.com', 'categories', 'auto_create', 'Auto-created category \'Men Casual\' during bulk CSV import', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 13:07:29'),
(10, 1, 'DiverseAdmin', 'alam.office.my@gmail.com', 'categories', 'auto_create', 'Auto-created category \'Women Ethnic\' during bulk CSV import', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 13:07:29'),
(11, 1, 'DiverseAdmin', 'alam.office.my@gmail.com', 'products', 'import_csv', 'Bulk CSV Import: 2 new products inserted, 0 updated, 0 skipped', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 13:07:29'),
(12, 1, 'DiverseAdmin', 'alam.office.my@gmail.com', 'orders', 'update_status', 'Updated Order #DVBD261025C3169A from Pending to Confirmed (Tax invoice email sent to alamshihab526@gmail.com)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 13:12:58');

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

--
-- Dumping data for table `admin_login_logs`
--

INSERT INTO `admin_login_logs` (`id`, `admin_id`, `email_attempted`, `ip_address`, `user_agent`, `status`, `created_at`) VALUES
(1, 1, 'alam.office.my@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', 'success', '2026-10-25 13:06:52');

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

--
-- Dumping data for table `admin_sessions`
--

INSERT INTO `admin_sessions` (`id`, `admin_id`, `ip_address`, `user_agent`, `last_activity`) VALUES
('ogvfa4fb9tiu8h5smesuorcmd0', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 13:28:28');

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
(1, 'Men Casual', 'men-casual', '2026-10-25 13:07:29'),
(2, 'Women Ethnic', 'women-ethnic', '2026-10-25 13:07:29');

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

--
-- Dumping data for table `customer_addresses`
--

INSERT INTO `customer_addresses` (`id`, `user_id`, `full_name`, `phone`, `street_address`, `city_id`, `is_default`, `created_at`) VALUES
(1, NULL, 'Ashraful Alam Shihab', '01982401992', 'Test Address', 1, 1, '2026-10-25 13:12:23');

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

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`id`, `user_id`, `order_number`, `customer_name`, `customer_email`, `customer_phone`, `address`, `shipping_address_id`, `city`, `city_id`, `notes`, `payment_method`, `status`, `tracking_number`, `subtotal`, `discount_amount`, `shipping_amount`, `total`, `coupon_code`, `coupon_id`, `created_at`, `updated_at`) VALUES
(1, NULL, 'DVBD261025C3169A', 'Ashraful Alam Shihab', 'alamshihab526@gmail.com', '01982401992', 'Test Address', 1, 'Dhaka', 1, '122333', 'COD', 'confirmed', 'TRK-570F123F', 2200.00, 0.00, 60.00, 2260.00, NULL, NULL, '2026-10-25 13:12:23', '2026-10-25 13:12:58');

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `order_id` bigint(20) UNSIGNED NOT NULL,
  `product_id` bigint(20) UNSIGNED DEFAULT NULL,
  `size` varchar(32) DEFAULT NULL,
  `product_name` varchar(180) NOT NULL,
  `sku` varchar(80) DEFAULT NULL,
  `quantity` int(10) UNSIGNED NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `line_total` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `order_items`
--

INSERT INTO `order_items` (`id`, `order_id`, `product_id`, `size`, `product_name`, `sku`, `quantity`, `unit_price`, `line_total`) VALUES
(1, 1, 2, 'XL', 'Floral Printed Kurti', 'KURTI-FL-002', 1, 2200.00, 2200.00);

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

--
-- Dumping data for table `order_status_history`
--

INSERT INTO `order_status_history` (`id`, `order_id`, `status`, `note`, `changed_by`, `created_at`) VALUES
(1, 1, 'pending', 'Order placed by customer', NULL, '2026-10-25 13:12:23'),
(2, 1, 'confirmed', 'Status updated in admin panel to Confirmed (Official Invoice sent to customer)', 1, '2026-10-25 13:12:58');

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
  `sizes` varchar(255) DEFAULT '',
  `compare_price` decimal(10,2) DEFAULT NULL,
  `image_url` varchar(500) DEFAULT NULL,
  `stock` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `category_id`, `name`, `slug`, `sku`, `description`, `price`, `sizes`, `compare_price`, `image_url`, `stock`, `active`, `created_at`, `updated_at`) VALUES
(1, 1, 'Cotton Oxford Shirt', 'cotton-oxford-shirt', 'SHIRT-OX-001', 'Premium breathable cotton shirt.', 1450.00, 'S,M,L,XL', 1850.00, 'https://example.com/shirt.jpg', 25, 1, '2026-10-25 13:07:29', '2026-10-25 13:07:29'),
(2, 2, 'Floral Printed Kurti', 'floral-printed-kurti', 'KURTI-FL-002', 'Lightweight 3-piece set with dupatta.', 2200.00, 'M,L,XL', 2600.00, '', 14, 1, '2026-10-25 13:07:29', '2026-10-25 13:12:23');

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
('admin_notification_email', 'hello.divers.almarez@gmail.com', '2026-10-18 17:02:39'),
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
('invoice_footer_note', 'Thank you for shopping with us!555633', '2026-10-19 19:38:52'),
('logo_height', '122', '2026-10-25 11:51:26'),
('logo_url', 'uploads/logo-e18a928bbfd4e73752aabe95.png', '2026-10-25 11:00:51'),
('mail_encryption', 'tls', '2026-10-18 17:14:14'),
('mail_from_address', 'hello.divers.almarez@gmail.com', '2026-10-23 07:04:25'),
('mail_from_name', '\"DiverseBD Store\"', '2026-10-23 07:04:25'),
('mail_host', 'smtp.gmail.com', '2026-10-18 17:14:14'),
('mail_password', 'bmvz cjjy nrvc wlkq', '2026-10-25 12:31:36'),
('mail_port', '587', '2026-10-18 17:14:14'),
('mail_username', 'hello.divers.almarez@gmail.com', '2026-10-23 07:04:25'),
('maintenance_message', 'Our website is currently undergoing scheduled maintenance. We should be back online shortly. Thank you for your patience!', '2026-10-22 19:36:55'),
('maintenance_mode', '0', '2026-10-25 12:21:08'),
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
-- Table structure for table `visitor_logs`
--

CREATE TABLE `visitor_logs` (
  `id` bigint(20) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `page_url` varchar(255) NOT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `visitor_logs`
--

INSERT INTO `visitor_logs` (`id`, `ip_address`, `page_url`, `user_agent`, `created_at`) VALUES
(1, '::1', '/v2ecom/admin', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 13:06:52'),
(2, '::1', '/v2ecom/admin', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 13:06:52'),
(3, '::1', '/v2ecom/index.php', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 13:07:11'),
(4, '::1', '/v2ecom/index.php', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 13:07:11'),
(5, '::1', '/v2ecom/admin', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 13:07:14'),
(6, '::1', '/v2ecom/admin', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 13:07:14'),
(7, '::1', '/v2ecom/index.php', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 13:07:34'),
(8, '::1', '/v2ecom/index.php', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 13:07:34'),
(9, '::1', '/v2ecom/index.php', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 13:08:36'),
(10, '::1', '/v2ecom/index.php', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 13:08:36'),
(11, '::1', '/v2ecom/index.php', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 13:10:57'),
(12, '::1', '/v2ecom/index.php', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 13:10:57'),
(13, '::1', '/v2ecom/index.php', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 13:11:57'),
(14, '::1', '/v2ecom/index.php', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 13:11:57'),
(15, '::1', '/v2ecom/product.php?slug=floral-printed-kurti', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 13:12:01'),
(16, '::1', '/v2ecom/product.php?slug=floral-printed-kurti', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 13:12:01'),
(17, '::1', '/v2ecom/product.php?slug=floral-printed-kurti', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 13:12:06'),
(18, '::1', '/v2ecom/product.php?slug=floral-printed-kurti', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 13:12:06'),
(19, '::1', '/v2ecom/cart.php', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 13:12:06'),
(20, '::1', '/v2ecom/cart.php', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 13:12:06'),
(21, '::1', '/v2ecom/cart.php', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 13:12:13'),
(22, '::1', '/v2ecom/cart.php', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 13:12:13'),
(23, '::1', '/v2ecom/cart.php', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 13:12:13'),
(24, '::1', '/v2ecom/cart.php', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 13:12:13'),
(25, '::1', '/v2ecom/checkout.php', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 13:12:16'),
(26, '::1', '/v2ecom/checkout.php', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 13:12:16'),
(27, '::1', '/v2ecom/checkout.php', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 13:12:23'),
(28, '::1', '/v2ecom/checkout.php', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 13:12:23'),
(29, '::1', '/v2ecom/order-success.php', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 13:12:32'),
(30, '::1', '/v2ecom/order-success.php', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 13:12:32'),
(31, '::1', '/v2ecom/index.php', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 13:21:00'),
(32, '::1', '/v2ecom/index.php', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-25 13:21:00');

--
-- Indexes for dumped tables
--

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
-- Indexes for table `visitor_logs`
--
ALTER TABLE `visitor_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_ip_time` (`ip_address`,`created_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `admin_activity_logs`
--
ALTER TABLE `admin_activity_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `admin_login_logs`
--
ALTER TABLE `admin_login_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `api_keys`
--
ALTER TABLE `api_keys`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `contacts`
--
ALTER TABLE `contacts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `coupons`
--
ALTER TABLE `coupons`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `coupon_redemptions`
--
ALTER TABLE `coupon_redemptions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customer_addresses`
--
ALTER TABLE `customer_addresses`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `hero_slides`
--
ALTER TABLE `hero_slides`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `order_status_history`
--
ALTER TABLE `order_status_history`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

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
-- AUTO_INCREMENT for table `visitor_logs`
--
ALTER TABLE `visitor_logs`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- Constraints for dumped tables
--

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
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
