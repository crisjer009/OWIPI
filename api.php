<?php
// Custom error handler for debugging
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    $msg = "[" . date('Y-m-d H:i:s') . "] Error ($errno): $errstr in $errfile on line $errline\n";
    file_put_contents(__DIR__ . '/php_debug.log', $msg, FILE_APPEND);
    return false;
});

// Custom exception handler
set_exception_handler(function ($exception) {
    $msg = "[" . date('Y-m-d H:i:s') . "] Uncaught Exception: " . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n";
    file_put_contents(__DIR__ . '/php_debug.log', $msg, FILE_APPEND);

    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $exception->getMessage()
    ]);
    exit;
});

if (!in_array('ob_gzhandler', ob_list_handlers()) && extension_loaded('zlib') && !headers_sent()) {
    @ob_start('ob_gzhandler');
}

header("Content-Type: application/json");
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/config.php';
// Release session lock immediately to allow concurrent AJAX requests
session_write_close();

if (function_exists('opcache_reset')) {
    @opcache_reset();
}

$rawInput = json_decode(file_get_contents('php://input'), true);
if (!$rawInput) {
    $rawInput = $_POST;
}

$action = $_GET['action'] ?? $_POST['action'] ?? $rawInput['action'] ?? '';

if ($action === 'receive_sync') {
    handleReceiveSync();
}

if ($action === 'create_manual_backup' || $action === 'create_backup' || $action === 'manual_backup' || $action === 'backup') {
    $storeCode = trim($_POST['store_code'] ?? ($_GET['store_code'] ?? ($rawInput['store_code'] ?? '')));
    if (empty($storeCode)) {
        $storeCode = $_SESSION['store_code'] ?? '';
    }
    if (empty($storeCode)) {
        sendResponse(['status' => 'error', 'message' => "Please specify a Store Code to back up."]);
    }
    $db = new OWI_DB();
    createCloudStoreBackup($db, $storeCode);
    sendResponse([
        'status' => 'success',
        'message' => "Successfully created automatic backup snapshot for store '" . strtoupper($storeCode) . "'!"
    ]);
}

// Helper function to send JSON response
function sendResponse($data)
{
    echo json_encode($data);
    exit;
}

// Helper function to write to master audit_logs table
function logAudit($action, $details, $storeCode = null, $overrideUsername = null)
{
    try {
        $db = new OWI_DB();
        $username = !empty($overrideUsername) ? $overrideUsername : ($_SESSION['username'] ?? 'UNKNOWN');
        $store = $storeCode ? $storeCode : ($_SESSION['store_code'] ?? null);

        $sql = "INSERT INTO audit_logs (store_code, username, action, details) VALUES (?, ?, ?, ?)";
        $db->execute($sql, [$store, $username, $action, $details]);
    } catch (Exception $e) {
        error_log("Failed to write audit log: " . $e->getMessage());
    }
}

function ensureCloudBackupsLogTable($db)
{
    try {
        $db->execute("CREATE TABLE IF NOT EXISTS cloud_backups_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            backup_id VARCHAR(255) NOT NULL,
            store_code VARCHAR(50) NOT NULL,
            backup_type VARCHAR(50) NOT NULL DEFAULT 'sql_script',
            scans_count INT DEFAULT 0,
            locators_count INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $colsToEnsure = [
            'backup_id' => "VARCHAR(255) NOT NULL",
            'store_code' => "VARCHAR(50) NOT NULL",
            'backup_type' => "VARCHAR(50) NOT NULL DEFAULT 'sql_script'",
            'scans_count' => "INT DEFAULT 0",
            'locators_count' => "INT DEFAULT 0",
            'created_at' => "DATETIME DEFAULT CURRENT_TIMESTAMP"
        ];
        foreach ($colsToEnsure as $col => $def) {
            try {
                $db->execute("ALTER TABLE cloud_backups_log ADD COLUMN `$col` $def");
            } catch (Exception $eC) {
            }
        }
    } catch (Exception $eTbl) {
    }
}

// Helper function to create automatic backups on Cloud before overwriting store tables
function createCloudStoreBackup($db, $storeCode)
{
    $clean = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($storeCode));
    if (empty($clean))
        return;

    $ts = date('Ymd_His');

    // Ensure log table exists and has all required columns
    ensureCloudBackupsLogTable($db);

    $locsCount = 0;
    $scansCount = 0;
    $existingLocs = [];
    $existingScans = [];
    $existingItems = [];

    try {
        $existingLocs = $db->query("SELECT * FROM `{$clean}_locators`");
        $locsCount = count($existingLocs);
    } catch (Exception $eL) {
    }

    try {
        $existingScans = $db->query("SELECT * FROM `{$clean}_countsheet`");
        $scansCount = count($existingScans);
    } catch (Exception $eS) {
    }

    try {
        $existingItems = $db->query("SELECT * FROM `{$clean}_items`");
    } catch (Exception $eI) {
    }

    $backupDir = __DIR__ . '/backups';
    if (!is_dir($backupDir)) {
        @mkdir($backupDir, 0777, true);
    }
    @chmod($backupDir, 0777);

    // 1. Generate executable .SQL Backup Script
    $sqlFile = $backupDir . "/backup_" . $clean . "_" . $ts . ".sql";
    $sqlScript = "-- OWI Physical Inventory Backup Script\n";
    $sqlScript .= "-- Store: " . strtoupper($clean) . "\n";
    $sqlScript .= "-- Created: " . date('Y-m-d H:i:s') . "\n";
    $sqlScript .= "-- Locators: {$locsCount} | Scans: {$scansCount} | Items: " . count($existingItems) . "\n\n";

    if (!empty($existingLocs)) {
        $sqlScript .= "-- Locators Table Data\n";
        $sqlScript .= "TRUNCATE TABLE `{$clean}_locators`;\n";
        foreach ($existingLocs as $row) {
            $cols = array_keys($row);
            $colNames = implode('`, `', array_map('addslashes', $cols));
            $vals = array_map(function ($v) {
                if ($v === null)
                    return "NULL";
                return "'" . addslashes($v) . "'";
            }, array_values($row));
            $valStr = implode(', ', $vals);
            $sqlScript .= "INSERT INTO `{$clean}_locators` (`{$colNames}`) VALUES ({$valStr});\n";
        }
        $sqlScript .= "\n";
    }

    if (!empty($existingScans)) {
        $sqlScript .= "-- Countsheet Table Data\n";
        $sqlScript .= "TRUNCATE TABLE `{$clean}_countsheet`;\n";
        foreach ($existingScans as $row) {
            $cols = array_keys($row);
            $colNames = implode('`, `', array_map('addslashes', $cols));
            $vals = array_map(function ($v) {
                if ($v === null)
                    return "NULL";
                return "'" . addslashes($v) . "'";
            }, array_values($row));
            $valStr = implode(', ', $vals);
            $sqlScript .= "INSERT INTO `{$clean}_countsheet` (`{$colNames}`) VALUES ({$valStr});\n";
        }
        $sqlScript .= "\n";
    }

    if (!empty($existingItems)) {
        $sqlScript .= "-- Store Items Catalog Data\n";
        $sqlScript .= "TRUNCATE TABLE `{$clean}_items`;\n";
        foreach ($existingItems as $row) {
            $cols = array_keys($row);
            $colNames = implode('`, `', array_map('addslashes', $cols));
            $vals = array_map(function ($v) {
                if ($v === null)
                    return "NULL";
                return "'" . addslashes($v) . "'";
            }, array_values($row));
            $valStr = implode(', ', $vals);
            $sqlScript .= "INSERT INTO `{$clean}_items` (`{$colNames}`) VALUES ({$valStr});\n";
        }
        $sqlScript .= "\n";
    }

    @file_put_contents($sqlFile, $sqlScript);

    // 2. Export JSON backup snapshot file
    $jsonFile = $backupDir . "/cloud_backup_" . $clean . "_" . $ts . ".json";
    $payload = [
        'store_code' => strtoupper($clean),
        'backed_up_at' => date('Y-m-d H:i:s'),
        'locators' => $existingLocs,
        'scans' => $existingScans,
        'products' => $existingItems,
        'items' => $existingItems
    ];
    @file_put_contents($jsonFile, json_encode($payload, JSON_PRETTY_PRINT));

    // 3. Automatically drop legacy _backup_ tables to keep MySQL database completely clean
    try {
        $legacyTables = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '_backup_%'");
        foreach ($legacyTables as $lt) {
            $tName = $lt['TABLE_NAME'] ?? (array_values($lt)[0] ?? '');
            if (!empty($tName) && strpos($tName, '_backup_') === 0) {
                try {
                    $db->execute("DROP TABLE IF EXISTS `{$tName}`");
                } catch (Exception $eD) {
                }
            }
        }
    } catch (Exception $eDrop) {
    }

    // 4. Register entry in cloud_backups_log
    try {
        $backupId = "backup_" . $clean . "_" . $ts . ".sql";
        $db->execute(
            "INSERT INTO cloud_backups_log (backup_id, store_code, backup_type, scans_count, locators_count, created_at) VALUES (?, ?, 'sql_script', ?, ?, NOW())",
            [$backupId, strtoupper($clean), $scansCount, $locsCount]
        );
    } catch (Exception $eLog) {
    }

    logAudit('Cloud Pre-Sync Backup', "Created SQL backup script for store '" . strtoupper($clean) . "' prior to cloud overwrite.");
}

// Helper function to ensure active session columns exist on store locators tables
function ensureLocatorSessionColumns($db, $storeCode)
{
    $clean = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($storeCode));
    if (empty($clean))
        return;
    try {
        $db->execute("ALTER TABLE `{$clean}_locators` ADD COLUMN active_device_token VARCHAR(64) NULL");
    } catch (Exception $e) {
    }
    try {
        $db->execute("ALTER TABLE `{$clean}_locators` ADD COLUMN last_ping_at DATETIME NULL");
    } catch (Exception $e) {
    }
}

function ensureStoresHostLockColumns($db)
{
    try {
        $db->execute("ALTER TABLE stores ADD COLUMN host_session_token VARCHAR(64) NULL");
    } catch (Exception $e) {
    }
    try {
        $db->execute("ALTER TABLE stores ADD COLUMN host_last_ping DATETIME NULL");
    } catch (Exception $e) {
    }
    try {
        $db->execute("ALTER TABLE stores ADD COLUMN host_user_id INT(11) NULL");
    } catch (Exception $e) {
    }
    try {
        $db->execute("ALTER TABLE stores ADD COLUMN index_host_token VARCHAR(64) NULL");
    } catch (Exception $e) {
    }
    try {
        $db->execute("ALTER TABLE stores ADD COLUMN index_last_ping DATETIME NULL");
    } catch (Exception $e) {
    }
    try {
        $db->execute("ALTER TABLE stores ADD COLUMN scan_host_token VARCHAR(64) NULL");
    } catch (Exception $e) {
    }
    try {
        $db->execute("ALTER TABLE stores ADD COLUMN scan_last_ping DATETIME NULL");
    } catch (Exception $e) {
    }
}

// Helper function to format product description by appending Attr and Size if not already present
function formatProductDescription($descr, $attr, $size)
{
    $finalDesc = $descr;
    $lowerDesc = strtolower($descr);

    if (!empty($attr)) {
        $cleanAttr = trim($attr);
        if ($cleanAttr !== '' && strpos($lowerDesc, strtolower($cleanAttr)) === false) {
            $finalDesc .= " " . $cleanAttr;
        }
    }

    if (!empty($size)) {
        $cleanSize = trim($size);
        if ($cleanSize !== '' && strpos($lowerDesc, strtolower($cleanSize)) === false) {
            $finalDesc .= " " . $cleanSize;
        }
    }

    return trim($finalDesc);
}

// Helper function to find a product in items catalog with flexible padding/unpadding support
// Helper function to find a product in items catalog with flexible padding/unpadding and fallback support
function findCatalogProduct($barcode, $storeCode = null)
{
    static $storeTableCache = [];
    static $storeNoCache = [];
    $db = new OWI_DB();
    $barcodeClean = trim($barcode);
    if ($barcodeClean === '') {
        return [];
    }

    $storeInput = $storeCode ?? ($_GET['store_code'] ?? ($_SESSION['store_code'] ?? ''));
    $cleanStore = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($storeInput));

    $tablesToSearch = [];
    if (!empty($cleanStore)) {
        if (!isset($storeTableCache[$cleanStore])) {
            try {
                $tableCheck = $db->query("SHOW TABLES LIKE '{$cleanStore}_items'");
                if (!empty($tableCheck)) {
                    $cntCheck = $db->query("SELECT COUNT(*) as cnt FROM `{$cleanStore}_items`");
                    $storeTableCache[$cleanStore] = !empty($cntCheck) && ((int) $cntCheck[0]['cnt'] > 0);
                } else {
                    $storeTableCache[$cleanStore] = false;
                }
            } catch (Exception $e) {
                $storeTableCache[$cleanStore] = false;
            }
        }
        if ($storeTableCache[$cleanStore]) {
            $tablesToSearch[] = "{$cleanStore}_items";
        }
    }
    // Always include central items table as fallback
    $tablesToSearch[] = 'items';

    foreach ($tablesToSearch as $tableName) {
        $qtyCol = "`Qty`";
        if ($tableName === 'items' && !empty($cleanStore)) {
            if (!isset($storeNoCache[$cleanStore])) {
                try {
                    $storeLookup = $db->query("SELECT str_no FROM stores_id WHERE LOWER(str_code) = ? OR str_no = ? LIMIT 1", [strtolower($cleanStore), $cleanStore]);
                    if (!empty($storeLookup) && is_numeric($storeLookup[0]['str_no'])) {
                        $storeNoCache[$cleanStore] = (int) $storeLookup[0]['str_no'];
                    } else {
                        $numMatch = preg_replace('/[^0-9]/', '', $cleanStore);
                        $storeNoCache[$cleanStore] = ($numMatch !== '') ? (int) $numMatch : 0;
                    }
                } catch (Exception $exLookup) {
                    $storeNoCache[$cleanStore] = 0;
                }
            }
            if (!empty($storeNoCache[$cleanStore])) {
                $strNo = $storeNoCache[$cleanStore];
                $qtyCol = "`QTY_STORE_{$strNo}` as Qty";
            } else {
                $qtyCol = "0.00 as Qty";
            }
        }

        // 1. Direct exact match check (lightning fast with B-Tree index)
        $rows = $db->query("SELECT UPC, SKU, Descr, Type, Attr, Size, Price, Aux1, {$qtyCol} FROM `{$tableName}` WHERE UPC = ? OR SKU = ? OR Aux1 = ?", [$barcodeClean, $barcodeClean, $barcodeClean]);
        if (!empty($rows)) {
            return $rows;
        }

        // 2. Indexed numeric variation matching (handles leading zeros without breaking indexes)
        if (ctype_digit($barcodeClean)) {
            $unpadded = ltrim($barcodeClean, '0');
            if ($unpadded === '') {
                $unpadded = '0';
            }
            $padded6 = str_pad($unpadded, 6, '0', STR_PAD_LEFT);
            $padded12 = str_pad($unpadded, 12, '0', STR_PAD_LEFT);
            $padded13 = str_pad($unpadded, 13, '0', STR_PAD_LEFT);

            $terms = array_values(array_unique([$barcodeClean, $unpadded, $padded6, $padded12, $padded13]));
            $inClause = implode(',', array_fill(0, count($terms), '?'));
            $params = array_merge($terms, $terms, $terms);

            $sql = "SELECT UPC, SKU, Descr, Type, Attr, Size, Price, Aux1, {$qtyCol} FROM `{$tableName}` 
                    WHERE UPC IN ($inClause) OR SKU IN ($inClause) OR Aux1 IN ($inClause)";

            $rows = $db->query($sql, $params);
            if (!empty($rows)) {
                return $rows;
            }
        }
    }

    return [];
}

// Enforce Authentication
$adminActions = ['get_config', 'save_config', 'save_sync_token', 'test_connection', 'init_db', 'restore_default_db', 'clear_scans', 'add_product', 'delete_product', 'import_cloud_products', 'import_cloud_users', 'delete_store', 'purge_inventory_data', 'clear_audit_logs', 'backup_db', 'get_pending_syncs', 'approve_sync_request', 'reject_sync_request', 'reopen_store', 'get_cloud_backups', 'download_cloud_backup', 'restore_cloud_backup', 'create_manual_backup', 'version', 'clear_cloud_backups', 'heartbeat_locator', 'release_session', 'heartbeat_store_host', 'release_store_host'];
$userActions = ['get_diagnostics', 'submit_scan', 'get_scans', 'get_store_summary', 'get_products', 'get_product_info', 'delete_scan', 'get_stores', 'select_store', 'logout_store', 'get_locators', 'add_locator', 'delete_locator', 'claim_locator', 'close_locator', 'approve_locator', 'edit_scan', 'get_print_spacing', 'save_print_spacing', 'get_users', 'add_user', 'delete_user', 'import_masterfile', 'get_audit_logs', 'get_sync_config', 'save_sync_config', 'trigger_cloud_sync', 'get_scans_html', 'close_store', 'get_cloud_stores', 'get_cloud_store_details', 'get_cloud_products', 'get_cloud_users', 'fetch_cloud_stores', 'import_cloud_store', 'submit_sync_request', 'get_pending_syncs', 'approve_sync_request', 'reject_sync_request', 'reopen_store', 'export_masterfile_variance', 'search_masterfile', 'get_cloud_backups', 'download_cloud_backup', 'restore_cloud_backup', 'create_manual_backup', 'version', 'clear_cloud_backups', 'heartbeat_locator', 'release_session', 'heartbeat_store_host', 'release_store_host'];

$storeDependentActions = ['submit_scan', 'get_scans', 'get_store_summary', 'clear_scans', 'get_locators', 'add_locator', 'delete_locator', 'claim_locator', 'close_locator', 'approve_locator', 'edit_scan', 'trigger_cloud_sync', 'get_scans_html', 'close_store', 'export_masterfile_variance', 'search_masterfile'];

