<?php
// Load local secrets without overriding values supplied by the server environment.
$localEnvFile = __DIR__ . '/.env';
if (is_file($localEnvFile)) {
    $localEnv = @parse_ini_file($localEnvFile, false, INI_SCANNER_RAW);
    if ($localEnv === false) {
        die('ERROR: Could not parse the local environment configuration.');
    }

    foreach ($localEnv as $name => $value) {
        if (!is_string($value) ||
            !preg_match('/^(DB|SMTP|BREVO|PAYMONGO)_[A-Z0-9_]+$/D', (string)$name)) {
            continue;
        }
        $existingValue = getenv($name);
        if ($existingValue === false || $existingValue === '') {
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
        }
    }
}

// Database Credentials
// Use 127.0.0.1 instead of localhost in local XAMPP/WAMP environments because localhost
// may resolve to ::1 and MySQL can be bound only to the IPv4 loopback interface.
define('DB_SERVER', getenv('DB_SERVER') ?: '127.0.0.1');
define('DB_PORT', (int) (getenv('DB_PORT') ?: 3306));
define('DB_USERNAME', getenv('DB_USERNAME') ?: 'root');
define('DB_PASSWORD', getenv('DB_PASSWORD') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'car_rental');

// Attempt to connect to MySQL server and ensure the app database exists.
$mysqli = @new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, '', DB_PORT);

// Check connection
if ($mysqli->connect_error) {
    die(
        "ERROR: Could not connect to MySQL server at " . DB_SERVER . ":" . DB_PORT . ". " .
        "Start MySQL in XAMPP/WAMP or update the DB_SERVER/DB_PORT values in config.php. " .
        "Original error: " . $mysqli->connect_error
    );
}
// Create the application database if it does not already exist.
$createDbQuery = "CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
if (!$mysqli->query($createDbQuery)) {
    die("ERROR: Could not create database " . DB_NAME . ". " . $mysqli->error);
}

if (!$mysqli->select_db(DB_NAME)) {
    die("ERROR: Could not select database " . DB_NAME . ". " . $mysqli->error);
}

