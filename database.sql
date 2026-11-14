-- ================================================================
-- VEHICLE RENTAL MANAGEMENT SYSTEM - DATABASE SCHEMA
-- Version 1.0
-- Date: 2025
-- ================================================================

CREATE DATABASE IF NOT EXISTS car_rental;
USE car_rental;

-- ================================================================
-- USERS TABLE - Stores all system users (owner, staff, customers)
-- ================================================================
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    phone VARCHAR(20),
    address TEXT,
    role ENUM('owner', 'staff', 'customer') DEFAULT 'customer' COMMENT 'User role: owner, staff, or customer',
    status ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (email),
    INDEX (role),
    INDEX (status)
) ENGINE=InnoDB;

-- ================================================================
-- AUTHENTICATION SECURITY - Email verification, login OTP, and reset codes
-- AuthSecurity also creates these tables at runtime for existing installs.
-- ================================================================
CREATE TABLE IF NOT EXISTS auth_security (
    user_id INT NOT NULL PRIMARY KEY,
    email_verified_at DATETIME DEFAULT NULL,
    challenge_type ENUM('email_verification', 'login_otp', 'password_reset') DEFAULT NULL,
    challenge_hash VARCHAR(255) DEFAULT NULL,
    challenge_expires_at DATETIME DEFAULT NULL,
    challenge_sent_at DATETIME DEFAULT NULL,
    challenge_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_auth_security_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS auth_rate_limits (
    bucket_key CHAR(64) NOT NULL PRIMARY KEY,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at DATETIME NOT NULL,
    blocked_until DATETIME DEFAULT NULL
) ENGINE=InnoDB;

-- ================================================================
-- CARS TABLE - Stores vehicle information
-- ================================================================
CREATE TABLE IF NOT EXISTS cars (
    plate_number VARCHAR(50) NOT NULL PRIMARY KEY COMMENT 'Vehicle plate number',
    brand VARCHAR(50) NOT NULL,
    model VARCHAR(100) NOT NULL,
    year INT NOT NULL,
    description TEXT,
    image TEXT,
    owner_id INT,
    price_per_day DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Daily rate',
    type VARCHAR(50) NOT NULL DEFAULT 'sedan' COMMENT 'Vehicle category',
    status ENUM('available', 'reserved', 'rented', 'maintenance') DEFAULT 'available' COMMENT 'Current vehicle status',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX (plate_number),
    INDEX (owner_id),
    INDEX (status),
    INDEX (brand, model),
    INDEX (price_per_day)
) ENGINE=InnoDB;

-- ================================================================
-- RENTALS TABLE - Stores rental transactions
-- ================================================================
CREATE TABLE IF NOT EXISTS rentals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL COMMENT 'Customer or owner making the rental',
    car_id VARCHAR(50) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    total_price DECIMAL(10, 2) NOT NULL COMMENT 'Total rental price',
    actual_return_date DATE,
    status ENUM('pending', 'approved', 'rejected', 'completed', 'cancelled') DEFAULT 'pending' COMMENT 'Rental transaction status',
    notes TEXT,
    driver_license_number VARCHAR(100) NOT NULL DEFAULT '',
    driver_license_image VARCHAR(255),
    customer_name VARCHAR(100) DEFAULT NULL,
    customer_phone VARCHAR(30) DEFAULT NULL,
    customer_address TEXT,
    customer_latitude DECIMAL(10,7) DEFAULT NULL,
    customer_longitude DECIMAL(10,7) DEFAULT NULL,
    tracking_enabled TINYINT(1) NOT NULL DEFAULT 0,
    tracking_latitude DECIMAL(10,7) DEFAULT NULL,
    tracking_longitude DECIMAL(10,7) DEFAULT NULL,
    tracking_updated_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (car_id) REFERENCES cars(plate_number) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX (user_id),
    INDEX (car_id),
    INDEX (status),
    INDEX (start_date),
    INDEX (end_date),
    INDEX (car_id, start_date, end_date)
) ENGINE=InnoDB;

