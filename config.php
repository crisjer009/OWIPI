<?php
// Config settings for OWI PHYSICAL INVENTORY system (MySQL version)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('CONFIG_FILE', __DIR__ . '/db_config.json');

// Default database configuration
function getDefaultConfig() {
    return [
        'server' => 'localhost',
        'port' => '3306',
        'database' => 'owi_physical_inventory', // Main master database
        'username' => 'root',
        'password' => '',
        'print_margin_top' => 0,
        'print_margin_left' => 10,
    ];
}

// Load database configuration
function loadConfig() {
    if (file_exists(CONFIG_FILE)) {
        $json = file_get_contents(CONFIG_FILE);
        $data = json_decode($json, true);
        if (is_array($data)) {
            return array_merge(getDefaultConfig(), $data);
        }
    }
    return getDefaultConfig();
}

// Save database configuration
function saveConfig($config) {
    $data = array_merge(getDefaultConfig(), $config);
    if (file_exists(CONFIG_FILE) && !is_writable(CONFIG_FILE)) {
        @chmod(CONFIG_FILE, 0666);
    }
    $result = @file_put_contents(CONFIG_FILE, json_encode($data, JSON_PRETTY_PRINT));
    return $result !== false;
}

// Detect server local IP address
function getServerLocalIPs() {
    $ips = [];
    $defaultIp = gethostbyname(gethostname());

    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        @exec('ipconfig', $output);
        if (is_array($output)) {
            $currentAdapter = '';
            $tempIps = [];
            foreach ($output as $line) {
                $trimmed = trim($line);
                if (empty($trimmed)) continue;

                // Check for adapter header line, e.g., "Ethernet adapter Ethernet:" or "Wireless LAN adapter Wi-Fi:"
                if (preg_match('/^[^\s].*?:$/', $line)) {
                    $currentAdapter = rtrim($line, ':');
                    continue;
                }

                if (preg_match('/IPv4 Address(\. )*: ([\d\.]+)/', $line, $match)) {
                    $ip = trim($match[2]);
                    if (preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2\d|3[01])\.)/', $ip)) {
                        $lowerAdapter = strtolower($currentAdapter);
                        if (
                            strpos($lowerAdapter, 'virtual') !== false ||
                            strpos($lowerAdapter, 'vbox') !== false ||
                            strpos($lowerAdapter, 'virtualbox') !== false ||
                            strpos($lowerAdapter, 'vmware') !== false ||
                            strpos($lowerAdapter, 'wsl') !== false ||
                            strpos($lowerAdapter, 'default switch') !== false ||
                            strpos($lowerAdapter, 'host-only') !== false ||
                            strpos($lowerAdapter, 'tailscale') !== false
                        ) {
                            continue;
                        }
                        $tempIps[$currentAdapter] = [
                            'ip' => $ip,
                            'adapter' => $currentAdapter,
                            'has_gateway' => false
                        ];
                    }
                }

                if (preg_match('/Default Gateway(\. )*: ([\d\.]+)/', $line, $match)) {
                    $gateway = trim($match[2]);
                    if (!empty($gateway) && $gateway !== '0.0.0.0' && isset($tempIps[$currentAdapter])) {
                        $tempIps[$currentAdapter]['has_gateway'] = true;
                    }
                }
            }
            $ips = array_values($tempIps);
        }
    }

    if (empty($ips)) {
        if (isset($_SERVER['SERVER_ADDR']) && $_SERVER['SERVER_ADDR'] !== '127.0.0.1' && $_SERVER['SERVER_ADDR'] !== '::1') {
            $ips[] = ['ip' => $_SERVER['SERVER_ADDR'], 'adapter' => 'Server Address', 'has_gateway' => true];
        } else {
            $ips[] = ['ip' => $defaultIp, 'adapter' => 'Hostname Address', 'has_gateway' => false];
        }
    }

    return $ips;
}

function getServerLocalIP() {
    $ips = getServerLocalIPs();
    if (empty($ips)) {
        return gethostbyname(gethostname());
    }

    // Sort network adapters:
    // Priority:
    // 1. Has gateway + Wi-Fi/Wireless
    // 2. Has gateway + Ethernet
    // 3. Has gateway + Other
    // 4. No gateway + Wi-Fi/Wireless
    // 5. No gateway + Ethernet
    // 6. No gateway + Other
    usort($ips, function($a, $b) {
        $aScore = 0;
        $bScore = 0;

        if ($a['has_gateway']) $aScore += 10;
        if ($b['has_gateway']) $bScore += 10;

        $aName = strtolower($a['adapter']);
        $bName = strtolower($b['adapter']);

        $aIsWifi = (strpos($aName, 'wireless') !== false || strpos($aName, 'wi-fi') !== false || strpos($aName, 'wlan') !== false);
        $bIsWifi = (strpos($bName, 'wireless') !== false || strpos($bName, 'wi-fi') !== false || strpos($bName, 'wlan') !== false);

        $aIsEthernet = (strpos($aName, 'ethernet') !== false || strpos($aName, 'local area') !== false);
        $bIsEthernet = (strpos($bName, 'ethernet') !== false || strpos($bName, 'local area') !== false);

        if ($aIsWifi) $aScore += 5;
        elseif ($aIsEthernet) $aScore += 3;

        if ($bIsWifi) $bScore += 5;
        elseif ($bIsEthernet) $bScore += 3;

        return $bScore - $aScore;
    });

    return $ips[0]['ip'];
}