// Ensure the required application schema exists.
try {
    $mysqli->query("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(100) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        phone VARCHAR(20),
        address TEXT,
        role ENUM('owner', 'staff', 'customer') DEFAULT 'customer',
        status ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX (email),
        INDEX (role),
        INDEX (status)
    ) ENGINE=InnoDB");

    $mysqli->query("CREATE TABLE IF NOT EXISTS cars (
        plate_number VARCHAR(50) NOT NULL PRIMARY KEY,
        brand VARCHAR(50) NOT NULL,
        model VARCHAR(100) NOT NULL,
        year INT NOT NULL,
        description TEXT,
        image TEXT,
        owner_id INT,
        price_per_day DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        type VARCHAR(50) NOT NULL DEFAULT 'sedan',
        status ENUM('available', 'reserved', 'rented', 'maintenance') DEFAULT 'available',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL,
        INDEX (plate_number),
        INDEX (owner_id),
        INDEX (status),
        INDEX (brand, model),
        INDEX (price_per_day)
    ) ENGINE=InnoDB");

    $carColumns = $mysqli->query("SHOW COLUMNS FROM cars");
    if (!$carColumns) {
        die("ERROR: Could not inspect car schema. " . $mysqli->error);
    }
    $carColumnNames = [];
    while ($column = $carColumns->fetch_assoc()) {
        $carColumnNames[] = $column['Field'];
    }
    $oldCarKey = in_array('car_key', $carColumnNames, true)
        ? 'car_key'
        : (in_array('id', $carColumnNames, true) ? 'id' : null);
    if ($oldCarKey !== null) {
        $foreignKeys = $mysqli->query(
            "SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.COLUMN_NAME, r.DELETE_RULE, r.UPDATE_RULE
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
              AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
              AND r.TABLE_NAME = k.TABLE_NAME
             WHERE k.CONSTRAINT_SCHEMA = '" . $mysqli->real_escape_string(DB_NAME) . "'
               AND k.REFERENCED_TABLE_NAME = 'cars'
               AND k.REFERENCED_COLUMN_NAME = '" . $mysqli->real_escape_string($oldCarKey) . "'"
        );
        if (!$foreignKeys) {
            die("ERROR: Could not inspect vehicle relationships. " . $mysqli->error);
        }

        $carForeignKeys = [];
        while ($foreignKey = $foreignKeys->fetch_assoc()) {
            $carForeignKeys[] = $foreignKey;
        }
        foreach ($carForeignKeys as $foreignKey) {
            $tableName = str_replace('`', '``', $foreignKey['TABLE_NAME']);
            $constraintName = str_replace('`', '``', $foreignKey['CONSTRAINT_NAME']);
            if (!$mysqli->query("ALTER TABLE `$tableName` DROP FOREIGN KEY `$constraintName`")) {
                die("ERROR: Could not prepare vehicle relationship migration. " . $mysqli->error);
            }
        }

        foreach ($carForeignKeys as $foreignKey) {
            $tableName = str_replace('`', '``', $foreignKey['TABLE_NAME']);
            $columnName = str_replace('`', '``', $foreignKey['COLUMN_NAME']);
            if (!$mysqli->query("ALTER TABLE `$tableName` MODIFY COLUMN `$columnName` VARCHAR(50) NOT NULL")) {
                die("ERROR: Could not convert vehicle relationship values. " . $mysqli->error);
            }
            $sql = "UPDATE `$tableName` child JOIN cars vehicle ON child.`$columnName` = vehicle.`$oldCarKey` SET child.`$columnName` = vehicle.plate_number";
            if (!$mysqli->query($sql)) {
                die("ERROR: Could not migrate vehicle relationships to plate numbers. " . $mysqli->error);
            }
        }

        if (!$mysqli->query("ALTER TABLE cars DROP PRIMARY KEY, DROP COLUMN `$oldCarKey`, ADD PRIMARY KEY (plate_number)")) {
            die("ERROR: Could not make plate number the vehicle primary key. " . $mysqli->error);
        }

        foreach ($carForeignKeys as $foreignKey) {
            $tableName = str_replace('`', '``', $foreignKey['TABLE_NAME']);
            $constraintName = str_replace('`', '``', $foreignKey['CONSTRAINT_NAME']);
            $columnName = str_replace('`', '``', $foreignKey['COLUMN_NAME']);
            $deleteRule = in_array($foreignKey['DELETE_RULE'], ['CASCADE', 'SET NULL', 'SET DEFAULT', 'RESTRICT', 'NO ACTION'], true)
                ? $foreignKey['DELETE_RULE']
                : 'NO ACTION';
            $updateRule = in_array($foreignKey['UPDATE_RULE'], ['CASCADE', 'SET NULL', 'SET DEFAULT', 'RESTRICT', 'NO ACTION'], true)
                ? $foreignKey['UPDATE_RULE']
                : 'NO ACTION';
            $sql = "ALTER TABLE `$tableName` ADD CONSTRAINT `$constraintName` FOREIGN KEY (`$columnName`) REFERENCES cars(plate_number) ON DELETE $deleteRule ON UPDATE CASCADE";
            if (!$mysqli->query($sql)) {
                die("ERROR: Could not restore vehicle relationships after migration. " . $mysqli->error);
            }
        }
    }

    $mysqli->query("CREATE TABLE IF NOT EXISTS rentals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        car_id VARCHAR(50) NOT NULL,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        total_price DECIMAL(10, 2) NOT NULL,
        actual_return_date DATE,
        status ENUM('pending', 'approved', 'rejected', 'completed', 'cancelled') DEFAULT 'pending',
        notes TEXT,
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
    ) ENGINE=InnoDB");

    $mysqli->query("CREATE TABLE IF NOT EXISTS reservations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        car_id VARCHAR(50) NOT NULL,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        status ENUM('pending', 'confirmed', 'cancelled', 'completed') DEFAULT 'pending',
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
    ) ENGINE=InnoDB");

    $paymentTables = $mysqli->query(
        "SELECT TABLE_NAME
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME IN ('payments', 'transactions')"
    );
    if (!$paymentTables) {
        die("ERROR: Could not inspect payment transaction tables. " . $mysqli->error);
    }

    $hasPaymentsTable = false;
    $hasTransactionsTable = false;
    while ($table = $paymentTables->fetch_assoc()) {
        if ($table['TABLE_NAME'] === 'payments') {
            $hasPaymentsTable = true;
        } elseif ($table['TABLE_NAME'] === 'transactions') {
            $hasTransactionsTable = true;
        }
    }
    if ($hasPaymentsTable && $hasTransactionsTable) {
        $paymentRowCount = $mysqli->query("SELECT COUNT(*) AS row_count FROM payments");
        $transactionRowCount = $mysqli->query("SELECT COUNT(*) AS row_count FROM transactions");
        if (!$paymentRowCount || !$transactionRowCount) {
            die("ERROR: Could not compare payments and transactions tables. " . $mysqli->error);
        }

        $paymentCount = (int)$paymentRowCount->fetch_assoc()['row_count'];
        $transactionCount = (int)$transactionRowCount->fetch_assoc()['row_count'];
        if ($paymentCount > 0 && $transactionCount > 0) {
            die("ERROR: Both payments and transactions tables contain data. Merge them before continuing.");
        }

        if ($transactionCount > 0) {
            if (!$mysqli->query("DROP TABLE payments")) {
                die("ERROR: Could not remove the empty legacy payments table. " . $mysqli->error);
            }
            $hasPaymentsTable = false;
        } else {
            if (!$mysqli->query("DROP TABLE transactions")) {
                die("ERROR: Could not replace the empty transactions table. " . $mysqli->error);
            }
            $hasTransactionsTable = false;
        }
    }
    if ($hasPaymentsTable && !$mysqli->query("RENAME TABLE payments TO transactions")) {
        die("ERROR: Could not rename payments table to transactions. " . $mysqli->error);
    }

    $mysqli->query("CREATE TABLE IF NOT EXISTS transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rental_id INT NOT NULL,
        user_id INT NOT NULL,
        amount DECIMAL(10, 2) NOT NULL,
        payment_method ENUM('cash', 'gcash', 'bank_transfer', 'credit_card', 'debit_card', 'online') DEFAULT 'cash',
        payment_status ENUM('pending', 'completed', 'cancelled', 'refunded') DEFAULT 'pending',
        transaction_id VARCHAR(100),
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
    ) ENGINE=InnoDB");

    if (!$mysqli->query("CREATE TABLE IF NOT EXISTS paymongo_booking_drafts (
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
    ) ENGINE=InnoDB")) {
        die("ERROR: Could not initialize PayMongo checkout drafts. " . $mysqli->error);
    }

    $mysqli->query("CREATE TABLE IF NOT EXISTS audit_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT,
        action VARCHAR(100) NOT NULL,
        entity_type VARCHAR(50),
        entity_id INT,
        old_value TEXT,
        new_value TEXT,
        ip_address VARCHAR(45),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
        INDEX (user_id),
        INDEX (entity_type),
        INDEX (action),
        INDEX (created_at)
    ) ENGINE=InnoDB");

    $colsRes = $mysqli->query("SHOW COLUMNS FROM cars");
    if ($colsRes) {
        $existingCols = [];
        while ($colRow = $colsRes->fetch_assoc()) {
            $existingCols[] = $colRow['Field'];
        }

        if (!in_array('price_per_day', $existingCols)) {
            $mysqli->query("ALTER TABLE cars ADD COLUMN price_per_day DECIMAL(10,2) NOT NULL DEFAULT 0.00");
        }
        if (!in_array('type', $existingCols)) {
            $mysqli->query("ALTER TABLE cars ADD COLUMN type VARCHAR(50) NOT NULL DEFAULT 'sedan'");
        }
        $mysqli->query("ALTER TABLE cars MODIFY COLUMN image TEXT");
    }
} catch (\Throwable $e) {
    die("ERROR: Could not initialize or migrate the application schema. " . $e->getMessage());
}