-- ================================================================
-- RESERVATIONS TABLE - Stores vehicle reservations
-- ================================================================
CREATE TABLE IF NOT EXISTS reservations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL COMMENT 'Customer or owner making the reservation',
    car_id VARCHAR(50) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status ENUM('pending', 'confirmed', 'cancelled', 'completed') DEFAULT 'pending' COMMENT 'Reservation status',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (car_id) REFERENCES cars(plate_number) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX (user_id),
    INDEX (car_id),
    INDEX (status),
    INDEX (start_date),
    INDEX (end_date)
) ENGINE=InnoDB;

-- ================================================================
-- TRANSACTIONS TABLE - Stores payment transaction records for rentals
-- ================================================================
CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rental_id INT NOT NULL,
    user_id INT NOT NULL COMMENT 'Customer who made the payment',
    amount DECIMAL(10, 2) NOT NULL,
    payment_method ENUM('cash', 'gcash', 'bank_transfer', 'credit_card', 'debit_card', 'online') DEFAULT 'cash',
    payment_status ENUM('pending', 'completed', 'cancelled', 'refunded') DEFAULT 'pending' COMMENT 'Payment status',
    transaction_id VARCHAR(100) COMMENT 'External transaction ID',
    reference_number VARCHAR(100),
    receipt_image VARCHAR(255),
    payment_date DATETIME,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (rental_id) REFERENCES rentals(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX (rental_id),
    INDEX (user_id),
    INDEX (payment_status),
    INDEX (payment_date),
    UNIQUE KEY unique_rental_payment (rental_id)
) ENGINE=InnoDB;

-- ================================================================
-- PAYMONGO CHECKOUT DRAFTS - Temporary, non-admin booking data
-- No rental or payment record is created until PayMongo confirms payment.
-- ================================================================
CREATE TABLE IF NOT EXISTS paymongo_booking_drafts (
    reference_number VARCHAR(100) PRIMARY KEY,
    checkout_session_id VARCHAR(100) NOT NULL UNIQUE,
    user_id INT NOT NULL,
    car_id VARCHAR(50) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    total_price DECIMAL(10, 2) NOT NULL,
    driver_license_number VARCHAR(100) NOT NULL,
    driver_license_image VARCHAR(255),
    customer_name VARCHAR(100) NOT NULL,
    customer_phone VARCHAR(30) NOT NULL,
    customer_address TEXT NOT NULL,
    customer_latitude DECIMAL(10,7) DEFAULT NULL,
    customer_longitude DECIMAL(10,7) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (car_id) REFERENCES cars(plate_number) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX (user_id),
    INDEX (car_id, start_date, end_date)
) ENGINE=InnoDB;

-- ================================================================
-- AUDIT LOG TABLE - Records system activities for security
-- ================================================================
CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(100) NOT NULL COMMENT 'Action performed',
    entity_type VARCHAR(50) COMMENT 'Type of entity affected',
    entity_id INT,
    old_value TEXT COMMENT 'Previous value',
    new_value TEXT COMMENT 'New value',
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX (user_id),
    INDEX (entity_type),
    INDEX (action),
    INDEX (created_at)
) ENGINE=InnoDB;

-- ================================================================
-- Indexes for Better Performance
-- NOTE: Keep indexes inline with table definitions so rerunning this
-- script does not fail with duplicate index-name errors.
-- ================================================================

-- ================================================================
-- SEED DATA - Default Admin Account
-- ================================================================
-- Default Admin Account for System Access
-- Email: admin@carental.com
-- Password: admin123 (CHANGE THIS AFTER FIRST LOGIN!)
-- ================================================================
INSERT INTO users (name, email, password, phone, address, role, status, created_at, updated_at) VALUES (
    'System Administrator',
    'admin@carental.com',
    '$2y$10$pp2THZzlvSw7PMxIQcNBsO5Y/Pp35eMs/wZODB9GqOzmPf0S6hggS',
    '+1-800-ADMIN-00',
    '123 Admin Street, Admin City, AC 12345',
    'owner',
    'active',
    NOW(),
    NOW()
) ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    password = VALUES(password),
    phone = VALUES(phone),
    address = VALUES(address),
    role = VALUES(role),
    status = VALUES(status),
    updated_at = NOW();