// Authentication Check Helpers
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'system_admin';
}

function hasActiveStore() {
    return isset($_SESSION['store_code']);
}

function checkAuth($requireAdmin = false) {
    if (!isLoggedIn()) {
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized. Please log in.']);
            exit;
        }
        header('Location: login.php');
        exit;
    }
    if ($requireAdmin && !isAdmin()) {
        header('Location: scan.php');
        exit;
    }
}

// Database Connection Wrapper for MySQL
class OWI_DB {
    private $config;
    private $pdo = null;
    private static $isInitializing = false;
    
    public function __construct() {
        $this->config = loadConfig();
    }
    
    // Check if PDO MySQL driver is loaded
    public static function isDriverLoaded() {
        return extension_loaded('pdo_mysql');
    }
    
    // Get driver diagnostics details
    public static function getDiagnostics() {
        return [
            'php_version' => PHP_VERSION,
            'os' => PHP_OS,
            'is_x64' => (PHP_INT_SIZE === 8),
            'pdo_mysql_loaded' => extension_loaded('pdo_mysql'),
            'mysqli_loaded' => extension_loaded('mysqli'),
        ];
    }
    
    // Connect to MySQL
    public function connect($selectDatabase = true) {
        $server = $this->config['server'];
        $port = !empty($this->config['port']) ? $this->config['port'] : '3306';
        $db = $this->config['database']; // Connect to main database owi_physical_inventory
        $username = $this->config['username'];
        $password = $this->config['password'];
        
        $this->pdo = null;
        
        try {
            if ($selectDatabase) {
                $dsn = "mysql:host=$server;port=$port;dbname=$db;charset=utf8mb4";
            } else {
                $dsn = "mysql:host=$server;port=$port;charset=utf8mb4";
            }
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
            ];
            
            $this->pdo = new PDO($dsn, $username, $password, $options);
            
            // Self-healing check: if database was selected, verify if 'stores' table exists and has all columns
            if ($selectDatabase && !self::$isInitializing) {
                static $dbChecked = false;
                if (!$dbChecked) {
                    $dbChecked = true;
                    self::$isInitializing = true;
                    try {
                        $stmt = $this->pdo->query("SHOW TABLES LIKE 'stores'");
                        if ($stmt->rowCount() == 0) {
                            // Table doesn't exist, trigger database structure initialization
                            $this->initializeDatabase();
                        } else {
                            // Column self-healing check: check if 'closed' column exists in 'stores' table
                            $colCheck = $this->pdo->query("SHOW COLUMNS FROM stores LIKE 'closed'");
                            if ($colCheck->rowCount() == 0) {
                                $this->pdo->exec("ALTER TABLE stores ADD COLUMN closed TINYINT(1) NOT NULL DEFAULT 0 AFTER synced");
                            }
                        }
                    } catch (PDOException $tblEx) {
                        $this->initializeDatabase();
                    } finally {
                        self::$isInitializing = false;
                    }
                }
            }
            
            return true;
        } catch (PDOException $e) {
            // Self-healing: If database doesn't exist, auto-create & initialize it
            if ($selectDatabase && !self::$isInitializing) {
                self::$isInitializing = true;
                try {
                    $this->initializeDatabase();
                    self::$isInitializing = false;
                    return true;
                } catch (Exception $initEx) {
                    self::$isInitializing = false;
                    // Fall back to original error
                }
            }
            throw new Exception("MySQL Connection Failed: " . $e->getMessage());
        }
    }
    
    // Execute a query
    public function execute($sql, $params = []) {
        if (!$this->pdo) {
            $this->connect();
        }
        
        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            throw new Exception("Query Execution Failed: " . $e->getMessage());
        }
    }
    
    // Fetch all rows for a query
    public function query($sql, $params = []) {
        if (!$this->pdo) {
            $this->connect();
        }
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            throw new Exception("Query Failed: " . $e->getMessage());
        }
    }
    
    // Initialize Master database and Master tables (users, stores)
    public function initializeDatabase() {
        $this->connect(false); // Connect without database selected first
        
        $dbName = $this->config['database'];
        $safeDbName = preg_replace('/[^a-zA-Z0-9_]/', '', $dbName);
        
        // Create master database if not exists (might throw permission error on cloud hosts if pre-provisioned)
        try {
            $sqlCreateDb = "CREATE DATABASE IF NOT EXISTS `$safeDbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
            $this->pdo->exec($sqlCreateDb);
        } catch (PDOException $dbEx) {
            // Ignore privilege issues in cloud/remote environments, assume pre-created database
        }
        
        // Reconnect selecting master database
        $this->connect(true);
        
        // Create master users table
        $sqlUsersTable = "
            CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) UNIQUE NOT NULL,
                password VARCHAR(255) NOT NULL,
                role VARCHAR(20) NOT NULL DEFAULT 'user',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        
        // Create master stores table
        $sqlStoresTable = "
            CREATE TABLE IF NOT EXISTS stores (
                id INT AUTO_INCREMENT PRIMARY KEY,
                store_code VARCHAR(50) UNIQUE NOT NULL,
                created_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                synced TINYINT(1) NOT NULL DEFAULT 0,
                closed TINYINT(1) NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";

        // Create global items table
        $sqlGlobalItemsTable = "
            CREATE TABLE IF NOT EXISTS items (
                UPC VARCHAR(100) NOT NULL PRIMARY KEY,
                SKU VARCHAR(100) NOT NULL,
                Descr VARCHAR(255) NOT NULL,
                Type VARCHAR(100) NULL,
                Attr VARCHAR(100) NULL,
                Size VARCHAR(100) NULL,
                Qty DECIMAL(10,2) NOT NULL DEFAULT 0.00
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
 
        // Create global audit logs table
        $sqlAuditLogsTable = "
            CREATE TABLE IF NOT EXISTS audit_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                store_code VARCHAR(50) NULL,
                username VARCHAR(50) NOT NULL,
                action VARCHAR(100) NOT NULL,
                details TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        
        $this->execute($sqlUsersTable);
        $this->execute($sqlStoresTable);
        $this->execute($sqlGlobalItemsTable);
        $this->execute($sqlAuditLogsTable);
 
        // Dynamically add synced column to stores table for existing installations
        try {
            $this->execute("ALTER TABLE stores ADD COLUMN synced TINYINT(1) NOT NULL DEFAULT 0");
        } catch (Exception $ex) {
            // Column already exists
        }
 
        // Dynamically add Attr, Size, and Qty columns to items table for existing installations
        try {
            $this->execute("ALTER TABLE items ADD COLUMN Attr VARCHAR(100) NULL AFTER Type");
        } catch (Exception $ex) {}
        try {
            $this->execute("ALTER TABLE items ADD COLUMN Size VARCHAR(100) NULL AFTER Attr");
        } catch (Exception $ex) {}
        try {
            $this->execute("ALTER TABLE items ADD COLUMN Qty DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER Size");
        } catch (Exception $ex) {}

        // Seed default global items
        $sqlSeedCheck = "SELECT COUNT(*) as count FROM items";
        $count = $this->query($sqlSeedCheck)[0]['count'];
        if ($count == 0) {
            $sampleProducts = [
                ['0000000022121', '022121', 'TRNSCND USB 2.0 JF310 16GB', 'ACCESSORIES'],
                ['0000000001314', '1314', 'CRAYON REGULAR 48COL', 'OFFICE SUPPLIES'],
                ['0000000002674', '2674', 'PAINTBRUSH 4F PONY', 'ART SUPPLIES'],
                ['0000000022118', '022118', 'BROTHER PRINTER MFCL5900DW', 'MACHINES'],
                ['0000000022119', '022119', 'BROTHER TONER 3448 BLK', 'CONSUMABLES'],
            ];
            $sqlInsertProduct = "INSERT INTO items (UPC, SKU, Descr, Type) VALUES (?, ?, ?, ?)";
            foreach ($sampleProducts as $prod) {
                $this->execute($sqlInsertProduct, $prod);
            }
        }
        
        try {
            $this->execute("ALTER TABLE stores ADD COLUMN created_by INT NULL AFTER store_code");
        } catch (Exception $e) {
            // Already added or table is clean
        }

        try {
            $this->execute("ALTER TABLE stores ADD COLUMN closed TINYINT(1) NOT NULL DEFAULT 0 AFTER synced");
        } catch (Exception $e) {
            // Already added or table is clean
        }
        
        // Seed default master users
        $sqlUserCheck = "SELECT COUNT(*) as count FROM users WHERE username = 'sys_admin'";
        $userCount = $this->query($sqlUserCheck)[0]['count'];
        
        if ($userCount == 0) {
            // Delete legacy admin if exists
            $this->execute("DELETE FROM users WHERE username = 'admin'");
            
            // Insert sys_admin
            $hashedPass = password_hash('sysadmin', PASSWORD_BCRYPT);
            $this->execute("INSERT INTO users (username, password, role) VALUES ('sys_admin', ?, 'system_admin')", [$hashedPass]);
        }
        
        // Seed default operator
        $sqlOpCheck = "SELECT COUNT(*) as count FROM users WHERE username = 'operator'";
        $opCount = $this->query($sqlOpCheck)[0]['count'];
        if ($opCount == 0) {
            $hashedPass = password_hash('operator123', PASSWORD_BCRYPT);
            $this->execute("INSERT INTO users (username, password, role) VALUES ('operator', ?, 'user')", [$hashedPass]);
        }
        
        return true;
    }
    
    // Create dynamically-prefixed tables for a specific store (e.g. tes_countsheet & tes_items)
    public function createStoreTables($storeCode, $createdBy = null, $locatorsCount = 0) {
        $this->connect(true); // Connect to master database
        
        $cleanStore = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($storeCode));
        if (empty($cleanStore)) {
            throw new Exception("Invalid Store Code.");
        }
        
        // Register store in master stores list
        $sqlRegisterStore = "INSERT INTO stores (store_code, created_by) VALUES (?, ?) ON DUPLICATE KEY UPDATE created_by = COALESCE(created_by, VALUES(created_by))";
        $this->execute($sqlRegisterStore, [strtoupper($cleanStore), $createdBy]);
        
        // Construct the store-specific countsheet table
        $sqlCountSheetTable = "
            CREATE TABLE IF NOT EXISTS `{$cleanStore}_countsheet` (
                RecNo INT AUTO_INCREMENT PRIMARY KEY,
                SlotNo VARCHAR(50) NOT NULL DEFAULT '1',
                CountDate DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UPC VARCHAR(100) NOT NULL,
                SKU VARCHAR(100) NULL,
                Descr VARCHAR(255) NULL,
                Qty DECIMAL(10,2) NOT NULL DEFAULT 1.00,
                EditedQty DECIMAL(10,2) NULL,
                Posted TINYINT(1) NOT NULL DEFAULT 0,
                Added TINYINT(1) NOT NULL DEFAULT 0,
                Edited TINYINT(1) NOT NULL DEFAULT 0,
                ScannedBy VARCHAR(100) NULL DEFAULT 'Handheld',
                synced TINYINT(1) NOT NULL DEFAULT 0,
                INDEX idx_slotno (SlotNo)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        
        $this->execute($sqlCountSheetTable);
        
        // Dynamically alter SlotNo column for existing tables to support varchar names
        try {
            $this->execute("ALTER TABLE `{$cleanStore}_countsheet` MODIFY COLUMN SlotNo VARCHAR(50) NOT NULL DEFAULT '1'");
        } catch (Exception $ex) {
            // Table doesn't exist yet or already altered
        }

        // Dynamically add index for existing tables
        try {
            $this->execute("ALTER TABLE `{$cleanStore}_countsheet` ADD INDEX idx_slotno (SlotNo)");
        } catch (Exception $ex) {
            // Already indexed or error
        }

        // Dynamically add synced column for existing tables
        try {
            $this->execute("ALTER TABLE `{$cleanStore}_countsheet` ADD COLUMN synced TINYINT(1) NOT NULL DEFAULT 0");
        } catch (Exception $ex) {
            // Already exists or error
        }
        
        // Construct the store-specific locators table
        $sqlLocatorsTable = "
            CREATE TABLE IF NOT EXISTS `{$cleanStore}_locators` (
                id INT AUTO_INCREMENT PRIMARY KEY,
                locator_name VARCHAR(50) UNIQUE NOT NULL,
                status VARCHAR(20) DEFAULT 'open',
                assigned_operator VARCHAR(100) DEFAULT NULL,
                synced TINYINT(1) NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        $this->execute($sqlLocatorsTable);

        // Dynamically add synced column for existing locators tables
        try {
            $this->execute("ALTER TABLE `{$cleanStore}_locators` ADD COLUMN synced TINYINT(1) NOT NULL DEFAULT 0");
        } catch (Exception $ex) {
            // Already exists or error
        }
        
        // Seed default Slots if table is empty
        $sqlLocatorCheck = "SELECT COUNT(*) as count FROM `{$cleanStore}_locators`";
        $locCount = $this->query($sqlLocatorCheck)[0]['count'];
        if ($locCount == 0) {
            $limit = ($locatorsCount > 0) ? (int) $locatorsCount : 10;
            for ($i = 1; $i <= $limit; $i++) {
                $this->execute("INSERT INTO `{$cleanStore}_locators` (locator_name) VALUES (?)", ["Slot {$i}"]);
            }
        }
        
        return true;
    }
}