// Migrate existing installations away from the obsolete fleet quantity field.
$carColumns = $mysqli->query("SHOW COLUMNS FROM cars");
if (!$carColumns) {
    die("ERROR: Could not inspect car schema. " . $mysqli->error);
}
$hasQuantityColumn = false;
while ($column = $carColumns->fetch_assoc()) {
    if ($column['Field'] === 'quantity') {
        $hasQuantityColumn = true;
        break;
    }
}
if ($hasQuantityColumn && !$mysqli->query("ALTER TABLE cars DROP COLUMN quantity")) {
    die("ERROR: Could not remove obsolete car quantity field. " . $mysqli->error);
}

// Keep upgrades for existing local installations aligned with database.sql.
$rentalColumns = $mysqli->query("SHOW COLUMNS FROM rentals");
if (!$rentalColumns) {
    die("ERROR: Could not inspect rental schema. " . $mysqli->error);
}
$existingRentalColumns = [];
while ($column = $rentalColumns->fetch_assoc()) {
    $existingRentalColumns[] = $column['Field'];
}
if (!in_array('driver_license_number', $existingRentalColumns, true) &&
    !$mysqli->query("ALTER TABLE rentals ADD COLUMN driver_license_number VARCHAR(100) NOT NULL DEFAULT ''")) {
    die("ERROR: Could not add driver's license details to rentals. " . $mysqli->error);
}
if (!in_array('driver_license_image', $existingRentalColumns, true) &&
    !$mysqli->query("ALTER TABLE rentals ADD COLUMN driver_license_image VARCHAR(255) DEFAULT NULL")) {
    die("ERROR: Could not add driver's license image to rentals. " . $mysqli->error);
}
if (!in_array('customer_name', $existingRentalColumns, true) &&
    !$mysqli->query("ALTER TABLE rentals ADD COLUMN customer_name VARCHAR(100) DEFAULT NULL")) {
    die("ERROR: Could not add rental customer names. " . $mysqli->error);
}
if (!in_array('customer_phone', $existingRentalColumns, true) &&
    !$mysqli->query("ALTER TABLE rentals ADD COLUMN customer_phone VARCHAR(30) DEFAULT NULL")) {
    die("ERROR: Could not add rental customer phone numbers. " . $mysqli->error);
}
if (!in_array('customer_address', $existingRentalColumns, true) &&
    !$mysqli->query("ALTER TABLE rentals ADD COLUMN customer_address TEXT")) {
    die("ERROR: Could not add rental customer addresses. " . $mysqli->error);
}
foreach ([
    'customer_latitude' => 'DECIMAL(10,7) DEFAULT NULL',
    'customer_longitude' => 'DECIMAL(10,7) DEFAULT NULL',
    'tracking_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
    'tracking_latitude' => 'DECIMAL(10,7) DEFAULT NULL',
    'tracking_longitude' => 'DECIMAL(10,7) DEFAULT NULL',
    'tracking_updated_at' => 'DATETIME DEFAULT NULL'
] as $columnName => $columnDefinition) {
    if (!in_array($columnName, $existingRentalColumns, true) &&
        !$mysqli->query("ALTER TABLE rentals ADD COLUMN `$columnName` $columnDefinition")) {
        die("ERROR: Could not add GPS tracking data to rentals. " . $mysqli->error);
    }
}