try {
    $bypassAuth = false;
    if ($action === 'get_cloud_stores' || $action === 'get_cloud_store_details' || $action === 'get_cloud_products' || $action === 'receive_sync' || $action === 'submit_sync_request' || $action === 'release_session' || $action === 'get_cloud_backups' || $action === 'version' || $action === 'download_cloud_backup' || $action === 'create_manual_backup' || $action === 'restore_cloud_backup' || $action === 'clear_cloud_backups' || $action === 'heartbeat_locator' || $action === 'heartbeat_store_host' || $action === 'release_store_host') {
        $bypassAuth = true;
    }

    $incomingStoreCode = $rawInput['store_code'] ?? ($_GET['store_code'] ?? '');
    if (($action === 'submit_scan' || $action === 'claim_locator' || $action === 'close_locator' || $action === 'get_product_info' || $action === 'get_scans' || $action === 'get_store_summary' || $action === 'edit_scan' || $action === 'delete_scan' || $action === 'get_scans_html') && !empty($incomingStoreCode)) {
        $bypassAuth = true;
        $_SESSION['store_code'] = strtoupper($incomingStoreCode);
    }

    if (!$bypassAuth) {
        if (in_array($action, $adminActions)) {
            checkAuth(true); // Requires system_admin
        } elseif (in_array($action, $userActions)) {
            checkAuth(false); // Requires logged-in user
        } else {
            throw new Exception("Unknown action: " . $action);
        }

        // Verify store selection is active for store-dependent actions
        if (in_array($action, $storeDependentActions) && !hasActiveStore()) {
            sendResponse([
                'status' => 'store_required',
                'message' => 'No active store selected. Please select or create a store session first.'
            ]);
        }
    }

    switch ($action) {
        case 'get_diagnostics':
            sendResponse([
                'status' => 'success',
                'diagnostics' => OWI_DB::getDiagnostics(),
                'driver_loaded' => OWI_DB::isDriverLoaded(),
                'server_ip' => getServerLocalIP()
            ]);
            break;

        case 'get_config':
            sendResponse([
                'status' => 'success',
                'config' => loadConfig()
            ]);
            break;

        case 'get_print_spacing':
            $config = loadConfig();
            sendResponse([
                'status' => 'success',
                'print_margin_top' => isset($config['print_margin_top']) ? (int) $config['print_margin_top'] : 0,
                'print_margin_left' => isset($config['print_margin_left']) ? (int) $config['print_margin_left'] : 0
            ]);
            break;

        case 'save_print_spacing':
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                throw new Exception("Invalid JSON inputs.");
            }
            $config = loadConfig();
            $config['print_margin_top'] = isset($input['print_margin_top']) ? (int) $input['print_margin_top'] : 0;
            $config['print_margin_left'] = isset($input['print_margin_left']) ? (int) $input['print_margin_left'] : 0;

            if (saveConfig($config)) {
                sendResponse([
                    'status' => 'success',
                    'message' => 'Print spacing saved successfully!'
                ]);
            } else {
                throw new Exception("Failed to write print config.");
            }
            break;

        case 'save_config':
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                throw new Exception("Invalid JSON inputs.");
            }

            $config = loadConfig();
            $config['server'] = isset($input['server']) ? trim($input['server']) : 'localhost';
            $config['port'] = isset($input['port']) ? trim($input['port']) : '3306';
            $config['database'] = isset($input['database']) ? trim($input['database']) : 'owi_physical_inventory';
            $config['username'] = isset($input['username']) ? trim($input['username']) : 'root';
            $config['password'] = isset($input['password']) ? trim($input['password']) : '';
            $config['print_margin_top'] = isset($input['print_margin_top']) ? (int) $input['print_margin_top'] : 0;
            $config['print_margin_left'] = isset($input['print_margin_left']) ? (int) $input['print_margin_left'] : 0;

            if (isset($input['sync_secret_token'])) {
                $config['sync_secret_token'] = trim($input['sync_secret_token']);
            }

            if (saveConfig($config)) {
                try {
                    $db = new OWI_DB();
                    $db->initializeDatabase();
                    sendResponse([
                        'status' => 'success',
                        'message' => 'Configuration saved & database initialized successfully!'
                    ]);
                } catch (Exception $e) {
                    sendResponse([
                        'status' => 'success',
                        'message' => 'Configuration saved, but connection failed: ' . $e->getMessage(),
                        'connection_failed' => true
                    ]);
                }
            } else {
                throw new Exception("Failed to write to db_config.json on the server. Please check file permissions (run: chmod 666 db_config.json on the cloud server).");
            }
            break;

        case 'save_sync_token':
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                throw new Exception("Invalid JSON inputs.");
            }
            $config = loadConfig();
            $config['sync_secret_token'] = isset($input['sync_secret_token']) ? trim($input['sync_secret_token']) : '';
            if (saveConfig($config)) {
                sendResponse([
                    'status' => 'success',
                    'message' => 'Secret Sync Token saved successfully!'
                ]);
            } else {
                throw new Exception("Failed to write to db_config.json on the server. Please check file permissions (run: chmod 666 db_config.json on the cloud server).");
            }
            break;

        case 'test_connection':
            if (!OWI_DB::isDriverLoaded()) {
                throw new Exception("PDO MySQL extension is not loaded in PHP.");
            }
            $db = new OWI_DB();
            $db->connect(false);
            sendResponse([
                'status' => 'success',
                'message' => 'Successfully connected to MySQL server host!'
            ]);
            break;

        case 'init_db':
            if (!OWI_DB::isDriverLoaded()) {
                throw new Exception("PDO MySQL extension is not loaded in PHP.");
            }
            $db = new OWI_DB();
            $db->initializeDatabase();
            sendResponse([
                'status' => 'success',
                'message' => 'Master database and tables checked/initialized!'
            ]);
            break;

        case 'restore_default_db':
            @set_time_limit(600);
            @ini_set('memory_limit', '512M');
            $db = new OWI_DB();
            $sqlFile = __DIR__ . "/database.sql";
            if (!file_exists($sqlFile)) {
                throw new Exception("Default database backup file (database.sql) not found in directory.");
            }
            $db->importSqlFile($sqlFile);
            logAudit('Restore Database Backup', "Imported database.sql backup file to restore structure & data.");
            sendResponse([
                'status' => 'success',
                'message' => 'Database successfully restored and catalog items imported from database.sql!'
            ]);
            break;

        case 'backup_db':
            $db = new OWI_DB();
            $backupPath = __DIR__ . '/database.sql';
            try {
                $db->exportDatabaseToSql($backupPath);
                sendResponse([
                    'status' => 'success',
                    'message' => 'Current database structure and data successfully saved as default (database.sql created)!'
                ]);
            } catch (Exception $e) {
                sendResponse([
                    'status' => 'error',
                    'message' => $e->getMessage()
                ]);
            }
            break;

        case 'get_stores':
            $db = new OWI_DB();
            $userId = (int) ($_SESSION['user_id'] ?? 0);
            $role = $_SESSION['role'] ?? 'user';

            if ($role === 'system_admin' || $role === 'admin') {
                $sql = "SELECT id, store_code, DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') as created_at FROM stores WHERE closed = 0 ORDER BY store_code ASC";
                $stores = $db->query($sql);
            } else {
                $sql = "SELECT id, store_code, DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') as created_at FROM stores WHERE closed = 0 AND created_by = ? ORDER BY store_code ASC";
                $stores = $db->query($sql, [$userId]);
            }
            sendResponse([
                'status' => 'success',
                'stores' => $stores
            ]);
            break;

        case 'select_store':
            $input = json_decode(file_get_contents('php://input'), true);
            $storeCode = isset($input['store_code']) ? trim($input['store_code']) : '';
            $locatorsCount = isset($input['locators_count']) ? (int) $input['locators_count'] : 0;
            $mode = isset($input['mode']) ? trim($input['mode']) : '';

            if (empty($storeCode)) {
                throw new Exception("Store Code is required.");
            }

            $cleanStore = preg_replace('/[^a-zA-Z0-9_]/', '', $storeCode);
            if (empty($cleanStore)) {
                throw new Exception("Invalid Store Code.");
            }

            $db = new OWI_DB();

            // Check if target store is closed
            $targetClosedSql = "SELECT closed FROM stores WHERE store_code = ?";
            $targetClosedRows = $db->query($targetClosedSql, [strtoupper($cleanStore)]);
            if (!empty($targetClosedRows) && (int) $targetClosedRows[0]['closed'] === 1) {
                throw new Exception("Store '" . strtoupper($cleanStore) . "' has been finalized and closed. It cannot be reopened or edited.");
            }

            // Enforce single-store session rule: check if there is an active ongoing store session for the current user
            $ongoingStore = null;
            $userId = (int) ($_SESSION['user_id'] ?? 0);
            try {
                $activeStoreRows = $db->query("SELECT store_code FROM stores WHERE created_by = ? AND closed = 0 LIMIT 1", [$userId]);
                if (!empty($activeStoreRows)) {
                    $ongoingStore = strtoupper($activeStoreRows[0]['store_code']);
                }
            } catch (Exception $e) {
                // Ignore query failure fallbacks
            }

            if ($ongoingStore !== null && strtoupper($cleanStore) !== $ongoingStore) {
                throw new Exception("Cannot select or create a new store. Your current store session '" . $ongoingStore . "' is currently ongoing and must be completed (all locators closed) and closed first.");
            }

            if ($mode === 'create') {
                $checkExistSql = "SELECT COUNT(*) as count FROM stores WHERE store_code = ?";
                $existCount = $db->query($checkExistSql, [strtoupper($cleanStore)])[0]['count'];
                if ($existCount > 0) {
                    throw new Exception("Store code '" . strtoupper($cleanStore) . "' already exists.");
                }
            }

            // Provision store tables dynamically only if not initialized yet
            $userId = (int) ($_SESSION['user_id'] ?? 0);
            $tblCheck = $db->query("SHOW TABLES LIKE '{$cleanStore}_locators'");
            if (empty($tblCheck) || $mode === 'create') {
                $db->createStoreTables($cleanStore, $userId, $locatorsCount);
            } else {
                $db->execute("INSERT INTO stores (store_code, created_by) VALUES (?, ?) ON DUPLICATE KEY UPDATE created_by = COALESCE(created_by, VALUES(created_by))", [strtoupper($cleanStore), $userId]);
            }

            ensureStoresHostLockColumns($db);
            $db->execute("UPDATE stores SET index_host_token = NULL, index_last_ping = NULL, scan_host_token = NULL, scan_last_ping = NULL, host_session_token = NULL, host_last_ping = NULL, host_user_id = ? WHERE UPPER(store_code) = ?", [$userId, strtoupper($cleanStore)]);

            $_SESSION['store_code'] = strtoupper($cleanStore);

            sendResponse([
                'status' => 'success',
                'message' => 'Store selected successfully!',
                'store_code' => $_SESSION['store_code']
            ]);
            break;

        case 'close_store':
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                $input = $_POST;
            }
            $storeCode = isset($input['store_code']) ? trim($input['store_code']) : ($_SESSION['store_code'] ?? '');
            if (empty($storeCode)) {
                throw new Exception("Store Code is required.");
            }
            $cleanStore = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($storeCode));

            $db = new OWI_DB();

            // Validate: check locator completion progress
            $checkTbl = $db->query("SHOW TABLES LIKE '{$cleanStore}_locators'");
            if (empty($checkTbl)) {
                $db->createStoreTables($cleanStore);
                $checkTbl = $db->query("SHOW TABLES LIKE '{$cleanStore}_locators'");
            }

            if (empty($checkTbl)) {
                $db->execute("UPDATE stores SET closed = 1 WHERE LOWER(store_code) = ?", [strtolower($cleanStore)]);
                unset($_SESSION['store_code']);
                sendResponse([
                    'status' => 'success',
                    'message' => "Store session '" . strtoupper($cleanStore) . "' closed successfully!"
                ]);
                break;
            }

            $totalRows = $db->query("SELECT COUNT(*) as count FROM `{$cleanStore}_locators`");
            $totalLocators = (int) ($totalRows[0]['count'] ?? 0);

            if ($totalLocators > 0) {
                $closedRows = $db->query("SELECT COUNT(*) as count FROM `{$cleanStore}_locators` WHERE status = 'closed'");
                $closedLocators = (int) ($closedRows[0]['count'] ?? 0);

                if ($closedLocators < $totalLocators) {
                    throw new Exception("Cannot close store. All locators must be closed first (Progress is " . round(($closedLocators / $totalLocators) * 100) . "%).");
                }
            }

            // Update stores table setting closed = 1
            $db->execute("UPDATE stores SET closed = 1 WHERE LOWER(store_code) = ?", [strtolower($cleanStore)]);

            // Clear current store session
            unset($_SESSION['store_code']);

            logAudit('CLOSE_STORE', "Closed store session '" . strtoupper($cleanStore) . "' after 100% completion.", strtoupper($cleanStore));

            sendResponse([
                'status' => 'success',
                'message' => "Store session '" . strtoupper($cleanStore) . "' closed successfully!"
            ]);
            break;

        case 'delete_store':
            $currentRole = $_SESSION['role'] ?? '';
            if (!in_array($currentRole, ['system_admin', 'admin'])) {
                throw new Exception("Only System Administrators or Admins can delete store sessions.");
            }
            $store = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($_GET['store_code'] ?? ''));
            if (empty($store)) {
                throw new Exception("Invalid store code.");
            }

            $db = new OWI_DB();

            // Delete from stores table
            $db->execute("DELETE FROM stores WHERE LOWER(store_code) = ?", [$store]);

            // Drop related dynamic tables
            $db->execute("DROP TABLE IF EXISTS `{$store}_locators`");
            $db->execute("DROP TABLE IF EXISTS `{$store}_countsheet`");
            $db->execute("DROP TABLE IF EXISTS `{$store}_items`");

            // If the deleted store was active in session, clear it
            if (!empty($_SESSION['store_code']) && strtolower($_SESSION['store_code']) === $store) {
                unset($_SESSION['store_code']);
            }

            logAudit('DELETE_STORE', "Permanently deleted store session '" . strtoupper($store) . "' and dropped all its tables.");

            sendResponse([
                'status' => 'success',
                'message' => "Successfully and permanently deleted store session '" . strtoupper($store) . "'!"
            ]);
            break;

        case 'purge_inventory_data':
            checkAuth(true); // Requires system_admin
            if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'system_admin') {
                throw new Exception("Only System Administrators (sys_admin) can perform a Fresh Inventory Reset.");
            }

            $db = new OWI_DB();
            $tables = $db->query("SHOW TABLES");

            $droppedTables = [];
            if (!empty($tables)) {
                foreach ($tables as $tRow) {
                    $tbl = array_values($tRow)[0];
                    $lower = strtolower($tbl);

                    // Protect core infrastructure tables from being dropped
                    if (in_array($lower, ['items', 'stores_id', 'users', 'stores_id_backup'])) {
                        continue;
                    }

                    // Dynamic store tables: *_countsheet, *_locators, *_items
                    if (preg_match('/^[a-z0-9_]+_(countsheet|locators|items)$/', $lower)) {
                        $db->execute("DROP TABLE IF EXISTS `{$tbl}`");
                        $droppedTables[] = $tbl;
                    }
                }
            }

            // Truncate active store sessions, pending sync requests, and audit logs
            try {
                $db->execute("TRUNCATE TABLE stores");
            } catch (Exception $eS) {
            }
            try {
                $db->execute("TRUNCATE TABLE pending_sync_requests");
            } catch (Exception $eP) {
            }
            try {
                $db->execute("TRUNCATE TABLE audit_logs");
            } catch (Exception $eA) {
            }

            // Clear current active store session
            unset($_SESSION['store_code']);

            logAudit('FRESH_INVENTORY_PURGE', "System Admin performed Fresh Inventory Reset. Dropped " . count($droppedTables) . " dynamic store tables while preserving items, stores_id, and users.");

            sendResponse([
                'status' => 'success',
                'message' => "Fresh Inventory Reset successful! Dropped " . count($droppedTables) . " store tables. Master items catalog, stores_id, users, and audit_logs remain 100% clean."
            ]);
            break;

        case 'clear_audit_logs':
            checkAuth(true); // Requires system_admin
            if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'system_admin') {
                throw new Exception("Only System Administrators (sys_admin) can clear audit logs.");
            }
            $db = new OWI_DB();
            try {
                $db->execute("TRUNCATE TABLE audit_logs");
            } catch (Exception $e) {
            }
            sendResponse([
                'status' => 'success',
                'message' => 'Audit logs cleared successfully!'
            ]);
            break;

        case 'logout_store':
            unset($_SESSION['store_code']);
            sendResponse([
                'status' => 'success',
                'message' => 'Store session cleared!'
            ]);
            break;

        case 'submit_scan':
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                $input = $_POST;
            }

            $barcode = isset($input['barcode']) ? trim($input['barcode']) : '';
            $qty = isset($input['quantity']) ? (float) $input['quantity'] : 1.0;
            $location = isset($input['location']) ? trim($input['location']) : '1';
            $scanned_by = isset($input['scanned_by']) ? trim($input['scanned_by']) : 'Handheld';

            if (empty($barcode)) {
                throw new Exception("Barcode (UPC) is required.");
            }
            if ($qty <= 0) {
                $qty = 1.0;
            }

            $db = new OWI_DB();
            $storeInput = $input['store_code'] ?? ($_SESSION['store_code'] ?? '');
            if (empty($storeInput)) {
                throw new Exception("Store code is required.");
            }
            $storeCode = strtoupper($storeInput);

            // Validate that store code exists in stores table
            $storeCheck = $db->query("SELECT COUNT(*) as count FROM stores WHERE LOWER(store_code) = ?", [strtolower($storeCode)]);
            if (empty($storeCheck) || (int) $storeCheck[0]['count'] === 0) {
                throw new Exception("Store code '" . $storeCode . "' does not exist.");
            }

            $store = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($storeCode));

            // Validate that store countsheets are initialized
            $tableCheck = $db->query("SHOW TABLES LIKE '{$store}_locators'");
            if (empty($tableCheck)) {
                throw new Exception("Store code '" . $storeCode . "' has not been initialized on the server yet.");
            }

            // Validate Locator Status (Self-healing auto-claim)
            $sqlCheckLoc = "SELECT status, assigned_operator FROM `{$store}_locators` WHERE locator_name = ?";
            $checkLocRows = $db->query($sqlCheckLoc, [$location]);
            if (!empty($checkLocRows)) {
                $locStatus = $checkLocRows[0]['status'];
                $assignedOp = $checkLocRows[0]['assigned_operator'];

                if ($locStatus === 'closed') {
                    sendResponse([
                        'status' => 'error',
                        'message' => "Locator '$location' is Closed. Ask the Host to reopen it."
                    ]);
                    break;
                }

                if ($locStatus === 'open') {
                    // Check if this operator name is already active in another locator
                    $sqlCheckOp = "SELECT locator_name FROM `{$store}_locators` WHERE status = 'in_use' AND LOWER(TRIM(assigned_operator)) = ? AND LOWER(TRIM(locator_name)) != ?";
                    $checkOpRows = $db->query($sqlCheckOp, [strtolower($scanned_by), strtolower($location)]);
                    if (!empty($checkOpRows)) {
                        $otherLoc = $checkOpRows[0]['locator_name'];
                        sendResponse([
                            'status' => 'error',
                            'message' => "Operator '$scanned_by' is active in locator '$otherLoc'."
                        ]);
                        break;
                    }

                    // Auto-claim or retain previous assigned operator if already present
                    if (!empty($assignedOp)) {
                        $db->execute(
                            "UPDATE `{$store}_locators` SET status = 'in_use', synced = 0 WHERE locator_name = ?",
                            [$location]
                        );
                    } else {
                        $db->execute(
                            "UPDATE `{$store}_locators` SET status = 'in_use', assigned_operator = ?, synced = 0 WHERE locator_name = ?",
                            [$scanned_by, $location]
                        );
                    }
                } elseif ($locStatus === 'in_use' && !empty($assignedOp)) {
                    $isHost = !empty($_SESSION['role']) && ($_SESSION['role'] === 'system_admin' || $_SESSION['role'] === 'admin' || $_SESSION['role'] === 'user_admin' || $_SESSION['role'] === 'host');
                    if (!$isHost && strtolower(trim($assignedOp)) !== strtolower(trim($scanned_by))) {
                        sendResponse([
                            'status' => 'error',
                            'message' => "Locator '$location' is currently claimed by operator '$assignedOp'."
                        ]);
                        break;
                    }
                }
            }

            // Check if product exists in global items catalog (resolving by UPC or SKU with flexible padding)
            $productRows = findCatalogProduct($barcode);

            $product_found = false;
            $product_name = 'Item Not Found';
            $product_type = '';
            $sku = '';
            $real_barcode = $barcode;
            $masterQty = 0.00;

            if (!empty($productRows)) {
                $product_found = true;
                $descr = $productRows[0]['Descr'];
                $attr = $productRows[0]['Attr'] ?? '';
                $size = $productRows[0]['Size'] ?? '';

                $product_name = formatProductDescription($descr, $attr, $size);
                $product_type = $productRows[0]['Type'];
                $sku = $productRows[0]['SKU'];
                $real_barcode = !empty($productRows[0]['UPC']) ? $productRows[0]['UPC'] : (!empty($barcode) ? $barcode : ($productRows[0]['SKU'] ?? ''));
                $masterQty = (float) ($productRows[0]['Qty'] ?? 0.00);
            }

            // Compute total quantity scanned so far for this product in the current locator/slot
            $sumQuery = $db->query("SELECT SUM(IF(Edited = 1, EditedQty, Qty)) as total FROM `{$store}_countsheet` WHERE UPC = ? AND SlotNo = ?", [$real_barcode, $location]);
            $existingScanned = (float) ($sumQuery[0]['total'] ?? 0.00);
            $totalScanned = $existingScanned + $qty;
            $variance = $totalScanned - $masterQty;

            // Insert scan log into dynamic store countsheet table
            $sqlInsertScan = "
                INSERT INTO `{$store}_countsheet` (SlotNo, UPC, SKU, Descr, Qty, ScannedBy, Variance, CountDate) 
                VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ";
            $db->execute($sqlInsertScan, [$location, $real_barcode, $sku, $product_name, $qty, $scanned_by, $variance]);

            // Sync variance column for all records of this product in this slot
            $db->execute("UPDATE `{$store}_countsheet` SET Variance = ? WHERE UPC = ? AND SlotNo = ?", [$variance, $real_barcode, $location]);

            // Retrieve updated scanned count (total number of scans/rows) for this locator
            $countRows = $db->query("SELECT COUNT(*) as count FROM `{$store}_countsheet` WHERE SlotNo = ?", [$location]);
            $scanned_count = !empty($countRows) ? (int) $countRows[0]['count'] : 0;

            // Format custom message including variance info for both Casio and mobile view
            $varianceStr = ($variance >= 0 ? "+" : "") . $variance;
            $successMsg = "Saved! Var: " . $varianceStr;

            sendResponse([
                'status' => 'success',
                'message' => $successMsg,
                'data' => [
                    'barcode' => $barcode,
                    'quantity' => $qty,
                    'location' => $location,
                    'scanned_by' => $scanned_by,
                    'product_found' => $product_found,
                    'product_name' => $product_name,
                    'product_type' => $product_type,
                    'sku' => $sku,
                    'scanned_count' => $scanned_count,
                    'master_qty' => $masterQty,
                    'total_scanned' => $totalScanned,
                    'variance' => $variance
                ]
            ]);
            break;

        case 'get_scans':
            session_write_close();
            $db = new OWI_DB();
            $store = strtolower($_SESSION['store_code'] ?? ($_GET['store_code'] ?? ''));
            $location = isset($_GET['location']) ? trim($_GET['location']) : '';

            if (empty($store)) {
                sendResponse(['status' => 'success', 'scans' => []]);
            }

            // Fast ETag fingerprint check to avoid redundant data transfer when no new scans exist
            $dataHash = '';
            try {
                $fpRow = $db->query("SELECT MAX(RecNo) as max_id, COUNT(*) as cnt, SUM(IF(Edited = 1, EditedQty, Qty)) as sum_qty, MAX(IF(Edited = 1, EditedQty, Qty)) as max_qty FROM `{$store}_countsheet`");
                $dataHash = md5(($fpRow[0]['max_id'] ?? '0') . '_' . ($fpRow[0]['cnt'] ?? '0') . '_' . ($fpRow[0]['sum_qty'] ?? '0') . '_' . ($fpRow[0]['max_qty'] ?? '0') . '_' . $location);
                $clientHash = $_SERVER['HTTP_IF_NONE_MATCH'] ?? ($_GET['hash'] ?? '');
                if (!empty($clientHash) && $clientHash === $dataHash) {
                    sendResponse([
                        'status' => 'unchanged',
                        'hash' => $dataHash,
                        'scans' => null
                    ]);
                }
            } catch (Exception $eFp) {
                $dataHash = '';
            }

            // Fetch scans from dynamic store countsheet table
            $sqlScans = "
                SELECT c.RecNo as id, c.UPC as barcode, c.Qty as original_qty, 
                       IF(c.Edited = 1, c.EditedQty, c.Qty) as quantity, 
                       c.SlotNo as location, c.ScannedBy as scanned_by, 
                       DATE_FORMAT(c.CountDate, '%Y-%m-%d %H:%i:%s') as scanned_at,
                       c.Descr as product_name, c.SKU as sku,
                       c.Added as added, c.Edited as edited, c.EditedQty as edited_qty,
                       c.Variance as variance,
                       COALESCE(i.Qty, m.Qty, 0.00) as master_qty
                FROM `{$store}_countsheet` c
                LEFT JOIN `{$store}_items` i ON (i.UPC = c.UPC OR (c.UPC != '' AND i.SKU = c.UPC) OR (c.SKU != '' AND i.SKU = c.SKU))
                LEFT JOIN `items` m ON (m.UPC = c.UPC OR (c.UPC != '' AND m.SKU = c.UPC) OR (c.SKU != '' AND m.SKU = c.SKU))
            ";

            if ($location !== '') {
                // Remove dynamic "Slot " prefix if passed from local mobile views
                $cleanLoc = str_replace('Slot ', '', $location);
                $sqlScans .= " WHERE TRIM(c.SlotNo) = ? OR TRIM(c.SlotNo) = ? ";
                $sqlScans .= " ORDER BY c.RecNo DESC";
                $scans = $db->query($sqlScans, [$location, "Slot " . $cleanLoc]);
            } else {
                $sqlScans .= " ORDER BY c.RecNo DESC";
                $scans = $db->query($sqlScans);
            }

            sendResponse([
                'status' => 'success',
                'hash' => $dataHash,
                'scans' => $scans
            ]);
            break;

        case 'get_store_summary':
            session_write_close();
            $db = new OWI_DB();
            $store = strtolower($_SESSION['store_code'] ?? ($_GET['store_code'] ?? ''));

            if (empty($store)) {
                sendResponse(['status' => 'success', 'summary' => []]);
            }

            $mode = $_GET['mode'] ?? 'all';
            $hasStoreItems = false;
            try {
                $checkTbl = $db->query("SHOW TABLES LIKE '{$store}_items'");
                if (!empty($checkTbl)) {
                    $cnt = (int) ($db->query("SELECT COUNT(*) as c FROM `{$store}_items`")[0]['c'] ?? 0);
                    if ($cnt > 0) $hasStoreItems = true;
                }
            } catch (Exception $eT) {}

            $summary = [];
            try {
                if ($hasStoreItems) {
                    $sqlSummary = "
                        SELECT 
                            i.UPC as barcode,
                            i.SKU as sku,
                            i.Descr as product_name,
                            COALESCE(i.Qty, 0.00) as master_qty,
                            COALESCE(s.scanned_qty, 0.00) as total_qty
                        FROM `{$store}_items` i
                        LEFT JOIN (
                            SELECT 
                                UPC,
                                SKU,
                                SUM(IF(Edited = 1, EditedQty, Qty)) as scanned_qty
                            FROM `{$store}_countsheet`
                            GROUP BY UPC, SKU
                        ) s ON (s.UPC = i.UPC OR (i.SKU IS NOT NULL AND i.SKU != '' AND (s.SKU = i.SKU OR s.UPC = i.SKU)))
                        
                        UNION ALL
                        
                        SELECT 
                            c.UPC as barcode,
                            c.SKU as sku,
                            c.Descr as product_name,
                            0.00 as master_qty,
                            SUM(IF(c.Edited = 1, c.EditedQty, c.Qty)) as total_qty
                        FROM `{$store}_countsheet` c
                        LEFT JOIN `{$store}_items` i ON (i.UPC = c.UPC OR (c.UPC != '' AND i.SKU = c.UPC) OR (c.SKU != '' AND i.SKU = c.SKU))
                        WHERE i.UPC IS NULL AND (i.SKU IS NULL OR i.SKU = '')
                        GROUP BY c.UPC, c.SKU, c.Descr
                    ";
                    if ($mode === 'variance_only') {
                        $sqlSummary = "SELECT * FROM ({$sqlSummary}) tmp WHERE (total_qty != master_qty)";
                    } else {
                        $sqlSummary = "SELECT * FROM ({$sqlSummary}) tmp WHERE (master_qty > 0 OR total_qty > 0)";
                    }
                    $summary = $db->query($sqlSummary);
                } else {
                    // Fallback to central items table with QTY_STORE_X
                    $strNo = null;
                    try {
                        $storeLookup = $db->query("SELECT str_no FROM stores_id WHERE LOWER(str_code) = ? OR str_no = ? LIMIT 1", [strtolower($store), $store]);
                        if (!empty($storeLookup) && is_numeric($storeLookup[0]['str_no'])) {
                            $strNo = (int) $storeLookup[0]['str_no'];
                        }
                    } catch (Exception $exFb) {}

                    $qtyCol = ($strNo !== null) ? "`QTY_STORE_{$strNo}`" : "0.00";

                    $sqlSummary = "
                        SELECT 
                            m.UPC as barcode,
                            m.SKU as sku,
                            m.Descr as product_name,
                            COALESCE(m.{$qtyCol}, 0.00) as master_qty,
                            COALESCE(s.scanned_qty, 0.00) as total_qty
                        FROM items m
                        LEFT JOIN (
                            SELECT 
                                UPC,
                                SKU,
                                SUM(IF(Edited = 1, EditedQty, Qty)) as scanned_qty
                            FROM `{$store}_countsheet`
                            GROUP BY UPC, SKU
                        ) s ON (s.UPC = m.UPC OR (m.SKU IS NOT NULL AND m.SKU != '' AND (s.SKU = m.SKU OR s.UPC = m.SKU)))
                        
                        UNION ALL
                        
                        SELECT 
                            c.UPC as barcode,
                            c.SKU as sku,
                            c.Descr as product_name,
                            0.00 as master_qty,
                            SUM(IF(c.Edited = 1, c.EditedQty, c.Qty)) as total_qty
                        FROM `{$store}_countsheet` c
                        LEFT JOIN items m ON (m.UPC = c.UPC OR (c.UPC != '' AND m.SKU = c.UPC) OR (c.SKU != '' AND m.SKU = c.SKU))
                        WHERE m.UPC IS NULL AND (m.SKU IS NULL OR m.SKU = '')
                        GROUP BY c.UPC, c.SKU, c.Descr
                    ";
                    if ($mode === 'variance_only') {
                        $sqlSummary = "SELECT * FROM ({$sqlSummary}) tmp WHERE (total_qty != master_qty)";
                    } else {
                        $sqlSummary = "SELECT * FROM ({$sqlSummary}) tmp WHERE (master_qty > 0 OR total_qty > 0)";
                    }
                    $summary = $db->query($sqlSummary);
                }
            } catch (Exception $eS) {
                // Fallback to scans-only summary if tables don't exist
                $sqlScans = "
                    SELECT 
                        c.UPC as barcode,
                        c.SKU as sku,
                        c.Descr as product_name,
                        COALESCE(i.Qty, 0.00) as master_qty,
                        SUM(IF(c.Edited = 1, c.EditedQty, c.Qty)) as total_qty
                    FROM `{$store}_countsheet` c
                    LEFT JOIN `{$store}_items` i ON (i.UPC = c.UPC OR (c.UPC != '' AND i.SKU = c.UPC) OR (c.SKU != '' AND i.SKU = c.SKU))
                    GROUP BY c.UPC, c.SKU, c.Descr
                ";
                if ($mode === 'variance_only') {
                    $sqlScans = "SELECT * FROM ({$sqlScans}) tmp WHERE (total_qty != master_qty)";
                } else {
                    $sqlScans = "SELECT * FROM ({$sqlScans}) tmp WHERE (master_qty > 0 OR total_qty > 0)";
                }
                try {
                    $summary = $db->query($sqlScans);
                } catch (Exception $eSc) {
                    $summary = [];
                }
            }

            sendResponse([
                'status' => 'success',
                'summary' => $summary
            ]);
            break;

        case 'edit_scan':
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                $input = $_POST;
            }

            $id = isset($input['id']) ? (int) $input['id'] : 0;
            $barcode = isset($input['barcode']) ? trim($input['barcode']) : '';
            $qty = isset($input['quantity']) ? (float) $input['quantity'] : 0.0;

            $scanned_by = isset($input['scanned_by']) ? trim($input['scanned_by']) : '';
            if ($scanned_by !== '') {
                $_SESSION['username'] = $scanned_by;
            }

            if ($id <= 0) {
                throw new Exception("Invalid Scan ID.");
            }
            if (empty($barcode)) {
                throw new Exception("Barcode (UPC) is required.");
            }
            if ($qty < 0) {
                throw new Exception("Quantity cannot be negative.");
            }

            $db = new OWI_DB();
            $store = strtolower($_SESSION['store_code']);

            // Look up product in catalog (resolving by UPC or SKU with flexible padding)
            $productRows = findCatalogProduct($barcode);

            $product_name = 'Item Not Found';
            $sku = '';
            $real_barcode = !empty($productRows[0]['UPC']) ? $productRows[0]['UPC'] : (!empty($barcode) ? $barcode : ($productRows[0]['SKU'] ?? ''));

            if (!empty($productRows)) {
                $descr = $productRows[0]['Descr'];
                $attr = $productRows[0]['Attr'] ?? '';
                $size = $productRows[0]['Size'] ?? '';

                $product_name = formatProductDescription($descr, $attr, $size);
                $sku = $productRows[0]['SKU'] ?? '';
            }

            // Fetch old scan state for audit trail
            $oldScanQuery = "SELECT SlotNo, UPC, SKU, Qty, EditedQty, Edited FROM `{$store}_countsheet` WHERE RecNo = ?";
            $oldScanRows = $db->query($oldScanQuery, [$id]);
            $oldDetails = "RecNo: {$id}";
            $slotNo = '1';
            if (!empty($oldScanRows)) {
                $slotNo = $oldScanRows[0]['SlotNo'];
                $origQty = $oldScanRows[0]['Edited'] ? $oldScanRows[0]['EditedQty'] : $oldScanRows[0]['Qty'];
                $oldDetails = "Locator: {$slotNo}, UPC: {$oldScanRows[0]['UPC']}, Qty: {$origQty}";
            }

            $sqlUpdateScan = "
                UPDATE `{$store}_countsheet` 
                SET UPC = ?, SKU = ?, Descr = ?, EditedQty = ?, Edited = 1, synced = 0
                WHERE RecNo = ?
            ";
            $db->execute($sqlUpdateScan, [$real_barcode, $sku, $product_name, $qty, $id]);

            // Consolidate duplicate scan entries for the same product in the same locator slot
            if (!empty($real_barcode) || !empty($sku)) {
                $db->execute(
                    "DELETE FROM `{$store}_countsheet` WHERE SlotNo = ? AND RecNo != ? AND ((UPC = ? AND UPC != '') OR (SKU = ? AND SKU != ''))",
                    [$slotNo, $id, $real_barcode, $sku]
                );
            }

            // Recalculate variance for this product in this slot/locator
            $masterQty = 0.00;
            $productCheck = findCatalogProduct(!empty($real_barcode) ? $real_barcode : $sku, $store);
            if (!empty($productCheck)) {
                $masterQty = (float) ($productCheck[0]['Qty'] ?? 0.00);
            }
            $sumQuery = $db->query("SELECT SUM(IF(Edited = 1, EditedQty, Qty)) as total FROM `{$store}_countsheet` WHERE (UPC = ? OR (UPC = '' AND SKU = ?)) AND SlotNo = ?", [$real_barcode, $sku, $slotNo]);
            $totalScanned = (float) ($sumQuery[0]['total'] ?? 0.00);
            $newVariance = $totalScanned - $masterQty;
            $db->execute("UPDATE `{$store}_countsheet` SET Variance = ? WHERE (UPC = ? OR (UPC = '' AND SKU = ?)) AND SlotNo = ?", [$newVariance, $real_barcode, $sku, $slotNo]);

            logAudit('Edit Scanned Item', "Updated item in {$oldDetails} -> New UPC: {$real_barcode}, New Qty: {$qty}");

            sendResponse([
                'status' => 'success',
                'message' => 'Scan updated successfully!'
            ]);
            break;

        case 'delete_scan':
            $data = json_decode(file_get_contents('php://input'), true);
            if (!$data) {
                $data = $_POST;
            }
            $id = isset($data['id']) ? (int) $data['id'] : 0;

            $scanned_by = isset($data['scanned_by']) ? trim($data['scanned_by']) : '';
            if ($scanned_by !== '') {
                $_SESSION['username'] = $scanned_by;
            }

            if ($id <= 0) {
                throw new Exception("Invalid Scan ID.");
            }
            $db = new OWI_DB();
            $store = strtolower($_SESSION['store_code']);

            // Get details before deleting for audit log
            $scanCheck = $db->query("SELECT SlotNo, UPC, Qty, Descr FROM `{$store}_countsheet` WHERE RecNo = ?", [$id]);
            if (!empty($scanCheck)) {
                $details = "Locator: {$scanCheck[0]['SlotNo']}, UPC: {$scanCheck[0]['UPC']}, Qty: {$scanCheck[0]['Qty']}, Descr: {$scanCheck[0]['Descr']}";
                $real_barcode = $scanCheck[0]['UPC'];
                $slotNo = $scanCheck[0]['SlotNo'];

                $db->execute("DELETE FROM `{$store}_countsheet` WHERE RecNo = ?", [$id]);
                logAudit('Delete Scanned Item', "Deleted scan row: {$details}");

                // Recalculate variance for remaining scans of this product in this slot/locator
                $masterQty = 0.00;
                $productCheck = findCatalogProduct($real_barcode, $store);
                if (!empty($productCheck)) {
                    $masterQty = (float) ($productCheck[0]['Qty'] ?? 0.00);
                }
                $sumQuery = $db->query("SELECT SUM(IF(Edited = 1, EditedQty, Qty)) as total FROM `{$store}_countsheet` WHERE UPC = ? AND SlotNo = ?", [$real_barcode, $slotNo]);
                $totalScanned = (float) ($sumQuery[0]['total'] ?? 0.00);
                $newVariance = $totalScanned - $masterQty;
                $db->execute("UPDATE `{$store}_countsheet` SET Variance = ? WHERE UPC = ? AND SlotNo = ?", [$newVariance, $real_barcode, $slotNo]);
            }

            sendResponse([
                'status' => 'success',
                'message' => 'Scan deleted successfully!'
            ]);
            break;

        case 'clear_scans':
            $db = new OWI_DB();
            $store = strtolower($_SESSION['store_code']);

            $sqlTruncate = "TRUNCATE TABLE `{$store}_countsheet`";
            $db->execute($sqlTruncate);
            logAudit('Clear Scan Logs', "Truncated countsheet table for store: {$store}");
            sendResponse([
                'status' => 'success',
                'message' => 'All count sheets have been cleared!'
            ]);
            break;

        case 'export_masterfile_variance':
            $db = new OWI_DB();
            $storeInput = $_GET['store_code'] ?? ($_SESSION['store_code'] ?? '');
            if (empty($storeInput)) {
                throw new Exception("Store code is required.");
            }
            $store = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($storeInput));

            // Verify if items table exists
            $tableCheck = $db->query("SHOW TABLES LIKE '{$store}_items'");
            if (empty($tableCheck)) {
                throw new Exception("Store items table does not exist. Please import a masterfile first.");
            }

            // Verify if countsheet table exists
            $countsheetCheck = $db->query("SHOW TABLES LIKE '{$store}_countsheet'");
            if (empty($countsheetCheck)) {
                throw new Exception("Store countsheet table does not exist.");
            }

            // Query items with master_qty, scanned_qty, and variance matching by UPC or SKU
            $sql = "
                SELECT 
                    COALESCE(NULLIF(i.UPC, ''), i.SKU, c.item_key) as upc, 
                    COALESCE(NULLIF(i.SKU, ''), i.UPC, c.item_key) as sku, 
                    COALESCE(NULLIF(i.Descr, ''), c.description, 'Item Not Found') as description, 
                    COALESCE(i.Qty, 0.00) as master_qty,
                    COALESCE(c.scanned_qty, 0.00) as scanned_qty,
                    (COALESCE(c.scanned_qty, 0.00) - COALESCE(i.Qty, 0.00)) as variance
                FROM `{$store}_items` i
                LEFT JOIN (
                    SELECT 
                        COALESCE(NULLIF(UPC, ''), SKU) as item_key,
                        MAX(Descr) as description,
                        SUM(IF(Edited = 1, EditedQty, Qty)) as scanned_qty 
                    FROM `{$store}_countsheet`
                    WHERE (UPC IS NOT NULL AND UPC != '') OR (SKU IS NOT NULL AND SKU != '')
                    GROUP BY COALESCE(NULLIF(UPC, ''), SKU)
                ) c ON c.item_key = COALESCE(NULLIF(i.UPC, ''), i.SKU)

                UNION

                SELECT 
                    c.item_key as upc, 
                    c.item_key as sku, 
                    c.description as description, 
                    0.00 as master_qty,
                    c.scanned_qty as scanned_qty,
                    c.scanned_qty as variance
                FROM (
                    SELECT 
                        COALESCE(NULLIF(UPC, ''), SKU) as item_key,
                        MAX(Descr) as description,
                        SUM(IF(Edited = 1, EditedQty, Qty)) as scanned_qty 
                    FROM `{$store}_countsheet`
                    WHERE (UPC IS NOT NULL AND UPC != '') OR (SKU IS NOT NULL AND SKU != '')
                    GROUP BY COALESCE(NULLIF(UPC, ''), SKU)
                ) c
                LEFT JOIN `{$store}_items` i ON c.item_key = COALESCE(NULLIF(i.UPC, ''), i.SKU)
                WHERE i.UPC IS NULL AND i.SKU IS NULL
                ORDER BY upc ASC
            ";

            $rows = $db->query($sql);

            // Set headers to trigger file download
            $filename = "OWI_Masterfile_Variance_" . strtoupper($store) . "_" . date('Ymd_His') . ".csv";
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Pragma: no-cache');
            header('Expires: 0');

            // Open PHP output stream
            $output = fopen('php://output', 'w');

            // Output UTF-8 BOM for Excel compatibility
            fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

            // Write CSV headers
            fputcsv($output, ['Barcode (UPC)', 'ALU/SKU', 'Description', 'Master Qty', 'Scanned Qty', 'Variance']);

            // Write CSV data rows
            $totalMaster = 0.0;
            $totalScanned = 0.0;
            $totalVariance = 0.0;

            foreach ($rows as $row) {
                $master = (float) $row['master_qty'];
                $scanned = (float) $row['scanned_qty'];
                $var = (float) $row['variance'];

                $totalMaster += $master;
                $totalScanned += $scanned;
                $totalVariance += $var;

                fputcsv($output, [
                    $row['upc'],
                    $row['sku'],
                    $row['description'],
                    $master,
                    $scanned,
                    $var
                ]);
            }

            // Write totals row
            fputcsv($output, ['TOTAL', '', '', $totalMaster, $totalScanned, $totalVariance]);

            fclose($output);
            exit;

        case 'search_masterfile':
            $q = trim($_GET['q'] ?? '');
            if ($q === '') {
                sendResponse(['status' => 'success', 'results' => []]);
            }

            $storeCode = $_SESSION['store_code'] ?? ($_GET['store_code'] ?? '');
            $cleanStore = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($storeCode));

            if (empty($cleanStore)) {
                sendResponse(['status' => 'error', 'message' => 'No active store selected.']);
            }

            $db = new OWI_DB();
            $tablesToSearch = [];

            try {
                $tableCheck = $db->query("SHOW TABLES LIKE '{$cleanStore}_items'");
                if (!empty($tableCheck)) {
                    $countCheck = $db->query("SELECT COUNT(*) as count FROM `{$cleanStore}_items`");
                    if (!empty($countCheck) && (int) $countCheck[0]['count'] > 0) {
                        $tablesToSearch[] = "{$cleanStore}_items";
                    }
                }
            } catch (Exception $e) {
            }

            $tablesToSearch[] = 'items';

            $items = [];
            $unpadded = ctype_digit($q) ? ltrim($q, '0') : $q;
            if ($unpadded === '')
                $unpadded = '0';
            $padded6 = ctype_digit($q) ? str_pad($unpadded, 6, '0', STR_PAD_LEFT) : $q;

            foreach ($tablesToSearch as $tableName) {
                $qtyCol = "`Qty`";
                if ($tableName === 'items' && !empty($cleanStore)) {
                    try {
                        $storeLookup = $db->query("SELECT str_no FROM stores_id WHERE LOWER(str_code) = ? OR str_no = ? LIMIT 1", [strtolower($cleanStore), $cleanStore]);
                        if (!empty($storeLookup) && is_numeric($storeLookup[0]['str_no'])) {
                            $strNo = (int) $storeLookup[0]['str_no'];
                            $qtyCol = "`QTY_STORE_{$strNo}` as Qty";
                        } else {
                            $numMatch = preg_replace('/[^0-9]/', '', $cleanStore);
                            if ($numMatch !== '') {
                                $strNo = (int) $numMatch;
                                $qtyCol = "`QTY_STORE_{$strNo}` as Qty";
                            }
                        }
                    } catch (Exception $exLookup) {
                    }
                }

                $searchParam = "%{$q}%";
                $searchPrefix = "{$q}%";
                $searchUnpaddedPrefix = "{$unpadded}%";

                $sql = "SELECT UPC, SKU, Descr, Type, Attr, Size, Price, Aux1, {$qtyCol} FROM `{$tableName}` 
                        WHERE UPC = ? OR SKU = ? OR UPC = ? OR SKU = ?
                           OR TRIM(LEADING '0' FROM SKU) = ? OR TRIM(LEADING '0' FROM UPC) = ?
                           OR SKU LIKE ? OR UPC LIKE ? OR Descr LIKE ?
                        ORDER BY 
                            CASE 
                                WHEN SKU = ? OR SKU = ? OR TRIM(LEADING '0' FROM SKU) = ? THEN 1
                                WHEN SKU LIKE ? OR SKU LIKE ? THEN 2
                                WHEN UPC = ? OR UPC = ? OR TRIM(LEADING '0' FROM UPC) = ? THEN 3
                                WHEN UPC LIKE ? OR UPC LIKE ? THEN 4
                                WHEN Descr LIKE ? THEN 5
                                ELSE 6
                            END ASC, Descr ASC
                        LIMIT 100";

                $params = [
                    $q,
                    $q,
                    $padded6,
                    $padded6,
                    $unpadded,
                    $unpadded,
                    $searchParam,
                    $searchParam,
                    $searchParam,
                    $q,
                    $padded6,
                    $unpadded,
                    $searchPrefix,
                    $searchUnpaddedPrefix,
                    $q,
                    $padded6,
                    $unpadded,
                    $searchPrefix,
                    $searchUnpaddedPrefix,
                    $searchPrefix
                ];

                $rows = $db->query($sql, $params);
                if (!empty($rows)) {
                    $items = $rows;
                    break;
                }
            }

            // Priority sorting: 1st ALU/SKU, 2nd Barcode/UPC, 3rd Description
            $qLower = strtolower($q);
            $unpaddedLower = strtolower($unpadded);
            $padded6Lower = strtolower($padded6);

            usort($items, function ($a, $b) use ($qLower, $unpaddedLower, $padded6Lower) {
                $getRank = function ($item) use ($qLower, $unpaddedLower, $padded6Lower) {
                    $skuRaw = trim($item['SKU'] ?? '');
                    $upcRaw = trim($item['UPC'] ?? '');
                    $descRaw = trim($item['Descr'] ?? '');

                    $sku = strtolower($skuRaw);
                    $upc = strtolower($upcRaw);
                    $desc = strtolower($descRaw);

                    $skuUnpadded = ctype_digit($sku) ? ltrim($sku, '0') : $sku;
                    if ($skuUnpadded === '')
                        $skuUnpadded = '0';

                    $upcUnpadded = ctype_digit($upc) ? ltrim($upc, '0') : $upc;
                    if ($upcUnpadded === '')
                        $upcUnpadded = '0';

                    // Rank 1: Exact SKU match (supports 1-digit, 2-digit, 3-digit ALUs raw, unpadded, or padded6)
                    if ($sku === $qLower || $sku === $unpaddedLower || $sku === $padded6Lower || $skuUnpadded === $unpaddedLower) {
                        return 1;
                    }

                    // Rank 2: SKU starts with match
                    if (strpos($sku, $qLower) === 0 || strpos($sku, $unpaddedLower) === 0 || strpos($skuUnpadded, $unpaddedLower) === 0) {
                        return 2;
                    }

                    // Rank 3: SKU contains match
                    if (strpos($sku, $qLower) !== false || strpos($sku, $unpaddedLower) !== false || strpos($skuUnpadded, $unpaddedLower) !== false) {
                        return 3;
                    }

                    // Rank 4: Exact Barcode/UPC match (raw or unpadded)
                    if ($upc === $qLower || $upc === $unpaddedLower || $upc === $padded6Lower || $upcUnpadded === $unpaddedLower) {
                        return 4;
                    }

                    // Rank 5: Barcode/UPC starts with match
                    if (strpos($upc, $qLower) === 0 || strpos($upc, $unpaddedLower) === 0 || strpos($upcUnpadded, $unpaddedLower) === 0) {
                        return 5;
                    }

                    // Rank 6: Barcode/UPC contains match
                    if (strpos($upc, $qLower) !== false || strpos($upc, $unpaddedLower) !== false || strpos($upcUnpadded, $unpaddedLower) !== false) {
                        return 6;
                    }

                    // Rank 7: Description starts with match
                    if (strpos($desc, $qLower) === 0) {
                        return 7;
                    }

                    // Rank 8: Description contains match
                    if (strpos($desc, $qLower) !== false) {
                        return 8;
                    }

                    return 9;
                };

                $rankA = $getRank($a);
                $rankB = $getRank($b);

                if ($rankA !== $rankB) {
                    return $rankA - $rankB;
                }
                return strcmp(strtolower($a['Descr'] ?? ''), strtolower($b['Descr'] ?? ''));
            });

            // Limit top 40 results after priority sorting
            $items = array_slice($items, 0, 40);

            $results = [];
            foreach ($items as $item) {
                $priceVal = isset($item['Price']) ? (float) $item['Price'] : 0.00;
                $attrText = !empty($item['Attr']) ? $item['Attr'] : (!empty($item['Type']) ? $item['Type'] : 'MASTERFILE ITEM');

                $results[] = [
                    'barcode' => $item['UPC'] ?? '',
                    'sku' => $item['SKU'] ?? 'N/A',
                    'description' => $item['Descr'] ?? 'Item Not Found',
                    'master_qty' => (float) ($item['Qty'] ?? 0),
                    'price' => $priceVal,
                    'attributes' => $attrText
                ];
            }

            sendResponse(['status' => 'success', 'results' => $results]);
            break;

        case 'get_product_info':
            $barcode = isset($_GET['barcode']) ? trim($_GET['barcode']) : '';
            if (empty($barcode)) {
                throw new Exception("Barcode is required.");
            }
            $location = isset($_GET['location']) ? trim($_GET['location']) : '';
            $db = new OWI_DB();

            // Get store code to query scanned counts
            $storeInput = $_GET['store_code'] ?? ($_SESSION['store_code'] ?? '');
            $store = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($storeInput));

            // Look up product in catalog (resolving by UPC or SKU with flexible padding)
            $productRows = findCatalogProduct($barcode);

            $product_found = false;
            $product_name = 'Item Not Found';
            $product_type = '';
            $masterQty = 0.00;
            $totalScanned = 0.00;
            $variance = 0.00;
            $resolvedBarcode = '';
            $resolvedSku = '';

            if (!empty($productRows)) {
                $product_found = true;
                $descr = $productRows[0]['Descr'];
                $attr = $productRows[0]['Attr'] ?? '';
                $size = $productRows[0]['Size'] ?? '';
                $resolvedBarcode = !empty($productRows[0]['UPC']) ? $productRows[0]['UPC'] : (!empty($barcode) ? $barcode : ($productRows[0]['SKU'] ?? ''));
                $resolvedSku = $productRows[0]['SKU'] ?? '';

                $product_name = formatProductDescription($descr, $attr, $size);
                $masterQty = (float) ($productRows[0]['Qty'] ?? 0.00);

                if (!empty($store)) {
                    $tableCheck = $db->query("SHOW TABLES LIKE '{$store}_countsheet'");
                    if (!empty($tableCheck)) {
                        $sumQuery = $db->query("SELECT SUM(IF(Edited = 1, EditedQty, Qty)) as total FROM `{$store}_countsheet` WHERE (UPC = ? OR (UPC = '' AND SKU = ?)) AND SlotNo = ?", [$resolvedBarcode, $resolvedSku, $location]);
                        $totalScanned = (float) ($sumQuery[0]['total'] ?? 0.00);
                    }
                }
                $variance = $totalScanned - $masterQty;
                $varianceStr = ($variance >= 0 ? "+" : "") . $variance;
                $product_type = "Store Qty: {$masterQty} | Scan: {$totalScanned}\nVar: " . $varianceStr;
            }

            sendResponse([
                'status' => 'success',
                'product_found' => $product_found,
                'product_name' => $product_name,
                'product_type' => $product_type,
                'store_qty' => $masterQty,
                'master_qty' => $masterQty,
                'total_scanned' => $totalScanned,
                'variance' => $variance,
                'barcode' => $resolvedBarcode,
                'sku' => $resolvedSku
            ]);
            break;

        case 'release_session':
            if (isset($_SESSION['user_id'])) {
                $userId = $_SESSION['user_id'];
                try {
                    $db = new OWI_DB();
                    $db->execute("UPDATE users SET session_token = NULL, last_activity = 0 WHERE id = ?", [$userId]);
                } catch (Exception $ex) {
                }
                $_SESSION = array();
                if (ini_get("session.use_cookies")) {
                    $params = session_get_cookie_params();
                    setcookie(
                        session_name(),
                        '',
                        time() - 42000,
                        $params["path"],
                        $params["domain"],
                        $params["secure"],
                        $params["httponly"]
                    );
                }
                @session_destroy();
            }
            sendResponse(['status' => 'success']);
            break;

        case 'get_products':
            session_write_close();
            $db = new OWI_DB();
            $storeInput = $_GET['store_code'] ?? ($_SESSION['store_code'] ?? '');
            $cleanStore = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($storeInput));

            $tableName = 'items';
            if (!empty($cleanStore)) {
                try {
                    $tableCheck = $db->query("SHOW TABLES LIKE '{$cleanStore}_items'");
                    if (!empty($tableCheck)) {
                        $countCheck = $db->query("SELECT COUNT(*) as count FROM `{$cleanStore}_items`");
                        if (!empty($countCheck) && (int) $countCheck[0]['count'] > 0) {
                            $tableName = "{$cleanStore}_items";
                        }
                    }
                } catch (Exception $e) {
                }
            }

            $sqlProducts = "SELECT UPC as barcode, SKU as sku, Descr as product_name, Type as type, Attr as attr, Size as size, Price as price, Aux1 as aux1, Qty as master_qty FROM `{$tableName}` ORDER BY Descr ASC";
            $products = $db->query($sqlProducts);
            sendResponse([
                'status' => 'success',
                'products' => $products
            ]);
            break;

        case 'add_product':
            $input = json_decode(file_get_contents('php://input'), true);
            $barcode = isset($input['barcode']) ? trim($input['barcode']) : '';
            $name = isset($input['product_name']) ? trim($input['product_name']) : '';
            $sku = isset($input['sku']) ? trim($input['sku']) : '';
            $type = isset($input['type']) ? trim($input['type']) : 'GENERAL';
            $attr = isset($input['attr']) ? trim($input['attr']) : null;
            $size = isset($input['size']) ? trim($input['size']) : null;
            $price = isset($input['price']) ? (float) $input['price'] : 0.00;
            $aux1 = isset($input['aux1']) ? trim($input['aux1']) : null;

            if (empty($barcode) || empty($name)) {
                throw new Exception("UPC (Barcode) and Product Description are required.");
            }

            $db = new OWI_DB();

            // Check if product exists to decide action name
            $checkProd = $db->query("SELECT UPC FROM items WHERE UPC = ?", [$barcode]);
            $actionName = !empty($checkProd) ? 'Edit Catalog Product' : 'Add Catalog Product';

            // Insert/Update global items catalog
            $sqlInsert = "
                INSERT INTO items (UPC, SKU, Descr, Type, Attr, Size, Price, Aux1) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    SKU = VALUES(SKU), 
                    Descr = VALUES(Descr), 
                    Type = VALUES(Type),
                    Attr = VALUES(Attr),
                    Size = VALUES(Size),
                    Price = VALUES(Price),
                    Aux1 = VALUES(Aux1)
            ";
            $db->execute($sqlInsert, [$barcode, $sku, $name, $type, $attr, $size, $price, $aux1]);

            logAudit($actionName, "UPC: {$barcode}, SKU: {$sku}, Description: {$name}, Type: {$type}");

            sendResponse([
                'status' => 'success',
                'message' => 'Product catalog updated successfully!'
            ]);
            break;

        case 'delete_product':
            $input = json_decode(file_get_contents('php://input'), true);
            $barcode = isset($input['barcode']) ? trim($input['barcode']) : '';

            if (empty($barcode)) {
                throw new Exception("UPC (Barcode) is required to delete.");
            }

            $db = new OWI_DB();
            $db->execute("DELETE FROM items WHERE UPC = ?", [$barcode]);

            logAudit('Delete Catalog Product', "Deleted product with UPC: {$barcode}");

            sendResponse([
                'status' => 'success',
                'message' => 'Product deleted from catalog successfully!'
            ]);
            break;

        case 'import_masterfile':
            @set_time_limit(300);
            @ini_set('memory_limit', '512M');

            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("No file uploaded or file upload error.");
            }

            $filePath = $_FILES['file']['tmp_name'];
            $fileContent = file_get_contents($filePath);
            if ($fileContent === false) {
                throw new Exception("Failed to read uploaded file.");
            }

            // Split into lines
            $lines = preg_split('/\r\n|\r|\n/', $fileContent);
            if (empty($lines)) {
                throw new Exception("The uploaded file is empty.");
            }

            $db = new OWI_DB();

            $recordsToInsert = [];
            $headerChecked = false;

            // Header column mapping
            $aluIdx = -1;
            $upcIdx = -1;
            $desc1Idx = -1;
            $desc2Idx = -1;
            $attrIdx = -1;
            $sizeIdx = -1;
            $priceIdx = -1;
            $aux1Idx = -1;
            $qtyIdx = -1;
            $storeQtyIdxs = [];

            $storeInput = $_POST['store_code'] ?? ($_GET['store_code'] ?? '');
            $cleanStore = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($storeInput));

            // Determine target store number if active store is provided
            $targetStoreNo = null;
            if (!empty($cleanStore)) {
                try {
                    $storeLookup = $db->query("SELECT str_no FROM stores_id WHERE LOWER(str_code) = ? OR str_no = ? LIMIT 1", [$cleanStore, $cleanStore]);
                    if (!empty($storeLookup) && is_numeric($storeLookup[0]['str_no'])) {
                        $targetStoreNo = (int) $storeLookup[0]['str_no'];
                    }
                } catch (Exception $e) {
                }

                if (!$targetStoreNo) {
                    $numMatch = preg_replace('/[^0-9]/', '', $cleanStore);
                    if ($numMatch !== '') {
                        $targetStoreNo = (int) $numMatch;
                    }
                }
            }

            // Central catalog or store items table
            if (!empty($cleanStore)) {
                $db->createStoreTables($cleanStore);
                $targetTables = ["{$cleanStore}_items"];
            } else {
                $db->ensureItemsColumnsExist('items');
                $targetTables = ['items'];
            }

            // Start transaction for speed
            $db->execute("START TRANSACTION");

            try {
                // Clear existing catalog table for current target tables
                foreach ($targetTables as $targetTbl) {
                    $db->execute("TRUNCATE TABLE `{$targetTbl}`");
                }

                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }

                    // Auto-detect delimiter: tab (\t) or comma (,)
                    $delimiter = (strpos($line, "\t") !== false) ? "\t" : ",";
                    $cols = str_getcsv($line, $delimiter);
                    if (empty($cols)) {
                        continue;
                    }

                    if (!$headerChecked) {
                        $storeCodeClean = preg_replace('/[^a-zA-Z0-9]/', '', strtolower($cleanStore));
                        $storeCodeNum = preg_replace('/[^0-9]/', '', $storeCodeClean);

                        foreach ($cols as $idx => $headerName) {
                            $rawHeader = trim(str_replace(['_', ' '], '', strtolower($headerName)));
                            $rawHeader = trim($rawHeader, '"\'');

                            if ($rawHeader === 'alu' || $rawHeader === 'sku') {
                                $aluIdx = $idx;
                            } elseif ($upcIdx === -1 && ($rawHeader === 'localupc' || strpos($rawHeader, 'upc') !== false || strpos($rawHeader, 'barcode') !== false)) {
                                $upcIdx = $idx;
                            } elseif ($rawHeader === 'description1' || $rawHeader === 'desc1' || $rawHeader === 'description') {
                                $desc1Idx = $idx;
                            } elseif ($rawHeader === 'description2' || $rawHeader === 'desc2') {
                                $desc2Idx = $idx;
                            } elseif ($rawHeader === 'attr' || $rawHeader === 'attribute') {
                                $attrIdx = $idx;
                            } elseif ($rawHeader === 'siz' || $rawHeader === 'size') {
                                $sizeIdx = $idx;
                            } elseif ($rawHeader === 'price') {
                                $priceIdx = $idx;
                            } elseif ($rawHeader === 'aux1') {
                                $aux1Idx = $idx;
                            } elseif (preg_match('/^qtystore(\d+)$/i', $rawHeader, $m)) {
                                $sNum = (int) $m[1];
                                if ($sNum >= 1 && $sNum <= 125) {
                                    $storeQtyIdxs[$sNum] = $idx;
                                }
                            } elseif (strpos($rawHeader, 'qty') !== false || strpos($rawHeader, 'quantity') !== false || strpos($rawHeader, 'stroh') !== false) {
                                if (!empty($storeCodeClean) && strpos($rawHeader, $storeCodeClean) !== false) {
                                    $qtyIdx = $idx;
                                } elseif (!empty($storeCodeNum) && strpos($rawHeader, 'store' . $storeCodeNum) !== false) {
                                    $qtyIdx = $idx;
                                } elseif ($qtyIdx === -1) {
                                    $qtyIdx = $idx;
                                }
                            }
                        }

                        // Default positional indexes matching standard layout (ALU, LOCAL_UPC, DESCRIPTION1, DESCRIPTION2, ATTR, SIZ, PRICE, AUX1)
                        if ($aluIdx === -1)
                            $aluIdx = 0;
                        if ($upcIdx === -1 && isset($cols[1]))
                            $upcIdx = 1;
                        if ($desc1Idx === -1 && isset($cols[2]))
                            $desc1Idx = 2;
                        if ($desc2Idx === -1 && isset($cols[3]))
                            $desc2Idx = 3;
                        if ($attrIdx === -1 && isset($cols[4]))
                            $attrIdx = 4;
                        if ($sizeIdx === -1 && isset($cols[5]))
                            $sizeIdx = 5;
                        if ($priceIdx === -1 && isset($cols[6]))
                            $priceIdx = 6;
                        if ($aux1Idx === -1 && isset($cols[7]))
                            $aux1Idx = 7;

                        $headerChecked = true;

                        // Check if this row is header row and skip
                        $isHeaderRow = false;
                        foreach ($cols as $colVal) {
                            $cleanColVal = trim(strtolower($colVal));
                            $cleanColVal = trim($cleanColVal, '"\'');
                            if ($cleanColVal === 'alu' || $cleanColVal === 'local_upc' || $cleanColVal === 'description1' || $cleanColVal === 'qty_store_1') {
                                $isHeaderRow = true;
                                break;
                            }
                        }
                        if ($isHeaderRow) {
                            continue;
                        }
                    }

                    $alu = isset($cols[$aluIdx]) ? trim($cols[$aluIdx], "\t\n\r\0\x0B\"'") : '';
                    $localUpc = ($upcIdx !== -1 && isset($cols[$upcIdx])) ? trim($cols[$upcIdx], "\t\n\r\0\x0B\"'") : '';
                    $desc1 = ($desc1Idx !== -1 && isset($cols[$desc1Idx])) ? trim($cols[$desc1Idx], "\t\n\r\0\x0B\"'") : '';
                    $desc2 = ($desc2Idx !== -1 && isset($cols[$desc2Idx])) ? trim($cols[$desc2Idx], "\t\n\r\0\x0B\"'") : '';
                    $attr = ($attrIdx !== -1 && isset($cols[$attrIdx])) ? trim($cols[$attrIdx], "\t\n\r\0\x0B\"'") : '';
                    $size = ($sizeIdx !== -1 && isset($cols[$sizeIdx])) ? trim($cols[$sizeIdx], "\t\n\r\0\x0B\"'") : '';
                    $priceStr = ($priceIdx !== -1 && isset($cols[$priceIdx])) ? trim($cols[$priceIdx], "\t\n\r\0\x0B\"'") : '0';
                    $price = is_numeric($priceStr) ? (float) $priceStr : 0.00;
                    $aux1 = ($aux1Idx !== -1 && isset($cols[$aux1Idx])) ? trim($cols[$aux1Idx], "\t\n\r\0\x0B\"'") : '';

                    if ($alu === '' && $localUpc === '') {
                        continue;
                    }

                    $fallbackUpc = str_pad($alu, 13, '0', STR_PAD_LEFT);
                    $upc = (!empty($localUpc)) ? $localUpc : $fallbackUpc;
                    $sku = ($alu !== '') ? $alu : $upc;
                    $descr = $desc1;
                    $type = $desc2;

                    // Store quantities 1..125
                    $qtyStoreRow = [];
                    $totalQtySum = 0.00;
                    for ($s = 1; $s <= 125; $s++) {
                        $colI = $storeQtyIdxs[$s] ?? (7 + $s);
                        $qVal = (isset($cols[$colI]) && is_numeric(trim($cols[$colI], "\t\n\r\0\x0B\"'"))) ? (float) trim($cols[$colI], "\t\n\r\0\x0B\"'") : 0.00;
                        $qtyStoreRow[$s] = $qVal;
                        $totalQtySum += $qVal;
                    }

                    // Active store Qty
                    if ($targetStoreNo !== null && isset($qtyStoreRow[$targetStoreNo])) {
                        $qty = $qtyStoreRow[$targetStoreNo];
                    } elseif ($qtyIdx !== -1 && isset($cols[$qtyIdx]) && is_numeric(trim($cols[$qtyIdx], "\t\n\r\0\x0B\"'"))) {
                        $qty = (float) trim($cols[$qtyIdx], "\t\n\r\0\x0B\"'");
                    } else {
                        $qty = $totalQtySum;
                    }

                    $rowRecord = [
                        'upc' => $upc,
                        'sku' => $sku,
                        'descr' => $descr,
                        'type' => $type,
                        'attr' => $attr,
                        'size' => $size,
                        'price' => $price,
                        'aux1' => $aux1,
                        'qty' => $qty,
                        'store_qtys' => $qtyStoreRow
                    ];

                    $recordsToInsert[] = $rowRecord;
                }

                // Batch insert records in 200-item chunks
                $chunkSize = 200;
                $chunks = array_chunk($recordsToInsert, $chunkSize);

                // Insert columns list
                $colNames = ['UPC', 'SKU', 'Descr', 'Type', 'Attr', 'Size', 'Price', 'Aux1', 'Qty'];
                for ($s = 1; $s <= 125; $s++) {
                    $colNames[] = "QTY_STORE_{$s}";
                }
                $colSql = implode(', ', array_map(function ($c) {
                    return "`{$c}`"; }, $colNames));

                $updateAssignments = [];
                foreach (['SKU', 'Descr', 'Type', 'Attr', 'Size', 'Price', 'Aux1', 'Qty'] as $f) {
                    $updateAssignments[] = "`{$f}` = VALUES(`{$f}`)";
                }
                for ($s = 1; $s <= 125; $s++) {
                    $updateAssignments[] = "`QTY_STORE_{$s}` = VALUES(`QTY_STORE_{$s}`)";
                }
                $updateSql = implode(', ', $updateAssignments);

                $singleRowPlaceholder = "(" . implode(', ', array_fill(0, count($colNames), '?')) . ")";

                foreach ($chunks as $chunk) {
                    foreach ($targetTables as $targetTbl) {
                        $placeholders = [];
                        $params = [];
                        foreach ($chunk as $row) {
                            $placeholders[] = $singleRowPlaceholder;
                            $params[] = $row['upc'];
                            $params[] = $row['sku'];
                            $params[] = $row['descr'];
                            $params[] = $row['type'];
                            $params[] = $row['attr'];
                            $params[] = $row['size'];
                            $params[] = $row['price'];
                            $params[] = $row['aux1'];
                            $params[] = $row['qty'];
                            for ($s = 1; $s <= 125; $s++) {
                                $params[] = $row['store_qtys'][$s] ?? 0.00;
                            }
                        }
                        $sqlChunk = "
                            INSERT INTO `{$targetTbl}` ({$colSql}) 
                            VALUES " . implode(', ', $placeholders) . "
                        ";
                        $db->execute($sqlChunk, $params);
                    }
                }
                $importedCount = count($recordsToInsert);

                $db->execute("COMMIT");
            } catch (Exception $e) {
                $db->execute("ROLLBACK");
                throw $e;
            }

            sendResponse([
                'status' => 'success',
                'message' => "Successfully imported {$importedCount} products into store catalog!"
            ]);
            break;

        case 'get_users':
            $currentRole = strtolower(trim($_SESSION['role'] ?? ''));
            if (!in_array($currentRole, ['system_admin', 'sys_admin', 'admin'])) {
                sendResponse(['status' => 'error', 'message' => 'Unauthorized access.']);
            }
            $db = new OWI_DB();
            if ($currentRole === 'admin') {
                $sql = "SELECT id, username, role, DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') as created_at FROM users WHERE role = 'user' ORDER BY username ASC";
            } else {
                $sql = "SELECT id, username, role, DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') as created_at FROM users ORDER BY username ASC";
            }
            $users = $db->query($sql);
            sendResponse([
                'status' => 'success',
                'users' => $users
            ]);
            break;

        case 'add_user':
            $currentRole = strtolower(trim($_SESSION['role'] ?? ''));
            if (!in_array($currentRole, ['system_admin', 'sys_admin', 'admin'])) {
                sendResponse(['status' => 'error', 'message' => 'Unauthorized access.']);
            }
            $input = json_decode(file_get_contents('php://input'), true);
            $username = isset($input['username']) ? strtoupper(trim($input['username'])) : '';
            $password = isset($input['password']) ? trim($input['password']) : '';
            $role = isset($input['role']) ? strtolower(trim($input['role'])) : 'user';

            if (empty($username) || empty($password)) {
                throw new Exception("Username and password are required.");
            }

            if ($currentRole === 'admin') {
                $role = 'user';
            }

            if (!in_array($role, ['system_admin', 'sys_admin', 'admin', 'user'])) {
                throw new Exception("Invalid user role.");
            }

            $db = new OWI_DB();
            $checkSql = "SELECT COUNT(*) as count FROM users WHERE username = ?";
            $count = $db->query($checkSql, [$username])[0]['count'];
            if ($count > 0) {
                throw new Exception("Username already exists.");
            }

            $hashedPass = password_hash($password, PASSWORD_BCRYPT);
            $insertSql = "INSERT INTO users (username, password, role) VALUES (?, ?, ?)";
            $db->execute($insertSql, [$username, $hashedPass, $role]);

            sendResponse([
                'status' => 'success',
                'message' => 'User account created successfully!'
            ]);
            break;

        case 'delete_user':
            $currentRole = strtolower(trim($_SESSION['role'] ?? ''));
            if (!in_array($currentRole, ['system_admin', 'sys_admin', 'admin'])) {
                sendResponse(['status' => 'error', 'message' => 'Unauthorized access.']);
            }
            $input = json_decode(file_get_contents('php://input'), true);
            $userId = isset($input['id']) ? (int) $input['id'] : 0;

            if ($userId <= 0) {
                throw new Exception("Invalid user ID.");
            }

            $db = new OWI_DB();
            $userSql = "SELECT username, role FROM users WHERE id = ?";
            $userRows = $db->query($userSql, [$userId]);

            if (empty($userRows)) {
                throw new Exception("User not found.");
            }

            $userToDelete = $userRows[0];

            if ($userId === (int) $_SESSION['user_id']) {
                throw new Exception("You cannot delete your own logged-in account!");
            }

            if ($userToDelete['username'] === 'sys_admin') {
                throw new Exception("The primary system administrator account (sys_admin) cannot be deleted.");
            }

            if ($_SESSION['role'] === 'admin' && $userToDelete['role'] !== 'user') {
                throw new Exception("Store administrators can only delete operator accounts.");
            }

            $deleteSql = "DELETE FROM users WHERE id = ?";
            $db->execute($deleteSql, [$userId]);

            sendResponse([
                'status' => 'success',
                'message' => 'User account deleted successfully!'
            ]);
            break;

        case 'get_locators':
            session_write_close();
            $db = new OWI_DB();
            $storeCode = $_SESSION['store_code'] ?? ($_GET['store_code'] ?? '');
            if (empty($storeCode)) {
                sendResponse(['status' => 'error', 'message' => 'No active store selected.']);
            }

            $store = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($storeCode));

            // Fast ETag fingerprint check to avoid redundant data transfer when locators have not changed
            $dataHash = '';
            try {
                $fpRow = $db->query("SELECT COUNT(*) as cnt, SUM(IF(status='closed',1,0)) as cls FROM `{$store}_locators`");
                $csFpRow = $db->query("SELECT MAX(RecNo) as max_scan FROM `{$store}_countsheet`");
                $dataHash = md5(($fpRow[0]['cnt'] ?? '0') . '_' . ($fpRow[0]['cls'] ?? '0') . '_' . ($csFpRow[0]['max_scan'] ?? '0'));
                $clientHash = $_SERVER['HTTP_IF_NONE_MATCH'] ?? ($_GET['hash'] ?? '');
                if (!empty($clientHash) && $clientHash === $dataHash) {
                    sendResponse([
                        'status' => 'unchanged',
                        'hash' => $dataHash,
                        'locators' => null
                    ]);
                }
            } catch (Exception $eFp) {
                $dataHash = '';
            }

            // Validate that store code exists in stores table
            $storeCheck = $db->query("SELECT COUNT(*) as count FROM stores WHERE LOWER(store_code) = ?", [strtolower($storeCode)]);
            if (empty($storeCheck) || (int) $storeCheck[0]['count'] === 0) {
                unset($_SESSION['store_code']);
                sendResponse([
                    'status' => 'store_required',
                    'message' => 'The active store session was deleted or closed.'
                ]);
            }

            // Self-healing: Ensure locators table is dynamically provisioned if session was already active
            try {
                $checkTbl = $db->query("SHOW TABLES LIKE '{$store}_locators'");
                if (empty($checkTbl)) {
                    $db->createStoreTables($storeCode);
                }
            } catch (Exception $ex) {
                // Fallback silently
            }

            $locators = $db->query("
                SELECT l.*, 
                       COALESCE(SUM(IF(c.Edited = 1, c.EditedQty, c.Qty)), 0) as total_qty, 
                       COUNT(c.RecNo) as total_scans
                FROM `{$store}_locators` l
                LEFT JOIN `{$store}_countsheet` c ON TRIM(c.SlotNo) = TRIM(l.locator_name)
                GROUP BY l.id, l.locator_name, l.status, l.assigned_operator
                ORDER BY l.id ASC
            ");
            sendResponse(['status' => 'success', 'hash' => $dataHash, 'locators' => $locators]);
            break;

        case 'add_locator':
            $data = json_decode(file_get_contents('php://input'), true);
            $name = trim($data['locator_name'] ?? '');
            if ($name === '') {
                throw new Exception("Locator name is required.");
            }
            $db = new OWI_DB();
            $store = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($_SESSION['store_code']));
            $db->execute("INSERT INTO `{$store}_locators` (locator_name) VALUES (?)", [$name]);
            sendResponse(['status' => 'success', 'message' => "Locator '$name' added successfully!"]);
            break;

        case 'delete_locator':
            $data = json_decode(file_get_contents('php://input'), true);
            $id = (int) ($data['id'] ?? 0);
            if ($id <= 0) {
                throw new Exception("Invalid locator ID.");
            }
            $db = new OWI_DB();
            $store = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($_SESSION['store_code']));

            // Retrieve name for audit logging
            $locRows = $db->query("SELECT locator_name FROM `{$store}_locators` WHERE id = ?", [$id]);
            $locName = !empty($locRows) ? $locRows[0]['locator_name'] : "ID {$id}";

            $db->execute("DELETE FROM `{$store}_locators` WHERE id = ?", [$id]);
            logAudit('Delete Locator', "Deleted locator '{$locName}' and all associated scans");

            sendResponse(['status' => 'success', 'message' => "Locator deleted successfully!"]);
            break;

        case 'claim_locator':
            $data = json_decode(file_get_contents('php://input'), true);
            $name = trim($data['locator_name'] ?? '');
            $operator = trim($data['scanned_by'] ?? '');
            $deviceToken = trim($data['device_token'] ?? '');
            if ($name === '' || $operator === '') {
                throw new Exception("Locator name and Operator name are required.");
            }
            if ($operator !== '') {
                $_SESSION['username'] = $operator;
            }
            $db = new OWI_DB();

            // Validate that store code exists in stores table
            $storeCode = $_SESSION['store_code'] ?? '';
            $storeCheck = $db->query("SELECT COUNT(*) as count FROM stores WHERE LOWER(store_code) = ?", [strtolower($storeCode)]);
            if (empty($storeCheck) || (int) $storeCheck[0]['count'] === 0) {
                throw new Exception("Store code '" . $storeCode . "' does not exist.");
            }

            $store = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($storeCode));
            ensureLocatorSessionColumns($db, $store);

            // Validate that store countsheets are initialized
            $tableCheck = $db->query("SHOW TABLES LIKE '{$store}_locators'");
            if (empty($tableCheck)) {
                throw new Exception("Store code '" . $storeCode . "' has not been initialized on the server yet.");
            }

            // Check status
            $loc = $db->query("SELECT * FROM `{$store}_locators` WHERE locator_name = ?", [$name]);
            if (empty($loc)) {
                throw new Exception("Locator '$name' does not exist.");
            }
            $loc = $loc[0];
            if ($loc['status'] === 'closed') {
                throw new Exception("This locator is finished/closed and needs Host approval to reopen.");
            }

            // Check active session lock (if claimed on another browser session within last 15 seconds)
            $lastPing = !empty($loc['last_ping_at']) ? strtotime($loc['last_ping_at']) : 0;
            $isPingActive = (time() - $lastPing) < 15;
            $activeToken = $loc['active_device_token'] ?? '';
            $assignedOp = strtolower(trim($loc['assigned_operator'] ?? ''));
            $currentOp = strtolower(trim($operator));

            if ($loc['status'] === 'in_use' && !empty($activeToken) && !empty($deviceToken) && $activeToken !== $deviceToken && $isPingActive && $assignedOp !== $currentOp) {
                $activeOp = !empty($loc['assigned_operator']) ? $loc['assigned_operator'] : 'another browser session';
                throw new Exception("Locator '$name' is currently active in another browser session (Operator: {$activeOp}). Multiple concurrent browser windows for the same locator are disabled.");
            }

            if ($loc['status'] === 'in_use' && $assignedOp !== $currentOp && $isPingActive) {
                throw new Exception("This locator is already claimed by operator: " . $loc['assigned_operator']);
            }

            // Check if this operator name is already claimed/active in ANY OTHER locator
            $sqlCheckOp = "SELECT locator_name FROM `{$store}_locators` WHERE status = 'in_use' AND LOWER(TRIM(assigned_operator)) = ? AND LOWER(TRIM(locator_name)) != ? AND TIMESTAMPDIFF(SECOND, last_ping_at, NOW()) < 15";
            $checkOpRows = $db->query($sqlCheckOp, [$currentOp, strtolower($name)]);
            if (!empty($checkOpRows)) {
                $otherLoc = $checkOpRows[0]['locator_name'];
                throw new Exception("Operator name '$operator' is already active in another locator: '$otherLoc'.");
            }

            $db->execute("UPDATE `{$store}_locators` SET status = 'in_use', assigned_operator = ?, active_device_token = ?, last_ping_at = NOW(), synced = 0 WHERE locator_name = ?", [$operator, $deviceToken, $name]);

            // Query current scanned count (total scans count) for this locator
            $countRows = $db->query("SELECT COUNT(*) as count FROM `{$store}_countsheet` WHERE SlotNo = ?", [$name]);
            $scanned_count = !empty($countRows) ? (int) $countRows[0]['count'] : 0;

            sendResponse([
                'status' => 'success',
                'message' => "Locator '$name' claimed successfully!",
                'scanned_count' => $scanned_count
            ]);
            break;

        case 'heartbeat_locator':
            $data = json_decode(file_get_contents('php://input'), true);
            $locatorName = trim($data['locator_name'] ?? '');
            $deviceToken = trim($data['device_token'] ?? '');
            $storeCode = $_SESSION['store_code'] ?? ($data['store_code'] ?? '');

            if (empty($storeCode) || empty($locatorName) || empty($deviceToken)) {
                sendResponse(['status' => 'success']);
                break;
            }

            $db = new OWI_DB();
            $store = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($storeCode));
            ensureLocatorSessionColumns($db, $store);

            $locRows = $db->query("SELECT active_device_token, status FROM `{$store}_locators` WHERE locator_name = ?", [$locatorName]);
            if (!empty($locRows)) {
                $activeToken = $locRows[0]['active_device_token'] ?? '';
                if (!empty($activeToken) && $activeToken !== $deviceToken) {
                    sendResponse([
                        'status' => 'session_conflict',
                        'message' => 'Your scanning session was opened or taken over in another browser window.'
                    ]);
                    break;
                }
                $db->execute("UPDATE `{$store}_locators` SET last_ping_at = NOW() WHERE locator_name = ?", [$locatorName]);
            }
            sendResponse(['status' => 'success']);
            break;

        case 'release_session':
            $data = json_decode(file_get_contents('php://input'), true);
            if (!$data)
                $data = $_POST;
            $locatorName = trim($data['locator_name'] ?? '');
            $deviceToken = trim($data['device_token'] ?? '');
            $storeCode = $_SESSION['store_code'] ?? ($data['store_code'] ?? '');

            if (!empty($storeCode) && !empty($locatorName)) {
                $db = new OWI_DB();
                $store = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($storeCode));
                ensureLocatorSessionColumns($db, $store);
                $db->execute("UPDATE `{$store}_locators` SET active_device_token = NULL, last_ping_at = NULL WHERE locator_name = ? AND (active_device_token = ? OR active_device_token IS NULL)", [$locatorName, $deviceToken]);
            }
            sendResponse(['status' => 'success', 'message' => 'Session released successfully.']);
            break;

        case 'heartbeat_store_host':
            $data = json_decode(file_get_contents('php://input'), true);
            if (!$data)
                $data = $_POST;
            $storeCode = $_SESSION['store_code'] ?? ($data['store_code'] ?? '');
            $deviceToken = trim($data['device_token'] ?? '');
            $pageType = strtolower(trim($data['page_type'] ?? 'index'));
            if (!in_array($pageType, ['index', 'scan'])) {
                $pageType = 'index';
            }

            if (empty($storeCode) || empty($deviceToken)) {
                sendResponse(['status' => 'success']);
                break;
            }

            $db = new OWI_DB();
            ensureStoresHostLockColumns($db);

            $cleanStore = strtoupper(trim($storeCode));
            $currentUserId = (int) ($_SESSION['user_id'] ?? 0);

            $tokenCol = ($pageType === 'scan') ? 'scan_host_token' : 'index_host_token';
            $pingCol = ($pageType === 'scan') ? 'scan_last_ping' : 'index_last_ping';

            $storeRows = $db->query("SELECT {$tokenCol} as active_token, TIMESTAMPDIFF(SECOND, {$pingCol}, NOW()) as ping_diff FROM stores WHERE UPPER(store_code) = ?", [$cleanStore]);

            if (!empty($storeRows)) {
                $activeToken = $storeRows[0]['active_token'] ?? '';
                $pingDiff = isset($storeRows[0]['ping_diff']) ? (int) $storeRows[0]['ping_diff'] : 999;
                $isLockActive = !empty($activeToken) && ($pingDiff < 10);

                if ($isLockActive && $activeToken !== $deviceToken) {
                    $pageLabel = ($pageType === 'scan') ? 'Host Store Monitor (scan.php)' : 'Control Dashboard (index.php)';
                    sendResponse([
                        'status' => 'store_locked',
                        'message' => "{$pageLabel} for store session '" . $cleanStore . "' is already active in another browser tab/window. Duplicate tabs for the same page are disabled."
                    ]);
                    break;
                }

                // Refresh/Acquire host lock for this page type
                $db->execute("UPDATE stores SET {$tokenCol} = ?, {$pingCol} = NOW(), host_user_id = ? WHERE UPPER(store_code) = ?", [$deviceToken, $currentUserId, $cleanStore]);
            }

            sendResponse(['status' => 'success']);
            break;

        case 'release_store_host':
            $data = json_decode(file_get_contents('php://input'), true);
            if (!$data)
                $data = $_POST;
            $storeCode = $_SESSION['store_code'] ?? ($data['store_code'] ?? '');
            $deviceToken = trim($data['device_token'] ?? '');
            $pageType = strtolower(trim($data['page_type'] ?? 'index'));

            if (!empty($storeCode)) {
                $db = new OWI_DB();
                ensureStoresHostLockColumns($db);
                $cleanStore = strtoupper(trim($storeCode));
                $tokenCol = ($pageType === 'scan') ? 'scan_host_token' : 'index_host_token';
                $pingCol = ($pageType === 'scan') ? 'scan_last_ping' : 'index_last_ping';
                $db->execute("UPDATE stores SET {$tokenCol} = NULL, {$pingCol} = NULL WHERE UPPER(store_code) = ? AND ({$tokenCol} = ? OR {$tokenCol} IS NULL)", [$cleanStore, $deviceToken]);
            }
            sendResponse(['status' => 'success']);
            break;

        case 'close_locator':
            $data = json_decode(file_get_contents('php://input'), true);
            $name = trim($data['locator_name'] ?? '');
            if ($name === '') {
                throw new Exception("Locator name is required.");
            }
            $db = new OWI_DB();
            $storeCode = $_SESSION['store_code'] ?? '';

            // Validate store code existence
            $storeCheck = $db->query("SELECT COUNT(*) as count FROM stores WHERE LOWER(store_code) = ?", [strtolower($storeCode)]);
            if (empty($storeCheck) || (int) $storeCheck[0]['count'] === 0) {
                throw new Exception("Store code '" . $storeCode . "' does not exist.");
            }

            $store = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($storeCode));

            // Validate that store countsheets are initialized
            $tableCheck = $db->query("SHOW TABLES LIKE '{$store}_locators'");
            if (empty($tableCheck)) {
                throw new Exception("Store code '" . $storeCode . "' has not been initialized on the server yet.");
            }

            $db->execute("UPDATE `{$store}_locators` SET status = 'closed', synced = 0 WHERE locator_name = ?", [$name]);
            sendResponse(['status' => 'success', 'message' => "Locator '$name' closed successfully!"]);
            break;

        case 'approve_locator':
            $data = json_decode(file_get_contents('php://input'), true);
            $id = (int) ($data['id'] ?? 0);
            if ($id <= 0) {
                throw new Exception("Invalid locator ID.");
            }
            $db = new OWI_DB();
            $store = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($_SESSION['store_code']));
            $db->execute("UPDATE `{$store}_locators` SET status = 'open', synced = 0 WHERE id = ?", [$id]);
            sendResponse(['status' => 'success', 'message' => "Locator approved and reopened successfully!"]);
            break;

        case 'get_audit_logs':
            $db = new OWI_DB();
            $sql = "SELECT id, store_code, username, action, details, DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') as timestamp FROM audit_logs ORDER BY id DESC LIMIT 500";
            $logs = $db->query($sql);
            sendResponse([
                'status' => 'success',
                'logs' => $logs
            ]);
            break;

        case 'get_sync_config':
            $config = loadConfig();
            sendResponse([
                'status' => 'success',
                'cloud_sync_url' => $config['cloud_sync_url'] ?? 'https://pginv.officewarehouse.com.ph/OWIPI/',
                'sync_secret_token' => $config['sync_secret_token'] ?? ''
            ]);
            break;

        case 'save_sync_config':
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                throw new Exception("Invalid JSON inputs.");
            }
            $config = loadConfig();
            $config['cloud_sync_url'] = isset($input['cloud_sync_url']) ? trim($input['cloud_sync_url']) : '';
            $config['sync_secret_token'] = isset($input['sync_secret_token']) ? trim($input['sync_secret_token']) : '';

            if (saveConfig($config)) {
                sendResponse([
                    'status' => 'success',
                    'message' => 'Cloud synchronization configuration saved successfully!'
                ]);
            } else {
                throw new Exception("Failed to write config file.");
            }
            break;

        case 'trigger_cloud_sync':
            $db = new OWI_DB();
            $store = strtolower($_SESSION['store_code']);

            $config = loadConfig();
            $cloudUrl = trim($config['cloud_sync_url'] ?? '');
            $secretToken = trim($config['sync_secret_token'] ?? '');

            if (empty($cloudUrl)) {
                throw new Exception("Cloud Synchronization URL is not configured in settings.");
            }

            $storeRows = $db->query("SELECT * FROM stores WHERE LOWER(store_code) = ?", [$store]);
            if (empty($storeRows)) {
                throw new Exception("Store code does not exist in master stores table.");
            }
            $storeDetails = $storeRows[0];
            // Check if store is 100% completed (all locators are closed)
            $locatorsTable = "{$store}_locators";
            $checkTbl = $db->query("SHOW TABLES LIKE ?", [$locatorsTable]);
            if (empty($checkTbl)) {
                throw new Exception("Synchronization failed: Locators table for '{$_SESSION['store_code']}' does not exist.");
            }

            $totalRows = $db->query("SELECT COUNT(*) as count FROM `{$locatorsTable}`");
            $totalLocators = (int) ($totalRows[0]['count'] ?? 0);
            if ($totalLocators === 0) {
                throw new Exception("Synchronization failed: Store '{$_SESSION['store_code']}' has no locators configured.");
            }

            $closedRows = $db->query("SELECT COUNT(*) as count FROM `{$locatorsTable}` WHERE status = 'closed'");
            $closedLocators = (int) ($closedRows[0]['count'] ?? 0);

            if ($closedLocators < $totalLocators) {
                $percent = round(($closedLocators / $totalLocators) * 100);
                throw new Exception("Synchronization failed: Store '{$_SESSION['store_code']}' is only at {$percent}% completion ({$closedLocators} of {$totalLocators} locators closed). All locators must be closed before syncing to the cloud.");
            }

            // Validation: Check countsheet table exists and has scan records
            $countsheetTbl = "{$store}_countsheet";
            $checkCsTbl = $db->query("SHOW TABLES LIKE ?", [$countsheetTbl]);
            if (empty($checkCsTbl)) {
                throw new Exception("Synchronization failed: Countsheet table for '{$_SESSION['store_code']}' does not exist.");
            }

            $totalScansCheck = $db->query("SELECT COUNT(*) as count FROM `{$countsheetTbl}`");
            $totalScans = (int) ($totalScansCheck[0]['count'] ?? 0);
            if ($totalScans === 0) {
                throw new Exception("Synchronization failed: Store '{$_SESSION['store_code']}' has 0 scan records. Cannot sync an empty store session.");
            }

            // Validation: Pre-check cloud store status to prevent data overwrite conflict
            $checkCloudUrl = rtrim($cloudUrl, '/');
            if (preg_match('/\/api\.php$/i', $checkCloudUrl)) {
                $checkCloudUrl = preg_replace('/\/api\.php$/i', '', $checkCloudUrl);
            }
            $checkCloudUrl = rtrim($checkCloudUrl, '/') . '/api.php?action=get_cloud_store_details&store_code=' . urlencode($_SESSION['store_code']) . '&secret_token=' . urlencode($secretToken);

            $chCheck = curl_init();
            curl_setopt($chCheck, CURLOPT_URL, $checkCloudUrl);
            curl_setopt($chCheck, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($chCheck, CURLOPT_TIMEOUT, 10);
            curl_setopt($chCheck, CURLOPT_SSL_VERIFYPEER, false);
            $checkResult = curl_exec($chCheck);
            $checkHttp = curl_getinfo($chCheck, CURLINFO_HTTP_CODE);
            curl_close($chCheck);

            $currentRole = $_SESSION['role'] ?? 'user';
            $isAdminOrSystem = in_array(strtolower($currentRole), ['admin', 'system_admin', 'sys_admin']);

            $isStoreOnCloud = false;
            $cloudScansCount = 0;
            if ($checkHttp === 200 && $checkResult) {
                $cloudInfo = json_decode($checkResult, true);
                if ($cloudInfo && ($cloudInfo['status'] ?? '') === 'success' && !empty($cloudInfo['exists']) && !empty($cloudInfo['store']) && !empty($cloudInfo['store']['id']) && (int) $cloudInfo['store']['id'] > 0) {
                    $isStoreOnCloud = true;
                    $cloudScansCount = count($cloudInfo['scans'] ?? []);
                }
            }

            // Fetch all locators for this store
            $locators = $db->query("SELECT * FROM `{$store}_locators`");

            // Fetch all scan records for this store
            $scans = $db->query("
                SELECT RecNo as id, UPC as barcode, Qty as original_qty, 
                       IF(Edited = 1, EditedQty, Qty) as quantity, 
                       SlotNo as location, ScannedBy as scanned_by, 
                       DATE_FORMAT(CountDate, '%Y-%m-%d %H:%i:%s') as scanned_at,
                       Descr as product_name, SKU as sku,
                       Added as added, Edited as edited, EditedQty as edited_qty,
                       Posted as posted, Variance as variance
                FROM `{$store}_countsheet`
            ");

            // Fetch store catalog items table for this store
            $storeItems = [];
            try {
                $itemCheck = $db->query("SHOW TABLES LIKE '{$store}_items'");
                if (!empty($itemCheck)) {
                    $storeItems = $db->query("SELECT UPC, SKU, Descr, Type, Attr, Size, Price, Aux1, Qty FROM `{$store}_items`");
                }
            } catch (Exception $exItem) {
                $storeItems = [];
            }

            // Fetch users table
            $usersList = $db->query("SELECT username, password, role FROM users");

            // Fetch local audit logs (limit 50 to keep payload lightweight)
            $auditLogs = $db->query("SELECT store_code, username, action, details, DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') as created_at FROM audit_logs WHERE store_code = ? OR store_code IS NULL ORDER BY id DESC LIMIT 50", [$_SESSION['store_code']]);

            if (empty($locators) && empty($scans) && empty($storeItems) && empty($usersList) && empty($auditLogs)) {
                sendResponse([
                    'status' => 'success',
                    'message' => 'Everything is already synchronized with the cloud.'
                ]);
                break;
            }

            // Prepare payload containing store session, locators, scans, catalog items, and users
            $payload = [
                'secret_token' => $secretToken,
                'store_code' => $_SESSION['store_code'],
                'synced_by' => $_SESSION['username'] ?? 'UNKNOWN',
                'store_details' => $storeDetails,
                'locators' => $locators,
                'scans' => $scans,
                'products' => $storeItems,
                'items' => $storeItems,
                'users' => $usersList,
                'audit_logs' => $auditLogs
            ];

            // If store already exists on cloud, submit for Admin approval on Cloud Dashboard before overwriting
            if ($isStoreOnCloud) {
                $submitUrl = rtrim($cloudUrl, '/');
                if (preg_match('/\/api\.php$/i', $submitUrl)) {
                    $submitUrl = preg_replace('/\/api\.php$/i', '', $submitUrl);
                }
                $submitUrl = rtrim($submitUrl, '/') . '/api.php?action=submit_sync_request';

                $payload['local_scans_count'] = $totalScans;
                $payload['cloud_scans_count'] = $cloudScansCount;
                unset($payload['products']);
                unset($payload['items']);

                $chReq = curl_init();
                curl_setopt($chReq, CURLOPT_URL, $submitUrl);
                curl_setopt($chReq, CURLOPT_POST, true);
                curl_setopt($chReq, CURLOPT_POSTFIELDS, json_encode($payload));
                curl_setopt($chReq, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($chReq, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($chReq, CURLOPT_TIMEOUT, 30);
                curl_setopt($chReq, CURLOPT_SSL_VERIFYPEER, false);

                $reqResult = curl_exec($chReq);
                $reqHttp = curl_getinfo($chReq, CURLINFO_HTTP_CODE);
                curl_close($chReq);

                $reqData = json_decode($reqResult, true);
                if ($reqHttp === 200 && ($reqData['status'] ?? '') === 'success') {
                    sendResponse([
                        'status' => 'pending_approval',
                        'message' => "Sync request for existing store '" . strtoupper($_SESSION['store_code']) . "' submitted to Cloud! Waiting for System Admin or Admin approval on the Cloud Dashboard before overwriting."
                    ]);
                    break;
                } else {
                    $errMsg = $reqData['message'] ?? 'Failed to submit sync request for approval.';
                    throw new Exception("Sync Request Submission Failed: " . $errMsg);
                }
            }

            // Clean Cloud Sync URL (strip api.php if user included it in settings)
            $targetUrl = rtrim($cloudUrl, '/');
            if (preg_match('/\/api\.php$/i', $targetUrl)) {
                $targetUrl = preg_replace('/\/api\.php$/i', '', $targetUrl);
            }
            $targetUrl = rtrim($targetUrl, '/') . '/api.php?action=receive_sync';

            // Send via cURL
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $targetUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

            $result = curl_exec($ch);
            $err = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($err) {
                throw new Exception("cURL Error: " . $err);
            }

            $resData = json_decode($result, true);
            if (!$resData || ($resData['status'] ?? 'error') !== 'success') {
                $msg = $resData['message'] ?? 'Unknown cloud server error.';
                throw new Exception("Cloud Sync Failed (HTTP {$httpCode}): " . $msg);
            }

            // Update local synced status (local store session remains open until user manually clicks Close Store)
            $db->execute("UPDATE stores SET synced = 1 WHERE id = ?", [$storeDetails['id']]);

            foreach ($locators as $loc) {
                $db->execute("UPDATE `{$store}_locators` SET synced = 1 WHERE id = ?", [$loc['id']]);
            }

            foreach ($scans as $scan) {
                $db->execute("UPDATE `{$store}_countsheet` SET synced = 1 WHERE RecNo = ?", [$scan['id']]);
            }

            sendResponse([
                'status' => 'success',
                'message' => "Successfully synchronized with the cloud! Synced " . count($locators) . " locators and " . count($scans) . " scan records."
            ]);
            break;

        case 'fetch_cloud_stores':
            $config = loadConfig();
            $cloudUrl = trim($config['cloud_sync_url'] ?? '');
            if (empty($cloudUrl)) {
                $cloudUrl = 'https://pginv.officewarehouse.com.ph/OWIPI/';
            }
            $secretToken = trim($config['sync_secret_token'] ?? '');

            $targetUrl = rtrim($cloudUrl, '/') . '/api.php?action=get_cloud_stores&secret_token=' . urlencode($secretToken);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $targetUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

            $result = curl_exec($ch);
            $err = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($err) {
                throw new Exception("cURL Error: " . $err);
            }

            $resData = json_decode($result, true);
            if ($httpCode !== 200 || !$resData || ($resData['status'] ?? 'error') !== 'success') {
                $msg = $resData['message'] ?? 'Connection to cloud failed.';
                throw new Exception("Cloud API Error (HTTP $httpCode): " . $msg);
            }

            sendResponse([
                'status' => 'success',
                'stores' => $resData['stores']
            ]);
            break;

        case 'import_cloud_store':
            $store = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($_GET['store_code'] ?? ''));
            if (empty($store)) {
                throw new Exception("Invalid store code.");
            }

            $config = loadConfig();
            $cloudUrl = trim($config['cloud_sync_url'] ?? '');
            if (empty($cloudUrl)) {
                $cloudUrl = 'https://pginv.officewarehouse.com.ph/OWIPI/';
            }
            $secretToken = trim($config['sync_secret_token'] ?? '');

            $targetUrl = rtrim($cloudUrl, '/') . '/api.php?action=get_cloud_store_details&store_code=' . urlencode($store) . '&secret_token=' . urlencode($secretToken);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $targetUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 180);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

            $result = curl_exec($ch);
            $err = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($err) {
                throw new Exception("cURL Error: " . $err);
            }

            $resData = json_decode($result, true);

            if ($httpCode !== 200 || !$resData || ($resData['status'] ?? 'error') !== 'success') {
                $msg = is_array($resData) && !empty($resData['message']) ? $resData['message'] : 'Failed to connect to Cloud Server.';
                throw new Exception("Cloud Download Failed: " . $msg);
            }

            $storeObj = $resData['store'] ?? null;
            $storeFound = isset($resData['store_found']) ? (bool) $resData['store_found'] : ($storeObj !== null);

            if (!$storeFound || empty($storeObj)) {
                throw new Exception("Download Blocked: Store session '" . strtoupper($store) . "' does not exist on the Cloud Dashboard. Please create or start the store session on Cloud first before downloading.");
            }

            $cloudStore = $storeObj;
            $locators = $resData['locators'] ?? [];
            $products = $resData['products'] ?? [];
            $scans = $resData['scans'] ?? [];

            $db = new OWI_DB();

            // Resolve Cloud Creator Username & Password Hash to Local User ID so store ownership & logins are preserved
            $creatorUsername = $cloudStore['creator_username'] ?? $cloudStore['creator'] ?? '';
            $creatorPassword = $cloudStore['creator_password'] ?? '';
            $creatorRole = $cloudStore['creator_role'] ?? 'user';
            $localCreatorId = null;

            if (!empty($creatorUsername)) {
                $userRows = $db->query("SELECT id FROM users WHERE LOWER(username) = ?", [strtolower($creatorUsername)]);
                if (!empty($userRows)) {
                    $localCreatorId = (int) $userRows[0]['id'];
                    if (!empty($creatorPassword)) {
                        $db->execute("UPDATE users SET password = ? WHERE id = ?", [$creatorPassword, $localCreatorId]);
                    }
                } else {
                    try {
                        $hashedPass = !empty($creatorPassword) ? $creatorPassword : password_hash('123456', PASSWORD_BCRYPT);
                        $db->execute("INSERT INTO users (username, password, role) VALUES (?, ?, ?)", [strtoupper($creatorUsername), $hashedPass, $creatorRole]);
                        $localCreatorId = (int) $db->lastInsertId();
                    } catch (Exception $exU) {
                    }
                }
            }

            if ($localCreatorId === null && isset($cloudStore['created_by']) && is_numeric($cloudStore['created_by'])) {
                $localCreatorId = (int) $cloudStore['created_by'];
            }

            // Create the local store and tables with creator ID
            $db->createStoreTables($store, $localCreatorId);

            if ($localCreatorId !== null) {
                $db->execute("UPDATE stores SET created_by = ? WHERE LOWER(store_code) = ?", [$localCreatorId, $store]);
            }

            // Sync the closed status
            if ($cloudStore && isset($cloudStore['closed'])) {
                $db->execute("UPDATE stores SET closed = ? WHERE LOWER(store_code) = ?", [(int) $cloudStore['closed'], $store]);
            }

            // Insert locators
            foreach ($locators as $loc) {
                $locName = $loc['locator_name'];
                $status = $loc['status'] ?? 'open';
                $operator = $loc['assigned_operator'] ?? null;

                $check = $db->query("SELECT id FROM `{$store}_locators` WHERE locator_name = ?", [$locName]);
                if (empty($check)) {
                    $db->execute(
                        "INSERT INTO `{$store}_locators` (locator_name, status, assigned_operator, synced) VALUES (?, ?, ?, 1)",
                        [$locName, $status, $operator]
                    );
                } else {
                    $db->execute(
                        "UPDATE `{$store}_locators` SET status = ?, assigned_operator = ?, synced = 1 WHERE locator_name = ?",
                        [$status, $operator, $locName]
                    );
                }
            }

            // Populate countsheet scans from cloud
            $scansImported = 0;
            if (!empty($scans)) {
                $db->execute("TRUNCATE TABLE `{$store}_countsheet`");
                foreach ($scans as $scan) {
                    $recNo = $scan['RecNo'] ?? $scan['id'] ?? null;
                    $loc = $scan['SlotNo'] ?? $scan['location'] ?? '';
                    $date = $scan['CountDate'] ?? $scan['scanned_at'] ?? date('Y-m-d H:i:s');
                    $upc = $scan['UPC'] ?? $scan['barcode'] ?? '';
                    $sku = $scan['SKU'] ?? $scan['sku'] ?? '';
                    $descr = $scan['Descr'] ?? $scan['product_name'] ?? '';
                    $qty = isset($scan['Qty']) ? (float) $scan['Qty'] : (isset($scan['original_qty']) ? (float) $scan['original_qty'] : 0.00);
                    $editedQty = isset($scan['EditedQty']) ? (float) $scan['EditedQty'] : (isset($scan['edited_qty']) ? (float) $scan['edited_qty'] : 0.00);
                    $posted = isset($scan['Posted']) ? (int) $scan['Posted'] : (isset($scan['posted']) ? (int) $scan['posted'] : 0);
                    $added = isset($scan['Added']) ? (int) $scan['Added'] : (isset($scan['added']) ? (int) $scan['added'] : 0);
                    $edited = isset($scan['Edited']) ? (int) $scan['Edited'] : (isset($scan['edited']) ? (int) $scan['edited'] : 0);
                    $scannedBy = $scan['ScannedBy'] ?? $scan['scanned_by'] ?? 'CLOUD';
                    $variance = isset($scan['Variance']) ? (float) $scan['Variance'] : (isset($scan['variance']) ? (float) $scan['variance'] : 0.00);

                    if ($recNo !== null) {
                        $db->execute(
                            "INSERT INTO `{$store}_countsheet` 
                             (RecNo, SlotNo, CountDate, UPC, SKU, Descr, Qty, EditedQty, Posted, Added, Edited, ScannedBy, Variance, synced) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)",
                            [$recNo, $loc, $date, $upc, $sku, $descr, $qty, $editedQty, $posted, $added, $edited, $scannedBy, $variance]
                        );
                    } else {
                        $db->execute(
                            "INSERT INTO `{$store}_countsheet` 
                             (SlotNo, CountDate, UPC, SKU, Descr, Qty, EditedQty, Posted, Added, Edited, ScannedBy, Variance, synced) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)",
                            [$loc, $date, $upc, $sku, $descr, $qty, $editedQty, $posted, $added, $edited, $scannedBy, $variance]
                        );
                    }
                }
                $scansImported = count($scans);
            }

            // Populate store items table with specific store products from cloud
            $productsImported = 0;
            if (!empty($products)) {
                $db->execute("TRUNCATE TABLE `{$store}_items`");

                $chunkSize = 200;
                $chunks = array_chunk($products, $chunkSize);

                $colNames = ['UPC', 'SKU', 'Descr', 'Type', 'Attr', 'Size', 'Price', 'Aux1', 'Qty'];
                $colSql = implode(', ', array_map(function ($c) {
                    return "`{$c}`"; }, $colNames));
                $singleRowPlaceholder = "(" . implode(', ', array_fill(0, count($colNames), '?')) . ")";

                foreach ($chunks as $chunk) {
                    $placeholders = [];
                    $params = [];
                    foreach ($chunk as $row) {
                        $placeholders[] = $singleRowPlaceholder;
                        $params[] = $row['UPC'] ?? $row['LOCAL_UPC'] ?? $row['upc'] ?? '';
                        $params[] = $row['SKU'] ?? $row['ALU'] ?? $row['sku'] ?? '';
                        $params[] = $row['Descr'] ?? $row['DESCRIPTION1'] ?? $row['descr'] ?? '';
                        $params[] = $row['Type'] ?? $row['DESCRIPTION2'] ?? $row['type'] ?? 'GENERAL';
                        $params[] = $row['Attr'] ?? $row['attr'] ?? null;
                        $params[] = $row['Size'] ?? $row['SIZ'] ?? $row['size'] ?? null;
                        $params[] = isset($row['Price']) ? (float) $row['Price'] : (isset($row['price']) ? (float) $row['price'] : 0.00);
                        $params[] = $row['Aux1'] ?? $row['AUX1'] ?? $row['aux1'] ?? null;
                        $params[] = isset($row['Qty']) ? (float) $row['Qty'] : (isset($row['qty']) ? (float) $row['qty'] : 0.00);
                    }
                    $sqlChunk = "INSERT INTO `{$store}_items` ({$colSql}) VALUES " . implode(', ', $placeholders);
                    $db->execute($sqlChunk, $params);
                }
                $productsImported = count($products);
            }

            $details = [];
            if (count($locators) > 0) {
                $details[] = count($locators) . " locators";
            }
            if ($scansImported > 0) {
                $details[] = $scansImported . " scan records";
            }
            $details[] = $productsImported . " items";

            $msg = "Successfully imported store '" . strtoupper($store) . "' with " . implode(', ', $details) . " from Cloud Masterfile!";

            sendResponse([
                'status' => 'success',
                'message' => $msg
            ]);
            break;

        case 'import_cloud_products':
            $store = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($_GET['store_code'] ?? ''));
            $config = loadConfig();
            $cloudUrl = trim($config['cloud_sync_url'] ?? '');
            if (empty($cloudUrl)) {
                $cloudUrl = 'https://pginv.officewarehouse.com.ph/OWIPI/';
            }
            $secretToken = trim($config['sync_secret_token'] ?? '');

            $db = new OWI_DB();

            if (!empty($store)) {
                // Fetch store-specific details/products from cloud
                $targetUrl = rtrim($cloudUrl, '/') . '/api.php?action=get_cloud_store_details&store_code=' . urlencode($store) . '&secret_token=' . urlencode($secretToken);

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $targetUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 180);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                $result = curl_exec($ch);
                $err = curl_error($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($err) {
                    throw new Exception("cURL Error: " . $err);
                }

                $resData = json_decode($result, true);
                if ($httpCode !== 200 || !$resData || ($resData['status'] ?? 'error') !== 'success') {
                    $msg = $resData['message'] ?? 'Connection to cloud failed.';
                    throw new Exception("Cloud API Error (HTTP $httpCode): " . $msg);
                }

                $products = $resData['products'] ?? [];
                if (empty($products)) {
                    throw new Exception("No products found for store " . strtoupper($store) . " in cloud catalog.");
                }

                // Ensure store tables exist
                $db->createStoreTables($store);
                $targetTbl = "`{$store}_items`";
            } else {
                // Fetch all products from cloud master catalog
                $targetUrl = rtrim($cloudUrl, '/') . '/api.php?action=get_cloud_products&secret_token=' . urlencode($secretToken);

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $targetUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 180);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                $result = curl_exec($ch);
                $err = curl_error($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($err) {
                    throw new Exception("cURL Error: " . $err);
                }

                $resData = json_decode($result, true);
                if ($httpCode !== 200 || !$resData || ($resData['status'] ?? 'error') !== 'success') {
                    $msg = $resData['message'] ?? 'Connection to cloud failed.';
                    throw new Exception("Cloud API Error (HTTP $httpCode): " . $msg);
                }

                $products = $resData['products'] ?? [];
                if (empty($products)) {
                    throw new Exception("No products found in cloud database catalog.");
                }

                $targetTbl = "items";
            }

            // Truncate target table
            $db->execute("TRUNCATE TABLE {$targetTbl}");

            // Insert in chunks of 500
            $chunkSize = 500;
            $chunks = array_chunk($products, $chunkSize);

            foreach ($chunks as $chunk) {
                $sqlInsert = "INSERT INTO {$targetTbl} (UPC, SKU, Descr, Type, Attr, Size, Price, Aux1, Qty) VALUES ";
                $placeholders = [];
                $params = [];

                foreach ($chunk as $p) {
                    $placeholders[] = "(?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $params[] = $p['UPC'] ?? $p['LOCAL_UPC'] ?? $p['upc'] ?? '';
                    $params[] = $p['SKU'] ?? $p['ALU'] ?? $p['sku'] ?? '';
                    $params[] = $p['Descr'] ?? $p['DESCRIPTION1'] ?? $p['descr'] ?? '';
                    $params[] = $p['Type'] ?? $p['DESCRIPTION2'] ?? $p['type'] ?? 'GENERAL';
                    $params[] = $p['Attr'] ?? $p['attr'] ?? null;
                    $params[] = $p['Size'] ?? $p['SIZ'] ?? $p['size'] ?? null;
                    $params[] = isset($p['Price']) ? (float) $p['Price'] : (isset($p['price']) ? (float) $p['price'] : 0.00);
                    $params[] = $p['Aux1'] ?? $p['aux1'] ?? null;
                    $params[] = isset($p['Qty']) ? (float) $p['Qty'] : (isset($p['qty']) ? (float) $p['qty'] : 0.00);
                }

                $sqlInsert .= implode(', ', $placeholders);
                $db->execute($sqlInsert, $params);
            }

            $msg = !empty($store)
                ? "Successfully imported " . count($products) . " products for store " . strtoupper($store) . " from cloud!"
                : "Successfully imported " . count($products) . " products from cloud masterfile!";

            sendResponse([
                'status' => 'success',
                'message' => $msg
            ]);
            break;

        case 'get_cloud_stores':
            verifySyncToken();
            $db = new OWI_DB();
            $stores = $db->query("SELECT id, store_code, closed FROM stores ORDER BY store_code ASC");
            sendResponse([
                'status' => 'success',
                'stores' => $stores
            ]);
            break;

        case 'receive_sync':
            verifySyncToken();
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                throw new Exception("Invalid sync payload.");
            }
            $storeCode = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($input['store_code'] ?? ''));
            if (empty($storeCode)) {
                throw new Exception("Invalid store code.");
            }
            $db = new OWI_DB();
            $createdBy = $input['store_details']['created_by'] ?? null;
            $db->createStoreTables($storeCode, $createdBy);

            // Automatically create backup on Cloud before overwriting data
            createCloudStoreBackup($db, $storeCode);

            // Automatically mark store session as CLOSED (closed = 1) on Cloud server upon sync
            $db->execute("UPDATE stores SET closed = 1 WHERE LOWER(store_code) = ?", [$storeCode]);

            if (!empty($input['locators']) && is_array($input['locators'])) {
                $db->execute("TRUNCATE TABLE `{$storeCode}_locators`");
                foreach ($input['locators'] as $loc) {
                    $locName = $loc['locator_name'];
                    $status = $loc['status'] ?? 'open';
                    $operator = $loc['assigned_operator'] ?? null;
                    $db->execute("INSERT INTO `{$storeCode}_locators` (locator_name, status, assigned_operator, synced) VALUES (?, ?, ?, 1)", [$locName, $status, $operator]);
                }
            }

            foreach ($input['scans'] ?? [] as $scan) {
                $recNo = isset($scan['id']) ? (int) $scan['id'] : 0;
                $slotNo = $scan['location'];
                $upc = $scan['barcode'];
                $sku = $scan['sku'] ?? '';
                $descr = $scan['product_name'] ?? '';
                $qty = (float) $scan['original_qty'];
                $editedQty = isset($scan['edited_qty']) ? (float) $scan['edited_qty'] : null;
                $posted = (int) ($scan['posted'] ?? 0);
                $added = (int) ($scan['added'] ?? 0);
                $edited = (int) ($scan['edited'] ?? 0);
                $scannedBy = !empty($scan['scanned_by']) ? $scan['scanned_by'] : 'Handheld';
                $countDate = $scan['scanned_at'] ?? date('Y-m-d H:i:s');
                $variance = (float) ($scan['variance'] ?? 0.00);

                $checkScan = [];
                if ($recNo > 0) {
                    $checkScan = $db->query("SELECT RecNo FROM `{$storeCode}_countsheet` WHERE RecNo = ?", [$recNo]);
                }
                if (empty($checkScan)) {
                    $checkScan = $db->query("SELECT RecNo FROM `{$storeCode}_countsheet` WHERE SlotNo = ? AND UPC = ? AND CountDate = ?", [$slotNo, $upc, $countDate]);
                }

                if (!empty($checkScan)) {
                    $targetRecNo = $checkScan[0]['RecNo'];
                    $db->execute(
                        "UPDATE `{$storeCode}_countsheet` 
                         SET SlotNo = ?, CountDate = ?, UPC = ?, SKU = ?, Descr = ?, Qty = ?, EditedQty = ?, Posted = ?, Added = ?, Edited = ?, ScannedBy = ?, Variance = ?, synced = 1
                         WHERE RecNo = ?",
                        [$slotNo, $countDate, $upc, $sku, $descr, $qty, $editedQty, $posted, $added, $edited, $scannedBy, $variance, $targetRecNo]
                    );
                } else {
                    $db->execute(
                        "INSERT INTO `{$storeCode}_countsheet` (SlotNo, CountDate, UPC, SKU, Descr, Qty, EditedQty, Posted, Added, Edited, ScannedBy, Variance, synced) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)",
                        [$slotNo, $countDate, $upc, $sku, $descr, $qty, $editedQty, $posted, $added, $edited, $scannedBy, $variance]
                    );
                }
            }

            // Verify store completion status on Cloud after sync
            $totLoc = (int) ($db->query("SELECT COUNT(*) as count FROM `{$storeCode}_locators`")[0]['count'] ?? 0);
            $clsLoc = (int) ($db->query("SELECT COUNT(*) as count FROM `{$storeCode}_locators` WHERE status = 'closed'")[0]['count'] ?? 0);
            if ($totLoc > 0 && $clsLoc === $totLoc) {
                $db->execute("UPDATE stores SET closed = 1 WHERE LOWER(store_code) = ?", [$storeCode]);
            }

            sendResponse([
                'status' => 'success',
                'message' => "Store '" . strtoupper($storeCode) . "' synchronized successfully to Cloud!"
            ]);
            break;

        case 'submit_sync_request':
            verifySyncToken();
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                throw new Exception("Invalid sync request payload.");
            }
            $storeCode = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($input['store_code'] ?? ''));
            $requestedBy = $input['synced_by'] ?? 'UNKNOWN';
            $localScans = (int) ($input['local_scans_count'] ?? 0);
            $cloudScans = (int) ($input['cloud_scans_count'] ?? 0);

            $db = new OWI_DB();
            // Remove bulky products/items catalog arrays from payload to prevent max_allowed_packet error
            unset($input['products']);
            unset($input['items']);
            $jsonPayload = json_encode($input);

            try {
                $db->execute("SET SESSION max_allowed_packet = 67108864");
            } catch (Exception $eP) {
            }

            $db->execute(
                "INSERT INTO pending_sync_requests (store_code, requested_by, payload, local_scans_count, cloud_scans_count, status) VALUES (?, ?, ?, ?, ?, 'pending')",
                [$storeCode, $requestedBy, $jsonPayload, $localScans, $cloudScans]
            );

            sendResponse([
                'status' => 'success',
                'message' => "Sync request for store '" . strtoupper($storeCode) . "' submitted successfully. Awaiting System Admin approval."
            ]);
            break;

        case 'get_pending_syncs':
            $db = new OWI_DB();
            $requests = [];
            try {
                $db->execute("CREATE TABLE IF NOT EXISTS pending_sync_requests (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    store_code VARCHAR(50) NOT NULL,
                    requested_by VARCHAR(100) NOT NULL,
                    payload LONGTEXT NOT NULL,
                    local_scans_count INT DEFAULT 0,
                    cloud_scans_count INT DEFAULT 0,
                    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
                    approved_by VARCHAR(100) NULL,
                    approved_at DATETIME NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");
                $requests = $db->query("SELECT id, store_code, requested_by, local_scans_count, cloud_scans_count, status, DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') as created_at FROM pending_sync_requests WHERE status = 'pending' ORDER BY id DESC");
            } catch (Exception $e) {
            }
            sendResponse([
                'status' => 'success',
                'requests' => $requests
            ]);
            break;

        case 'version':
            sendResponse([
                'status' => 'success',
                'version' => '2.5.0-sql-script-backups',
                'commit' => '71bd80e',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;

        case 'clear_cloud_backups':
            $db = new OWI_DB();
            ensureCloudBackupsLogTable($db);
            try {
                $db->execute("TRUNCATE TABLE cloud_backups_log");
            } catch (Exception $eC1) {
                try {
                    $db->execute("DELETE FROM cloud_backups_log");
                } catch (Exception $eC2) {
                }
            }

            // Delete physical backup files
            try {
                $backupDir = __DIR__ . '/backups';
                if (is_dir($backupDir)) {
                    $files = glob($backupDir . "/*.*");
                    foreach ($files as $file) {
                        if (is_file($file)) {
                            @unlink($file);
                        }
                    }
                }
            } catch (Exception $eF) {
            }

            logAudit('Clear Cloud Backups', "System Admin cleared all cloud backup logs and deleted stored backup script files.");

            sendResponse([
                'status' => 'success',
                'message' => 'Successfully cleared all cloud pre-sync backup logs and deleted stored backup script files!'
            ]);
            break;

        case 'get_cloud_backups':
            $db = new OWI_DB();
            $backups = [];

            $dbError = null;
            // 1. Ensure log table exists and has all required columns
            ensureCloudBackupsLogTable($db);
            try {
                $db->execute("DELETE FROM cloud_backups_log WHERE store_code LIKE '%MANUAL%' OR store_code LIKE '%SNAPSHOT%'");
            } catch (Exception $eClean) {
            }

            try {
                $rows = $db->query("SELECT * FROM cloud_backups_log ORDER BY id DESC");
                if (!empty($rows)) {
                    foreach ($rows as $r) {
                        $row = array_change_key_case((array) $r, CASE_LOWER);

                        $bId = $row['backup_id'] ?? '';
                        $sCode = strtoupper($row['store_code'] ?? '');
                        $bType = $row['backup_type'] ?? 'sql_script';
                        $sCount = (int) ($row['scans_count'] ?? 0);
                        $lCount = (int) ($row['locators_count'] ?? 0);
                        $cAt = $row['created_at'] ?? '';

                        if (!empty($bId)) {
                            $backups[] = [
                                'id' => $bId,
                                'type' => !empty($bType) ? $bType : 'sql_script',
                                'store_code' => $sCode,
                                'scans_count' => $sCount,
                                'locators_count' => $lCount,
                                'created_at' => $cAt
                            ];
                        }
                    }
                }
            } catch (Exception $eL) {
                $dbError = $eL->getMessage();
                error_log("Failed to query cloud_backups_log: " . $eL->getMessage());
            }

            // 2. Scan backups/ directory for .SQL Script files
            try {
                $backupDir = __DIR__ . '/backups';
                if (is_dir($backupDir)) {
                    $sqlFiles = glob($backupDir . "/backup_*.sql");
                    foreach ($sqlFiles as $file) {
                        $basename = basename($file);
                        if (preg_match('/^backup_(.+?)_(\d{8}_\d{6})\.sql$/', $basename, $matches)) {
                            $storeCode = strtoupper($matches[1]);
                            $tsStr = $matches[2];

                            $alreadyInList = false;
                            foreach ($backups as $b) {
                                if ($b['id'] === $basename || $b['id'] . '.sql' === $basename || (strpos($b['id'], $tsStr) !== false && $b['store_code'] === $storeCode)) {
                                    $alreadyInList = true;
                                    break;
                                }
                            }
                            if ($alreadyInList)
                                continue;

                            $dateObj = DateTime::createFromFormat('Ymd_His', $tsStr);
                            $formattedDate = $dateObj ? $dateObj->format('Y-m-d H:i:s') : $tsStr;

                            $content = file_get_contents($file);
                            $scansCount = (int) preg_match_all('/INSERT INTO `[^`]+_countsheet`/', $content, $m1);
                            $locsCount = (int) preg_match_all('/INSERT INTO `[^`]+_locators`/', $content, $m2);

                            $backups[] = [
                                'id' => $basename,
                                'type' => 'sql_script',
                                'store_code' => $storeCode,
                                'created_at' => $formattedDate,
                                'scans_count' => $scansCount,
                                'locators_count' => $locsCount
                            ];
                        }
                    }
                }
            } catch (Exception $eSqlF) {
            }

            // 3. Scan backups/ directory for JSON files
            try {
                $backupDir = __DIR__ . '/backups';
                if (is_dir($backupDir)) {
                    $files = glob($backupDir . "/cloud_backup_*.json");
                    foreach ($files as $file) {
                        $basename = basename($file);
                        if (preg_match('/^cloud_backup_([a-zA-Z0-9]+)_(\d{8}_\d{6})\.json$/', $basename, $matches)) {
                            $storeCode = strtoupper($matches[1]);
                            $tsStr = $matches[2];

                            $alreadyInList = false;
                            foreach ($backups as $b) {
                                if (strpos($b['id'], $tsStr) !== false && $b['store_code'] === $storeCode) {
                                    $alreadyInList = true;
                                    break;
                                }
                            }
                            if ($alreadyInList)
                                continue;

                            $dateObj = DateTime::createFromFormat('Ymd_His', $tsStr);
                            $formattedDate = $dateObj ? $dateObj->format('Y-m-d H:i:s') : $tsStr;

                            $data = json_decode(file_get_contents($file), true);
                            $scansCount = count($data['scans'] ?? []);
                            $locsCount = count($data['locators'] ?? []);

                            $backups[] = [
                                'id' => $basename,
                                'type' => 'json_file',
                                'store_code' => $storeCode,
                                'created_at' => $formattedDate,
                                'scans_count' => $scansCount,
                                'locators_count' => $locsCount
                            ];
                        }
                    }
                }
            } catch (Exception $eF) {
            }

            // Safely sort backups by created_at descending
            usort($backups, function ($a, $b) {
                return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
            });

            sendResponse([
                'status' => 'success',
                'backups' => $backups,
                'db_error' => $dbError
            ]);
            break;

        case 'download_cloud_backup':
            $file = trim($_GET['file'] ?? ($_POST['file'] ?? ''));
            $filename = basename($file);
            $filePath = __DIR__ . '/backups/' . $filename;
            if (!file_exists($filePath) && file_exists($filePath . '.sql')) {
                $filename .= '.sql';
                $filePath .= '.sql';
            }
            if (empty($filename) || !file_exists($filePath)) {
                http_response_code(404);
                die("Backup script file not found.");
            }

            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($filePath));
            readfile($filePath);
            exit;

        case 'create_manual_backup':
        case 'create_backup':
        case 'manual_backup':
        case 'backup':
        case 'trigger_backup':
            $storeCode = trim($_POST['store_code'] ?? ($_GET['store_code'] ?? ($rawInput['store_code'] ?? '')));
            if (empty($storeCode)) {
                $storeCode = $_SESSION['store_code'] ?? '';
            }
            if (empty($storeCode)) {
                throw new Exception("Please specify a Store Code to back up.");
            }

            $db = new OWI_DB();
            createCloudStoreBackup($db, $storeCode);

            sendResponse([
                'status' => 'success',
                'message' => "Successfully created automatic backup snapshot for store '" . strtoupper($storeCode) . "'!"
            ]);
            break;

        case 'restore_cloud_backup':
            $backupId = trim($_POST['backup_id'] ?? ($_GET['backup_id'] ?? ''));
            if (empty($backupId)) {
                throw new Exception("Invalid backup ID specified.");
            }

            $db = new OWI_DB();
            $storeCode = '';
            $restoredScans = 0;
            $restoredLocs = 0;

            if (strpos($backupId, '_backup_') === 0 && preg_match('/^_backup_(.+?)_countsheet_(\d{8}_\d{6})$/', $backupId, $matches)) {
                $storeCode = strtolower($matches[1]);
                $tsStr = $matches[2];
                $locTable = "_backup_{$storeCode}_locators_{$tsStr}";

                // 1. Restore locators table
                $checkLocBackup = $db->query("SHOW TABLES LIKE '{$locTable}'");
                if (!empty($checkLocBackup)) {
                    $db->execute("DROP TABLE IF EXISTS `{$storeCode}_locators`");
                    $db->execute("CREATE TABLE `{$storeCode}_locators` AS SELECT * FROM `{$locTable}`");
                }

                // 2. Restore countsheet table
                $db->execute("DROP TABLE IF EXISTS `{$storeCode}_countsheet`");
                $db->execute("CREATE TABLE `{$storeCode}_countsheet` AS SELECT * FROM `{$backupId}`");

                $restoredScans = (int) ($db->query("SELECT COUNT(*) as c FROM `{$storeCode}_countsheet`")[0]['c'] ?? 0);
                $restoredLocs = (int) ($db->query("SELECT COUNT(*) as c FROM `{$storeCode}_locators`")[0]['c'] ?? 0);
            } else if (strpos($backupId, 'backup_') === 0 && strpos($backupId, '.sql') !== false) {
                $filePath = __DIR__ . '/backups/' . basename($backupId);
                if (!file_exists($filePath)) {
                    throw new Exception("Backup SQL script file not found.");
                }
                $sqlContent = file_get_contents($filePath);
                $queries = array_filter(array_map('trim', explode(";\n", $sqlContent)));
                foreach ($queries as $q) {
                    if (!empty($q) && strpos($q, '--') !== 0) {
                        try {
                            $db->execute($q);
                        } catch (Exception $eQ) {
                        }
                    }
                }
                if (preg_match('/^backup_(.+?)_(\d{8}_\d{6})\.sql$/', basename($backupId), $m)) {
                    $storeCode = strtolower($m[1]);
                }
                try {
                    $restoredScans = (int) ($db->query("SELECT COUNT(*) as c FROM `{$storeCode}_countsheet`")[0]['c'] ?? 0);
                } catch (Exception $eC) {
                }
                try {
                    $restoredLocs = (int) ($db->query("SELECT COUNT(*) as c FROM `{$storeCode}_locators`")[0]['c'] ?? 0);
                } catch (Exception $eL) {
                }
            } else if (strpos($backupId, 'cloud_backup_') === 0 && strpos($backupId, '.json') !== false) {
                $filePath = __DIR__ . '/backups/' . basename($backupId);
                if (!file_exists($filePath)) {
                    throw new Exception("Backup JSON file not found.");
                }
                $content = json_decode(file_get_contents($filePath), true);
                if (!$content) {
                    throw new Exception("Invalid backup JSON format.");
                }
                $storeCode = strtolower($content['store_code'] ?? '');
                if (empty($storeCode)) {
                    throw new Exception("Store code missing from backup file.");
                }

                $db->createStoreTables($storeCode);

                if (!empty($content['locators']) && is_array($content['locators'])) {
                    $db->execute("TRUNCATE TABLE `{$storeCode}_locators`");
                    foreach ($content['locators'] as $loc) {
                        $locName = $loc['locator_name'];
                        $status = $loc['status'] ?? 'open';
                        $operator = $loc['assigned_operator'] ?? null;
                        $db->execute("INSERT INTO `{$storeCode}_locators` (locator_name, status, assigned_operator, synced) VALUES (?, ?, ?, 1)", [$locName, $status, $operator]);
                    }
                    $restoredLocs = count($content['locators']);
                }

                if (isset($content['scans']) && is_array($content['scans'])) {
                    $db->execute("TRUNCATE TABLE `{$storeCode}_countsheet`");
                    foreach ($content['scans'] as $scan) {
                        $slotNo = $scan['SlotNo'] ?? ($scan['location'] ?? '1');
                        $upc = $scan['UPC'] ?? ($scan['barcode'] ?? '');
                        $sku = $scan['SKU'] ?? ($scan['sku'] ?? '');
                        $descr = $scan['Descr'] ?? ($scan['product_name'] ?? '');
                        $qty = (float) ($scan['Qty'] ?? ($scan['original_qty'] ?? 1));
                        $editedQty = isset($scan['EditedQty']) ? (float) $scan['EditedQty'] : null;
                        $posted = (int) ($scan['Posted'] ?? 0);
                        $added = (int) ($scan['Added'] ?? 0);
                        $edited = (int) ($scan['Edited'] ?? 0);
                        $scannedBy = $scan['ScannedBy'] ?? 'Handheld';
                        $countDate = $scan['CountDate'] ?? date('Y-m-d H:i:s');
                        $variance = (float) ($scan['Variance'] ?? 0.00);

                        $db->execute(
                            "INSERT INTO `{$storeCode}_countsheet` (SlotNo, CountDate, UPC, SKU, Descr, Qty, EditedQty, Posted, Added, Edited, ScannedBy, Variance, synced) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)",
                            [$slotNo, $countDate, $upc, $sku, $descr, $qty, $editedQty, $posted, $added, $edited, $scannedBy, $variance]
                        );
                    }
                    $restoredScans = count($content['scans']);
                }

                $products = $content['products'] ?? ($content['items'] ?? []);
                if (!empty($products) && is_array($products)) {
                    $db->execute("TRUNCATE TABLE `{$storeCode}_items`");

                    $chunkSize = 200;
                    $chunks = array_chunk($products, $chunkSize);

                    $colNames = ['UPC', 'SKU', 'Descr', 'Type', 'Attr', 'Size', 'Price', 'Aux1', 'Qty'];
                    $colSql = implode(', ', array_map(function ($c) {
                        return "`{$c}`"; }, $colNames));
                    $singleRowPlaceholder = "(" . implode(', ', array_fill(0, count($colNames), '?')) . ")";

                    foreach ($chunks as $chunk) {
                        $placeholders = [];
                        $params = [];
                        foreach ($chunk as $row) {
                            $placeholders[] = $singleRowPlaceholder;
                            $params[] = $row['UPC'] ?? ($row['upc'] ?? '');
                            $params[] = $row['SKU'] ?? ($row['sku'] ?? '');
                            $params[] = $row['Descr'] ?? ($row['descr'] ?? ($row['product_name'] ?? ''));
                            $params[] = $row['Type'] ?? ($row['type'] ?? '');
                            $params[] = $row['Attr'] ?? ($row['attr'] ?? '');
                            $params[] = $row['Size'] ?? ($row['size'] ?? '');
                            $params[] = isset($row['Price']) ? (float) $row['Price'] : (isset($row['price']) ? (float) $row['price'] : 0.00);
                            $params[] = $row['Aux1'] ?? ($row['aux1'] ?? '');
                            $params[] = isset($row['Qty']) ? (float) $row['Qty'] : (isset($row['qty']) ? (float) $row['qty'] : 0);
                        }
                        if (!empty($placeholders)) {
                            $sql = "INSERT INTO `{$storeCode}_items` ({$colSql}) VALUES " . implode(', ', $placeholders);
                            $db->execute($sql, $params);
                        }
                    }
                }
            } else {
                throw new Exception("Unrecognized backup ID format.");
            }

            $totLoc = (int) ($db->query("SELECT COUNT(*) as count FROM `{$storeCode}_locators`")[0]['count'] ?? 0);
            $clsLoc = (int) ($db->query("SELECT COUNT(*) as count FROM `{$storeCode}_locators` WHERE status = 'closed'")[0]['count'] ?? 0);
            $isClosed = ($totLoc > 0 && $clsLoc === $totLoc) ? 1 : 0;
            $db->execute("UPDATE stores SET closed = ? WHERE LOWER(store_code) = ?", [$isClosed, $storeCode]);

            logAudit('Restore Cloud Backup', "Restored store '" . strtoupper($storeCode) . "' to backup state '{$backupId}' ({$restoredScans} scans, {$restoredLocs} locators).", strtoupper($storeCode));

            sendResponse([
                'status' => 'success',
                'message' => "Store '" . strtoupper($storeCode) . "' successfully restored from backup snapshot! Restored {$restoredLocs} locators and {$restoredScans} scan records."
            ]);
            break;

        case 'approve_sync_request':
            $id = (int) ($_POST['id'] ?? ($_GET['id'] ?? 0));
            if ($id <= 0) {
                throw new Exception("Invalid request ID.");
            }
            $db = new OWI_DB();
            $rows = $db->query("SELECT * FROM pending_sync_requests WHERE id = ?", [$id]);
            if (empty($rows)) {
                throw new Exception("Pending sync request not found.");
            }
            $req = $rows[0];
            $payload = json_decode($req['payload'], true);
            if (!$payload) {
                throw new Exception("Corrupted sync payload.");
            }

            $storeCode = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($payload['store_code'] ?? ''));
            $createdBy = $payload['store_details']['created_by'] ?? null;
            $db->createStoreTables($storeCode, $createdBy);

            // Automatically create backup on Cloud before overwriting data
            createCloudStoreBackup($db, $storeCode);

            // Automatically mark store session as CLOSED (closed = 1) on Cloud server upon sync approval
            $db->execute("UPDATE stores SET closed = 1 WHERE LOWER(store_code) = ?", [$storeCode]);

            if (!empty($payload['locators']) && is_array($payload['locators'])) {
                $db->execute("TRUNCATE TABLE `{$storeCode}_locators`");
                foreach ($payload['locators'] as $loc) {
                    $locName = $loc['locator_name'];
                    $status = $loc['status'] ?? 'open';
                    $operator = $loc['assigned_operator'] ?? null;
                    $db->execute("INSERT INTO `{$storeCode}_locators` (locator_name, status, assigned_operator, synced) VALUES (?, ?, ?, 1)", [$locName, $status, $operator]);
                }
            }

            foreach ($payload['scans'] ?? [] as $scan) {
                $recNo = isset($scan['id']) ? (int) $scan['id'] : 0;
                $slotNo = $scan['location'];
                $upc = $scan['barcode'];
                $sku = $scan['sku'] ?? '';
                $descr = $scan['product_name'] ?? '';
                $qty = (float) $scan['original_qty'];
                $editedQty = isset($scan['edited_qty']) ? (float) $scan['edited_qty'] : null;
                $posted = (int) ($scan['posted'] ?? 0);
                $added = (int) ($scan['added'] ?? 0);
                $edited = (int) ($scan['edited'] ?? 0);
                $scannedBy = !empty($scan['scanned_by']) ? $scan['scanned_by'] : 'Handheld';
                $countDate = $scan['scanned_at'] ?? date('Y-m-d H:i:s');
                $variance = (float) ($scan['variance'] ?? 0.00);

                // Match existing record by RecNo OR by SlotNo + UPC + CountDate
                $checkScan = [];
                if ($recNo > 0) {
                    $checkScan = $db->query("SELECT RecNo FROM `{$storeCode}_countsheet` WHERE RecNo = ?", [$recNo]);
                }
                if (empty($checkScan)) {
                    $checkScan = $db->query("SELECT RecNo FROM `{$storeCode}_countsheet` WHERE SlotNo = ? AND UPC = ? AND CountDate = ?", [$slotNo, $upc, $countDate]);
                }

                if (!empty($checkScan)) {
                    $targetRecNo = $checkScan[0]['RecNo'];
                    $db->execute(
                        "UPDATE `{$storeCode}_countsheet` 
                         SET SlotNo = ?, CountDate = ?, UPC = ?, SKU = ?, Descr = ?, Qty = ?, EditedQty = ?, Posted = ?, Added = ?, Edited = ?, ScannedBy = ?, Variance = ?, synced = 1
                         WHERE RecNo = ?",
                        [$slotNo, $countDate, $upc, $sku, $descr, $qty, $editedQty, $posted, $added, $edited, $scannedBy, $variance, $targetRecNo]
                    );
                } else {
                    $db->execute(
                        "INSERT INTO `{$storeCode}_countsheet` (SlotNo, CountDate, UPC, SKU, Descr, Qty, EditedQty, Posted, Added, Edited, ScannedBy, Variance, synced) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)",
                        [$slotNo, $countDate, $upc, $sku, $descr, $qty, $editedQty, $posted, $added, $edited, $scannedBy, $variance]
                    );
                }
            }

            // Sync items catalog for the store to the cloud
            $products = $payload['products'] ?? ($payload['items'] ?? []);
            if (!empty($products) && is_array($products)) {
                $db->execute("TRUNCATE TABLE `{$storeCode}_items`");

                $chunkSize = 200;
                $chunks = array_chunk($products, $chunkSize);

                $colNames = ['UPC', 'SKU', 'Descr', 'Type', 'Attr', 'Size', 'Price', 'Aux1', 'Qty'];
                $colSql = implode(', ', array_map(function ($c) {
                    return "`{$c}`"; }, $colNames));
                $singleRowPlaceholder = "(" . implode(', ', array_fill(0, count($colNames), '?')) . ")";

                foreach ($chunks as $chunk) {
                    $placeholders = [];
                    $params = [];
                    foreach ($chunk as $row) {
                        $placeholders[] = $singleRowPlaceholder;
                        $params[] = $row['UPC'] ?? ($row['upc'] ?? '');
                        $params[] = $row['SKU'] ?? ($row['sku'] ?? '');
                        $params[] = $row['Descr'] ?? ($row['descr'] ?? ($row['product_name'] ?? ''));
                        $params[] = $row['Type'] ?? ($row['type'] ?? '');
                        $params[] = $row['Attr'] ?? ($row['attr'] ?? '');
                        $params[] = $row['Size'] ?? ($row['size'] ?? '');
                        $params[] = isset($row['Price']) ? (float) $row['Price'] : (isset($row['price']) ? (float) $row['price'] : 0.00);
                        $params[] = $row['Aux1'] ?? ($row['aux1'] ?? '');
                        $params[] = isset($row['Qty']) ? (float) $row['Qty'] : (isset($row['qty']) ? (float) $row['qty'] : 0);
                    }
                    if (!empty($placeholders)) {
                        $sql = "INSERT INTO `{$storeCode}_items` ({$colSql}) VALUES " . implode(', ', $placeholders);
                        $db->execute($sql, $params);
                    }
                }
            }

            // Verify store completion status on Cloud after sync
            $totLoc = (int) ($db->query("SELECT COUNT(*) as count FROM `{$storeCode}_locators`")[0]['count'] ?? 0);
            $clsLoc = (int) ($db->query("SELECT COUNT(*) as count FROM `{$storeCode}_locators` WHERE status = 'closed'")[0]['count'] ?? 0);
            if ($totLoc > 0 && $clsLoc === $totLoc) {
                $db->execute("UPDATE stores SET closed = 1 WHERE LOWER(store_code) = ?", [$storeCode]);
            }

            $adminUser = $_SESSION['username'] ?? 'sys_admin';
            $db->execute("UPDATE pending_sync_requests SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?", [$adminUser, $id]);

            sendResponse([
                'status' => 'success',
                'message' => "Sync request #" . $id . " for store '" . strtoupper($storeCode) . "' approved! Cloud store database updated successfully."
            ]);
            break;

        case 'reject_sync_request':
            $id = (int) ($_POST['id'] ?? ($_GET['id'] ?? 0));
            if ($id <= 0) {
                throw new Exception("Invalid request ID.");
            }
            $db = new OWI_DB();
            $adminUser = $_SESSION['username'] ?? 'sys_admin';
            $db->execute("UPDATE pending_sync_requests SET status = 'rejected', approved_by = ?, approved_at = NOW() WHERE id = ?", [$adminUser, $id]);

            sendResponse([
                'status' => 'success',
                'message' => "Sync request #" . $id . " rejected."
            ]);
            break;

        case 'reopen_store':
            $storeCode = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($_GET['store_code'] ?? ($_POST['store_code'] ?? '')));
            if (empty($storeCode)) {
                throw new Exception("Invalid store code.");
            }
            $db = new OWI_DB();

            // 1. Re-open store status in stores table
            try {
                $db->execute("UPDATE stores SET closed = 0 WHERE LOWER(store_code) = ?", [$storeCode]);
            } catch (Exception $e) {
            }

            // 2. Set status = 'open' for locators in {store}_locators table
            try {
                $db->execute("UPDATE `{$storeCode}_locators` SET status = 'open'");
            } catch (Exception $e) {
            }

            sendResponse([
                'status' => 'success',
                'message' => "Store '" . strtoupper($storeCode) . "' has been successfully re-opened! Users can now access and continue scanning."
            ]);
            break;

        case 'get_cloud_stores':
            verifySyncToken();
            $db = new OWI_DB();
            $stores = [];
            try {
                $storeRows = $db->query("
                    SELECT s.*, u.username as creator_name 
                    FROM stores s 
                    LEFT JOIN users u ON s.created_by = u.id 
                    ORDER BY s.id DESC
                ");
                foreach ($storeRows as $s) {
                    $sCode = strtolower($s['store_code']);
                    $scansCount = 0;
                    $locsCount = 0;
                    try {
                        $scansCount = (int) ($db->query("SELECT COUNT(*) as c FROM `{$sCode}_countsheet`")[0]['c'] ?? 0);
                    } catch (Exception $eS) {
                    }
                    try {
                        $locsCount = (int) ($db->query("SELECT COUNT(*) as c FROM `{$sCode}_locators`")[0]['c'] ?? 0);
                    } catch (Exception $eL) {
                    }

                    $stores[] = [
                        'id' => $s['id'],
                        'store_code' => strtoupper($s['store_code']),
                        'creator_name' => $s['creator_name'] ?? 'SYSTEM',
                        'created_at' => $s['created_at'],
                        'scans_count' => $scansCount,
                        'locators_count' => $locsCount,
                        'synced' => (int) ($s['synced'] ?? 0),
                        'closed' => (int) ($s['closed'] ?? 0)
                    ];
                }
            } catch (Exception $e) {
            }

            sendResponse([
                'status' => 'success',
                'stores' => $stores
            ]);
            break;

        case 'get_cloud_store_details':
            verifySyncToken();
            $store = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($_GET['store_code'] ?? ''));
            if (empty($store)) {
                throw new Exception("Store code required.");
            }
            $db = new OWI_DB();

            $storeRows = [];
            try {
                $storeRows = $db->query("
                    SELECT s.*, u.username as creator_username, u.password as creator_password, u.role as creator_role 
                    FROM stores s 
                    LEFT JOIN users u ON s.created_by = u.id 
                    WHERE LOWER(s.store_code) = ?", [$store]);
            } catch (Exception $e) {
                // Table 'stores' or column doesn't exist on cloud
            }

            $exists = !empty($storeRows);
            $storeObj = $exists ? $storeRows[0] : null;

            $locators = [];
            if ($exists) {
                try {
                    $locators = $db->query("SELECT * FROM `{$store}_locators`");
                } catch (Exception $e) {
                }
            }

            $scans = [];
            if ($exists) {
                try {
                    $scans = $db->query("SELECT * FROM `{$store}_countsheet`");
                } catch (Exception $e) {
                }
            }

            // Fetch products specific to this store based on stores_id store number
            $products = [];
            try {
                $storeLookup = $db->query("SELECT str_no FROM stores_id WHERE LOWER(str_code) = ? OR str_no = ? LIMIT 1", [strtolower($store), $store]);
                $strNo = (!empty($storeLookup) && is_numeric($storeLookup[0]['str_no'])) ? (int) $storeLookup[0]['str_no'] : null;

                if ($strNo !== null) {
                    try {
                        $products = $db->query("SELECT UPC, SKU, Descr, Type, Attr, Size, Price, Aux1, `QTY_STORE_{$strNo}` as Qty FROM items");
                    } catch (Exception $eCol) {
                        $products = $db->query("SELECT UPC, SKU, Descr, Type, Attr, Size, Price, Aux1, 0.00 as Qty FROM items");
                    }
                } else {
                    // Store does NOT exist in stores_id table -> Default all item stock quantities to 0.00
                    try {
                        $tableCheck = $db->query("SHOW TABLES LIKE '{$store}_items'");
                        if (!empty($tableCheck)) {
                            $cnt = (int) ($db->query("SELECT COUNT(*) as c FROM `{$store}_items`")[0]['c'] ?? 0);
                            if ($cnt > 0) {
                                $products = $db->query("SELECT UPC, SKU, Descr, Type, Attr, Size, Price, Aux1, Qty FROM `{$store}_items`");
                            } else {
                                $products = $db->query("SELECT UPC, SKU, Descr, Type, Attr, Size, Price, Aux1, 0.00 as Qty FROM items");
                            }
                        } else {
                            $products = $db->query("SELECT UPC, SKU, Descr, Type, Attr, Size, Price, Aux1, 0.00 as Qty FROM items");
                        }
                    } catch (Exception $ex) {
                        $products = $db->query("SELECT UPC, SKU, Descr, Type, Attr, Size, Price, Aux1, 0.00 as Qty FROM items");
                    }
                }
            } catch (Exception $e) {
                $products = [];
            }

            sendResponse([
                'status' => 'success',
                'store_found' => $exists,
                'store' => $storeObj,
                'locators' => $locators,
                'scans' => $scans,
                'products' => $products
            ]);
            break;

        case 'get_cloud_products':
            verifySyncToken();
            $db = new OWI_DB();
            $products = $db->query("SELECT * FROM items");
            sendResponse([
                'status' => 'success',
                'products' => $products
            ]);
            break;

        case 'import_cloud_users':
            $config = loadConfig();
            $cloudUrl = trim($config['cloud_sync_url'] ?? '');
            if (empty($cloudUrl)) {
                $cloudUrl = 'https://pginv.officewarehouse.com.ph/OWIPI/';
            }
            $secretToken = trim($config['sync_secret_token'] ?? '');

            $targetUrl = rtrim($cloudUrl, '/') . '/api.php?action=get_cloud_users&secret_token=' . urlencode($secretToken);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $targetUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

            $result = curl_exec($ch);
            $err = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($err) {
                throw new Exception("cURL Error: " . $err);
            }

            $resData = json_decode($result, true);
            if ($httpCode !== 200 || !$resData || ($resData['status'] ?? 'error') !== 'success') {
                $msg = $resData['message'] ?? 'Connection to cloud failed.';
                throw new Exception("Cloud API Error (HTTP $httpCode): " . $msg);
            }

            $users = $resData['users'] ?? [];
            if (empty($users)) {
                throw new Exception("No users found in cloud database.");
            }

            $db = new OWI_DB();

            // Truncate local users table
            $db->execute("TRUNCATE TABLE users");

            // Insert users
            foreach ($users as $u) {
                $db->execute(
                    "INSERT INTO users (username, password, role) VALUES (?, ?, ?)",
                    [$u['username'], $u['password'], $u['role']]
                );
            }

            sendResponse([
                'status' => 'success',
                'message' => "Successfully imported " . count($users) . " user accounts from cloud!"
            ]);
            break;

        case 'get_cloud_users':
            verifySyncToken();
            $db = new OWI_DB();
            $users = $db->query("SELECT username, password, role FROM users");
            sendResponse([
                'status' => 'success',
                'users' => $users
            ]);
            break;

        case 'download_system_zip':
            verifySyncToken();

            $zipFile = tempnam(sys_get_temp_dir(), 'owipi_') . '.zip';
            $zip = new ZipArchive();

            if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new Exception("Cannot create temporary zip archive.");
            }

            $sourcePath = realpath(__DIR__);
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($sourcePath),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($files as $name => $file) {
                if (!$file->isDir()) {
                    $filePath = $file->getRealPath();
                    $relativePath = substr($filePath, strlen($sourcePath) + 1);

                    // Exclude config, zip, and git files
                    if (basename($filePath) === 'db_config.json' || pathinfo($filePath, PATHINFO_EXTENSION) === 'zip' || strpos($relativePath, '.git') !== false) {
                        continue;
                    }

                    $zip->addFile($filePath, $relativePath);
                }
            }

            $zip->close();

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="owipi.zip"');
            header('Content-Length: ' . filesize($zipFile));
            readfile($zipFile);
            unlink($zipFile);
            exit;

        case 'get_scans_html':
            $db = new OWI_DB();
            $store = strtolower($input['store_code'] ?? ($_GET['store_code'] ?? ''));
            $location = $input['location'] ?? ($_GET['location'] ?? '');

            if (empty($store) || empty($location)) {
                echo "<tr><td colspan='3' style='text-align:center;'>Missing parameters.</td></tr>";
                exit;
            }

            $sql = "
                SELECT UPC, Descr, Qty, EditedQty, Edited 
                FROM `{$store}_countsheet` 
                WHERE LOWER(TRIM(SlotNo)) = LOWER(TRIM(?)) 
                ORDER BY RecNo DESC 
                LIMIT 5
            ";
            try {
                $rows = $db->query($sql, [$location]);
                if (empty($rows)) {
                    echo "<tr><td colspan='3' style='text-align:center; color:#8b949e;'>No items scanned.</td></tr>";
                } else {
                    foreach ($rows as $row) {
                        $name = !empty($row['Descr']) ? $row['Descr'] : 'Item Not Found';
                        $qty = $row['Edited'] ? $row['EditedQty'] : $row['Qty'];
                        $qtyFormatted = number_format($qty, 0);
                        echo "<tr>";
                        echo "<td>" . htmlspecialchars($row['UPC']) . "</td>";
                        echo "<td>" . htmlspecialchars($name) . "</td>";
                        echo "<td style='text-align:center; font-weight:bold;'>" . $qtyFormatted . "</td>";
                        echo "</tr>";
                    }
                }
            } catch (Exception $ex) {
                echo "<tr><td colspan='3' style='text-align:center; color:#ff7b72;'>Error loading logs.</td></tr>";
            }
            exit;

        default:
            throw new Exception("Unknown action: " . $action);
    }
} catch (Exception $e) {
    sendResponse([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}

function handleReceiveSync()
{
    try {
        verifySyncToken();
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            throw new Exception("Invalid JSON sync payload.");
        }

        $storeCode = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($input['store_code'] ?? ''));
        if (empty($storeCode)) {
            throw new Exception("Invalid store code in payload.");
        }

        $db = new OWI_DB();

        $syncedBy = $input['synced_by'] ?? 'UNKNOWN';

        // Check if store already exists on cloud and enforce Admin authorization for overwriting
        $existingStore = $db->query("SELECT id FROM stores WHERE LOWER(store_code) = ?", [$storeCode]);
        if (!empty($existingStore)) {
            // Check if user has System Admin or Admin role on cloud
            $userCheck = $db->query("SELECT role FROM users WHERE LOWER(username) = LOWER(?)", [$syncedBy]);
            $userRole = !empty($userCheck) ? strtolower($userCheck[0]['role'] ?? 'user') : 'user';
            $isAuthorizedAdmin = in_array($userRole, ['admin', 'system_admin', 'sys_admin']);

            if (!$isAuthorizedAdmin) {
                try {
                    $cloudScansCheck = $db->query("SELECT COUNT(*) as count FROM `{$storeCode}_countsheet`");
                    $cloudScansCount = (int) ($cloudScansCheck[0]['count'] ?? 0);
                    if ($cloudScansCount > 0) {
                        throw new Exception("Cloud Overwrite Blocked: Store session '" . strtoupper($storeCode) . "' already exists on the cloud with {$cloudScansCount} scan records. User '{$syncedBy}' is not a System Admin or Admin. Overwriting existing cloud store data requires System Admin or Admin approval.");
                    }
                } catch (Exception $exCloud) {
                    if (strpos($exCloud->getMessage(), 'Cloud Overwrite Blocked') !== false) {
                        throw $exCloud;
                    }
                }
            }
        }

        $storeDetails = $input['store_details'] ?? [];
        $createdBy = $storeDetails['created_by'] ?? null;

        // Automatically create backup on Cloud before overwriting store tables
        createCloudStoreBackup($db, $storeCode);

        $db->createStoreTables($storeCode, $createdBy);

        // Automatically mark store as CLOSED on Cloud when synced from local
        $db->execute("UPDATE stores SET closed = 1 WHERE LOWER(store_code) = ?", [$storeCode]);

        $locators = $input['locators'] ?? [];
        if (!empty($locators)) {
            $db->execute("TRUNCATE TABLE `{$storeCode}_locators`");
            foreach ($locators as $loc) {
                $locName = $loc['locator_name'];
                $status = $loc['status'] ?? 'open';
                $operator = $loc['assigned_operator'] ?? null;

                $db->execute(
                    "INSERT INTO `{$storeCode}_locators` (locator_name, status, assigned_operator, synced) VALUES (?, ?, ?, 1)",
                    [$locName, $status, $operator]
                );
            }
        }

        $scans = $input['scans'] ?? [];
        foreach ($scans as $scan) {
            $recNo = (int) ($scan['id'] ?? 0);
            $barcode = $scan['barcode'];
            $sku = $scan['sku'] ?? '';
            $desc = $scan['product_name'] ?? '';
            $qty = (float) $scan['original_qty'];
            $editedQty = isset($scan['edited_qty']) ? (float) $scan['edited_qty'] : null;
            $posted = (int) ($scan['posted'] ?? 0);
            $added = (int) ($scan['added'] ?? 0);
            $edited = (int) ($scan['edited'] ?? 0);
            $scannedBy = !empty($scan['scanned_by']) ? $scan['scanned_by'] : 'Handheld';
            $countDate = $scan['scanned_at'] ?? date('Y-m-d H:i:s');
            $location = $scan['location'];
            $variance = isset($scan['variance']) ? (float) $scan['variance'] : 0.00;

            $check = [];
            if ($recNo > 0) {
                $check = $db->query("SELECT RecNo FROM `{$storeCode}_countsheet` WHERE RecNo = ?", [$recNo]);
            }
            if (empty($check)) {
                $check = $db->query("SELECT RecNo FROM `{$storeCode}_countsheet` WHERE SlotNo = ? AND UPC = ? AND CountDate = ?", [$location, $barcode, $countDate]);
            }

            if (!empty($check)) {
                $targetRecNo = $check[0]['RecNo'];
                $db->execute(
                    "UPDATE `{$storeCode}_countsheet` 
                     SET SlotNo = ?, CountDate = ?, UPC = ?, SKU = ?, Descr = ?, Qty = ?, EditedQty = ?, Posted = ?, Added = ?, Edited = ?, ScannedBy = ?, synced = 1, Variance = ?
                     WHERE RecNo = ?",
                    [$location, $countDate, $barcode, $sku, $desc, $qty, $editedQty, $posted, $added, $edited, $scannedBy, $variance, $targetRecNo]
                );
            } else {
                $db->execute(
                    "INSERT INTO `{$storeCode}_countsheet` 
                     (SlotNo, CountDate, UPC, SKU, Descr, Qty, EditedQty, Posted, Added, Edited, ScannedBy, Variance, synced)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)",
                    [$location, $countDate, $barcode, $sku, $desc, $qty, $editedQty, $posted, $added, $edited, $scannedBy, $variance]
                );
            }
        }

        // Sync items catalog for the store to the cloud
        $products = $input['products'] ?? [];
        if (!empty($products)) {
            $db->execute("TRUNCATE TABLE `{$storeCode}_items`");

            $chunkSize = 200;
            $chunks = array_chunk($products, $chunkSize);

            $colNames = ['UPC', 'SKU', 'Descr', 'Type', 'Attr', 'Size', 'Price', 'Aux1', 'Qty'];
            $colSql = implode(', ', array_map(function ($c) {
                return "`{$c}`"; }, $colNames));
            $singleRowPlaceholder = "(" . implode(', ', array_fill(0, count($colNames), '?')) . ")";

            foreach ($chunks as $chunk) {
                $placeholders = [];
                $params = [];
                foreach ($chunk as $row) {
                    $placeholders[] = $singleRowPlaceholder;
                    $params[] = $row['UPC'];
                    $params[] = $row['SKU'];
                    $params[] = $row['Descr'] ?? '';
                    $params[] = $row['Type'] ?? '';
                    $params[] = $row['Attr'] ?? '';
                    $params[] = $row['Size'] ?? '';
                    $params[] = $row['Price'] ?? 0.00;
                    $params[] = $row['Aux1'] ?? '';
                    $params[] = $row['Qty'] ?? 0;
                }
                if (!empty($placeholders)) {
                    $sql = "INSERT INTO `{$storeCode}_items` ({$colSql}) VALUES " . implode(', ', $placeholders);
                    $db->execute($sql, $params);
                }
            }
        }

        // Sync users table to the cloud
        $usersList = $input['users'] ?? [];
        if (!empty($usersList)) {
            foreach ($usersList as $u) {
                $uName = trim($u['username'] ?? '');
                if (empty($uName))
                    continue;

                $uPass = $u['password'] ?? '';
                $uRole = $u['role'] ?? 'user';

                $checkUser = $db->query("SELECT id FROM users WHERE LOWER(username) = LOWER(?)", [$uName]);
                if (!empty($checkUser)) {
                    $db->execute(
                        "UPDATE users SET password = ?, role = ? WHERE LOWER(username) = LOWER(?)",
                        [$uPass, $uRole, $uName]
                    );
                } else {
                    $db->execute(
                        "INSERT INTO users (username, password, role) VALUES (?, ?, ?)",
                        [$uName, $uPass, $uRole]
                    );
                }
            }
        }

        // Sync local audit logs to the cloud audit_logs table
        $incomingAuditLogs = $input['audit_logs'] ?? [];
        if (!empty($incomingAuditLogs)) {
            foreach ($incomingAuditLogs as $log) {
                $logStore = $log['store_code'] ?? $storeCode;
                $logUser = $log['username'] ?? 'UNKNOWN';
                $logAction = $log['action'] ?? '';
                $logDetails = $log['details'] ?? '';
                $logCreatedAt = $log['created_at'] ?? date('Y-m-d H:i:s');

                // Prevent duplicate audit entries on cloud
                $checkLog = $db->query(
                    "SELECT id FROM audit_logs WHERE (store_code = ? OR (store_code IS NULL AND ? IS NULL)) AND username = ? AND action = ? AND details = ? AND created_at = ?",
                    [$logStore, $logStore, $logUser, $logAction, $logDetails, $logCreatedAt]
                );
                if (empty($checkLog)) {
                    $db->execute(
                        "INSERT INTO audit_logs (store_code, username, action, details, created_at) VALUES (?, ?, ?, ?, ?)",
                        [$logStore, $logUser, $logAction, $logDetails, $logCreatedAt]
                    );
                }
            }
        }

        $syncedBy = $input['synced_by'] ?? ($_SESSION['username'] ?? 'SYSTEM');
        logAudit(
            'RECEIVE_SYNC',
            "Received sync payload for store session '" . strtoupper($storeCode) . "' containing " . count($locators) . " locators, " . count($scans) . " scan records, " . count($products) . " catalog items, " . count($usersList) . " user accounts, and " . count($incomingAuditLogs) . " audit logs.",
            strtoupper($storeCode),
            $syncedBy
        );

        sendResponse([
            'status' => 'success',
            'message' => 'Sync payload processed successfully.',
            'synced_locators' => count($locators),
            'synced_scans' => count($scans),
            'synced_products' => count($products),
            'synced_users' => count($usersList),
            'synced_audit_logs' => count($incomingAuditLogs)
        ]);

    } catch (Exception $e) {
        sendResponse(['status' => 'error', 'message' => $e->getMessage()]);
    }
}

// Verify sync token authentication helper
function verifySyncToken()
{
    $rawInput = json_decode(file_get_contents('php://input'), true);
    $secretToken = trim($rawInput['secret_token'] ?? ($_GET['secret_token'] ?? ($_POST['secret_token'] ?? '')));

    $config = loadConfig();
    $expectedToken = trim($config['sync_secret_token'] ?? '');

    if (!empty($expectedToken) && $secretToken !== $expectedToken) {
        http_response_code(401);
        $cloudHint = !empty($expectedToken) ? " Expected token: '$expectedToken'." : " No token set on cloud.";
        sendResponse(['status' => 'error', 'message' => "Unauthorized sync token. Provided token does not match cloud secret token.$cloudHint"]);
        exit;
    }
}