$rentalIndexes = $mysqli->query("SHOW INDEX FROM rentals");
if (!$rentalIndexes) {
    die("ERROR: Could not inspect rental indexes. " . $mysqli->error);
}
$hasUniqueRentalPeriod = false;
while ($index = $rentalIndexes->fetch_assoc()) {
    if ($index['Key_name'] === 'unique_rental_period') {
        $hasUniqueRentalPeriod = true;
        break;
    }
}
if ($hasUniqueRentalPeriod && !$mysqli->query("ALTER TABLE rentals DROP INDEX unique_rental_period")) {
    die("ERROR: Could not remove the obsolete rental period constraint. " . $mysqli->error);
}

$paymentColumns = $mysqli->query("SHOW COLUMNS FROM transactions");
if (!$paymentColumns) {
    die("ERROR: Could not inspect payment schema. " . $mysqli->error);
}
$existingPaymentColumns = [];
$paymentMethodType = '';
while ($column = $paymentColumns->fetch_assoc()) {
    $existingPaymentColumns[] = $column['Field'];
    if ($column['Field'] === 'payment_method') {
        $paymentMethodType = $column['Type'];
    }
}
if (!in_array('receipt_image', $existingPaymentColumns, true) &&
    !$mysqli->query("ALTER TABLE transactions ADD COLUMN receipt_image VARCHAR(255) DEFAULT NULL")) {
    die("ERROR: Could not add payment receipt storage. " . $mysqli->error);
}
if (strpos($paymentMethodType, 'gcash') === false &&
    !$mysqli->query("ALTER TABLE transactions MODIFY COLUMN payment_method ENUM('cash', 'gcash', 'bank_transfer', 'credit_card', 'debit_card', 'online') DEFAULT 'cash'")) {
    die("ERROR: Could not enable GCash payments. " . $mysqli->error);
}

// SMTP Configuration
define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.gmail.com');
define('SMTP_PORT', (int)(getenv('SMTP_PORT') ?: 587));
define('SMTP_USERNAME', getenv('SMTP_USERNAME') ?: '');
define('SMTP_PASSWORD', getenv('SMTP_PASSWORD') ?: '');
define('SMTP_ENCRYPTION', strtolower(getenv('SMTP_ENCRYPTION') ?: 'tls'));
define('SMTP_FROM_EMAIL', getenv('SMTP_FROM_EMAIL') ?: SMTP_USERNAME);
define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'Vehicle Rental Management System');
define('PAYMONGO_PUBLIC_KEY', getenv('PAYMONGO_PUBLIC_KEY') ?: '');
define('PAYMONGO_SECRET_KEY', getenv('PAYMONGO_SECRET_KEY') ?: '');
define('PAYMONGO_WEBHOOK_SECRET', getenv('PAYMONGO_WEBHOOK_SECRET') ?: '');

// Global Constants
define('BASE_URL', 'http://localhost/Car_Rental/');

// User Roles
define('ROLE_OWNER', 'owner');
define('ROLE_STAFF', 'staff');
define('ROLE_CUSTOMER', 'customer');

// User Status
define('STATUS_ACTIVE', 'active');
define('STATUS_INACTIVE', 'inactive');
define('STATUS_SUSPENDED', 'suspended');

// Vehicle Status
define('VEHICLE_STATUS_AVAILABLE', 'available');
define('VEHICLE_STATUS_RESERVED', 'reserved');
define('VEHICLE_STATUS_RENTED', 'rented');
define('VEHICLE_STATUS_MAINTENANCE', 'maintenance');

// Rental Status
define('RENTAL_STATUS_PENDING', 'pending');
define('RENTAL_STATUS_APPROVED', 'approved');
define('RENTAL_STATUS_REJECTED', 'rejected');
define('RENTAL_STATUS_COMPLETED', 'completed');
define('RENTAL_STATUS_CANCELLED', 'cancelled');

// Payment Status
define('PAYMENT_STATUS_PENDING', 'pending');
define('PAYMENT_STATUS_COMPLETED', 'completed');
define('PAYMENT_STATUS_CANCELLED', 'cancelled');
define('PAYMENT_STATUS_REFUNDED', 'refunded');

// Payment Methods
define('PAYMENT_METHOD_CASH', 'cash');
define('PAYMENT_METHOD_GCASH', 'gcash');
define('PAYMENT_METHOD_CREDIT_CARD', 'credit_card');
define('PAYMENT_METHOD_DEBIT_CARD', 'debit_card');
define('PAYMENT_METHOD_BANK_TRANSFER', 'bank_transfer');
define('PAYMENT_METHOD_ONLINE', 'online');

// Reservation Status
define('RESERVATION_STATUS_PENDING', 'pending');
define('RESERVATION_STATUS_CONFIRMED', 'confirmed');
define('RESERVATION_STATUS_CANCELLED', 'cancelled');
define('RESERVATION_STATUS_COMPLETED', 'completed');

// Set Timezone to Philippines
date_default_timezone_set('Asia/Manila');

// Start Session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Error Reporting (disable in production)
// error_reporting(E_ALL);
// ini_set('display_errors', 1);

// Autoload MVC classes (Models & Controllers)
spl_autoload_register(function ($class) {
    $classPath = str_replace('\\', '/', $class);
    // Remove leading App/ if used
    if (strpos($classPath, 'App/') === 0) {
        $classPath = substr($classPath, 4);
    }
    $file = __DIR__ . '/app/' . $classPath . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Helper function to parse multiple car images
if (!function_exists('getCarImagesList')) {
    function getCarImagesList($imgString) {
        if (empty($imgString)) return [];
        $decoded = json_decode($imgString, true);
        if (is_array($decoded)) return array_values(array_filter($decoded));
        return array_values(array_filter(array_map('trim', explode(',', $imgString))));
    }
}
?>
