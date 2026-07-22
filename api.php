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

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/config.php';

$action = isset($_GET['action']) ? $_GET['action'] : '';

$rawInput = json_decode(file_get_contents('php://input'), true);
if (!$rawInput) {
    $rawInput = $_POST;
}

if ($action === 'receive_sync') {
    handleReceiveSync();
}

// Helper function to send JSON response
function sendResponse($data)
{
    echo json_encode($data);
    exit;
}

// Helper function to write to master audit_logs table
function logAudit($action, $details, $storeCode = null)
{
    try {
        $db = new OWI_DB();
        $username = $_SESSION['username'] ?? 'UNKNOWN';
        $store = $storeCode ? $storeCode : ($_SESSION['store_code'] ?? null);

        $sql = "INSERT INTO audit_logs (store_code, username, action, details) VALUES (?, ?, ?, ?)";
        $db->execute($sql, [$store, $username, $action, $details]);
    } catch (Exception $e) {
        error_log("Failed to write audit log: " . $e->getMessage());
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
    $db = new OWI_DB();
    $barcodeClean = trim($barcode);
    if ($barcodeClean === '') {
        return [];
    }

    $storeInput = $storeCode ?? ($_GET['store_code'] ?? ($_SESSION['store_code'] ?? ''));
    $cleanStore = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($storeInput));

    $tablesToSearch = [];
    if (!empty($cleanStore)) {
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
    }
    // Always include central items table as fallback
    $tablesToSearch[] = 'items';

    foreach ($tablesToSearch as $tableName) {
        // 1. Direct exact match check (fastest)
        $rows = $db->query("SELECT UPC, SKU, Descr, Type, Attr, Size, Price, Aux1, Qty FROM `{$tableName}` WHERE UPC = ? OR SKU = ?", [$barcodeClean, $barcodeClean]);
        if (!empty($rows)) {
            return $rows;
        }

        // 2. Flexible numeric matching (handles leading zeros like 016965 <-> 16965)
        if (ctype_digit($barcodeClean)) {
            $unpadded = ltrim($barcodeClean, '0');
            if ($unpadded === '') {
                $unpadded = '0';
            }
            $padded6 = str_pad($unpadded, 6, '0', STR_PAD_LEFT);

            $sql = "SELECT UPC, SKU, Descr, Type, Attr, Size, Price, Aux1, Qty FROM `{$tableName}` 
                    WHERE UPC = ? OR SKU = ? 
                       OR UPC = ? OR SKU = ?
                       OR TRIM(LEADING '0' FROM UPC) = ? 
                       OR TRIM(LEADING '0' FROM SKU) = ?";

            $params = [$barcodeClean, $barcodeClean, $padded6, $padded6, $unpadded, $unpadded];
            $rows = $db->query($sql, $params);
            if (!empty($rows)) {
                return $rows;
            }
        }
    }

    return [];
}

// Enforce Authentication
$adminActions = ['get_config', 'save_config', 'save_sync_token', 'test_connection', 'init_db', 'restore_default_db', 'clear_scans', 'add_product', 'delete_product', 'import_cloud_products', 'import_cloud_users', 'delete_store', 'backup_db'];
$userActions = ['get_diagnostics', 'submit_scan', 'get_scans', 'get_products', 'get_product_info', 'delete_scan', 'get_stores', 'select_store', 'logout_store', 'get_locators', 'add_locator', 'delete_locator', 'claim_locator', 'close_locator', 'approve_locator', 'edit_scan', 'get_print_spacing', 'save_print_spacing', 'get_users', 'add_user', 'delete_user', 'import_masterfile', 'get_audit_logs', 'get_sync_config', 'save_sync_config', 'trigger_cloud_sync', 'get_scans_html', 'close_store', 'get_cloud_stores', 'get_cloud_store_details', 'get_cloud_products', 'get_cloud_users', 'fetch_cloud_stores', 'import_cloud_store'];

$storeDependentActions = ['submit_scan', 'get_scans', 'clear_scans', 'get_locators', 'add_locator', 'delete_locator', 'claim_locator', 'close_locator', 'approve_locator', 'edit_scan', 'trigger_cloud_sync', 'get_scans_html', 'close_store'];

try {
    $bypassAuth = false;
    if ($action === 'get_cloud_stores' || $action === 'get_cloud_store_details' || $action === 'get_cloud_products' || $action === 'receive_sync') {
        $bypassAuth = true;
    }

    $incomingStoreCode = $rawInput['store_code'] ?? ($_GET['store_code'] ?? '');
    if (($action === 'submit_scan' || $action === 'claim_locator' || $action === 'close_locator' || $action === 'get_product_info' || $action === 'get_scans' || $action === 'edit_scan' || $action === 'delete_scan' || $action === 'get_scans_html') && !empty($incomingStoreCode)) {
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
            if ($_SESSION['role'] === 'system_admin' || $_SESSION['role'] === 'admin') {
                $sql = "SELECT id, store_code, DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') as created_at FROM stores WHERE closed = 0 ORDER BY store_code ASC";
                $stores = $db->query($sql);
            } else {
                $userId = (int) ($_SESSION['user_id'] ?? 0);
                $sql = "SELECT id, store_code, DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') as created_at FROM stores WHERE (created_by = ? OR created_by IS NULL) AND closed = 0 ORDER BY store_code ASC";
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

            // Provision store tables dynamically (creates e.g. tes_countsheet and tes_items)
            $userId = (int) ($_SESSION['user_id'] ?? 0);
            $db->createStoreTables($cleanStore, $userId, $locatorsCount);

            $_SESSION['store_code'] = strtoupper($cleanStore);

            sendResponse([
                'status' => 'success',
                'message' => 'Store selected successfully!',
                'store_code' => $_SESSION['store_code']
            ]);
            break;

        case 'close_store':
            $input = json_decode(file_get_contents('php://input'), true);
            $storeCode = isset($input['store_code']) ? trim($input['store_code']) : '';
            if (empty($storeCode)) {
                throw new Exception("Store Code is required.");
            }
            $cleanStore = preg_replace('/[^a-zA-Z0-9_]/', '', $storeCode);

            $db = new OWI_DB();

            // Validate: check locator completion progress
            $checkTbl = $db->query("SHOW TABLES LIKE '{$cleanStore}_locators'");
            if (empty($checkTbl)) {
                throw new Exception("Store is not initialized.");
            }

            $totalRows = $db->query("SELECT COUNT(*) as count FROM `{$cleanStore}_locators`");
            $totalLocators = (int) ($totalRows[0]['count'] ?? 0);
            if ($totalLocators === 0) {
                throw new Exception("No locators found in this store to close.");
            }

            $closedRows = $db->query("SELECT COUNT(*) as count FROM `{$cleanStore}_locators` WHERE status = 'closed'");
            $closedLocators = (int) ($closedRows[0]['count'] ?? 0);

            if ($closedLocators < $totalLocators) {
                throw new Exception("Cannot close store. All locators must be closed first (Progress is " . round(($closedLocators / $totalLocators) * 100) . "%).");
            }

            // Update stores table setting closed = 1
            $db->execute("UPDATE stores SET closed = 1 WHERE store_code = ?", [strtoupper($cleanStore)]);

            // Clear current store session
            unset($_SESSION['store_code']);

            logAudit('CLOSE_STORE', "Closed store session '" . strtoupper($cleanStore) . "' after 100% completion.", strtoupper($cleanStore));

            sendResponse([
                'status' => 'success',
                'message' => "Store session '" . strtoupper($cleanStore) . "' closed successfully!"
            ]);
            break;

        case 'delete_store':
            checkAuth(true); // Requires system_admin
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

            logAudit('DELETE_STORE', "Permanently deleted store session '" . strtoupper($store) . "' and dropped all its tables.");

            sendResponse([
                'status' => 'success',
                'message' => "Successfully and permanently deleted store session '" . strtoupper($store) . "'!"
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

                    // Auto-claim the reopened locator back to current operator
                    $db->execute(
                        "UPDATE `{$store}_locators` SET status = 'in_use', assigned_operator = ?, synced = 0 WHERE locator_name = ?",
                        [$scanned_by, $location]
                    );
                } elseif ($locStatus === 'in_use' && !empty($assignedOp)) {
                    if (strtolower($assignedOp) !== strtolower($scanned_by)) {
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
                $real_barcode = $productRows[0]['UPC'];
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
            $db = new OWI_DB();
            $store = strtolower($_SESSION['store_code']);
            $location = isset($_GET['location']) ? trim($_GET['location']) : '';

            // Fetch scans from dynamic store countsheet table
            $sqlScans = "
                SELECT c.RecNo as id, c.UPC as barcode, c.Qty as original_qty, 
                       IF(c.Edited = 1, c.EditedQty, c.Qty) as quantity, 
                       c.SlotNo as location, c.ScannedBy as scanned_by, 
                       DATE_FORMAT(c.CountDate, '%Y-%m-%d %H:%i:%s') as scanned_at,
                       c.Descr as product_name, c.SKU as sku,
                       c.Added as added, c.Edited as edited, c.EditedQty as edited_qty,
                       c.Variance as variance,
                       COALESCE(i.Qty, 0.00) as master_qty
                FROM `{$store}_countsheet` c
                LEFT JOIN items i ON i.UPC = c.UPC
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
                'scans' => $scans
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
            $real_barcode = $barcode;

            if (!empty($productRows)) {
                $descr = $productRows[0]['Descr'];
                $attr = $productRows[0]['Attr'] ?? '';
                $size = $productRows[0]['Size'] ?? '';

                $product_name = formatProductDescription($descr, $attr, $size);
                $sku = $productRows[0]['SKU'];
                $real_barcode = $productRows[0]['UPC'];
            }

            // Fetch old scan state for audit trail
            $oldScanQuery = "SELECT SlotNo, UPC, Qty, EditedQty, Edited FROM `{$store}_countsheet` WHERE RecNo = ?";
            $oldScanRows = $db->query($oldScanQuery, [$id]);
            $oldDetails = "RecNo: {$id}";
            if (!empty($oldScanRows)) {
                $origQty = $oldScanRows[0]['Edited'] ? $oldScanRows[0]['EditedQty'] : $oldScanRows[0]['Qty'];
                $oldDetails = "Locator: {$oldScanRows[0]['SlotNo']}, UPC: {$oldScanRows[0]['UPC']}, Qty: {$origQty}";
            }

            $sqlUpdateScan = "
                UPDATE `{$store}_countsheet` 
                SET UPC = ?, SKU = ?, Descr = ?, EditedQty = ?, Edited = 1, synced = 0
                WHERE RecNo = ?
            ";
            $db->execute($sqlUpdateScan, [$real_barcode, $sku, $product_name, $qty, $id]);

            // Recalculate variance for this product in this slot/locator
            $slotNo = !empty($oldScanRows) ? $oldScanRows[0]['SlotNo'] : '1';
            $masterQty = 0.00;
            $productCheck = findCatalogProduct($real_barcode, $store);
            if (!empty($productCheck)) {
                $masterQty = (float) ($productCheck[0]['Qty'] ?? 0.00);
            }
            $sumQuery = $db->query("SELECT SUM(IF(Edited = 1, EditedQty, Qty)) as total FROM `{$store}_countsheet` WHERE UPC = ? AND SlotNo = ?", [$real_barcode, $slotNo]);
            $totalScanned = (float) ($sumQuery[0]['total'] ?? 0.00);
            $newVariance = $totalScanned - $masterQty;
            $db->execute("UPDATE `{$store}_countsheet` SET Variance = ? WHERE UPC = ? AND SlotNo = ?", [$newVariance, $real_barcode, $slotNo]);

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
                $resolvedBarcode = $productRows[0]['UPC'];
                $resolvedSku = $productRows[0]['SKU'];

                $product_name = formatProductDescription($descr, $attr, $size);
                $masterQty = (float) ($productRows[0]['Qty'] ?? 0.00);

                if (!empty($store)) {
                    $tableCheck = $db->query("SHOW TABLES LIKE '{$store}_countsheet'");
                    if (!empty($tableCheck)) {
                        $sumQuery = $db->query("SELECT SUM(IF(Edited = 1, EditedQty, Qty)) as total FROM `{$store}_countsheet` WHERE UPC = ? AND SlotNo = ?", [$productRows[0]['UPC'], $location]);
                        $totalScanned = (float) ($sumQuery[0]['total'] ?? 0.00);
                    }
                }
                $variance = $totalScanned - $masterQty;
                $varianceStr = ($variance >= 0 ? "+" : "") . $variance;
                $product_type = "Mst Qty: {$masterQty} | Scan: {$totalScanned}\nVar: " . $varianceStr;
            }

            sendResponse([
                'status' => 'success',
                'product_found' => $product_found,
                'product_name' => $product_name,
                'product_type' => $product_type,
                'master_qty' => $masterQty,
                'total_scanned' => $totalScanned,
                'variance' => $variance,
                'barcode' => $resolvedBarcode,
                'sku' => $resolvedSku
            ]);
            break;

        case 'get_products':
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
            $price = isset($input['price']) ? (float)$input['price'] : 0.00;
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
                } catch (Exception $e) {}

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
                        if ($aluIdx === -1) $aluIdx = 0;
                        if ($upcIdx === -1 && isset($cols[1])) $upcIdx = 1;
                        if ($desc1Idx === -1 && isset($cols[2])) $desc1Idx = 2;
                        if ($desc2Idx === -1 && isset($cols[3])) $desc2Idx = 3;
                        if ($attrIdx === -1 && isset($cols[4])) $attrIdx = 4;
                        if ($sizeIdx === -1 && isset($cols[5])) $sizeIdx = 5;
                        if ($priceIdx === -1 && isset($cols[6])) $priceIdx = 6;
                        if ($aux1Idx === -1 && isset($cols[7])) $aux1Idx = 7;

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
                $colSql = implode(', ', array_map(function($c) { return "`{$c}`"; }, $colNames));

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
            if ($_SESSION['role'] !== 'system_admin' && $_SESSION['role'] !== 'admin') {
                sendResponse(['status' => 'error', 'message' => 'Unauthorized access.']);
            }
            $db = new OWI_DB();
            if ($_SESSION['role'] === 'admin') {
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
            if ($_SESSION['role'] !== 'system_admin' && $_SESSION['role'] !== 'admin') {
                sendResponse(['status' => 'error', 'message' => 'Unauthorized access.']);
            }
            $input = json_decode(file_get_contents('php://input'), true);
            $username = isset($input['username']) ? strtoupper(trim($input['username'])) : '';
            $password = isset($input['password']) ? trim($input['password']) : '';
            $role = isset($input['role']) ? trim($input['role']) : 'user';

            if (empty($username) || empty($password)) {
                throw new Exception("Username and password are required.");
            }

            if ($_SESSION['role'] === 'admin') {
                $role = 'user';
            }

            if ($role !== 'system_admin' && $role !== 'admin' && $role !== 'user') {
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
            if ($_SESSION['role'] !== 'system_admin' && $_SESSION['role'] !== 'admin') {
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
            $db = new OWI_DB();
            $storeCode = $_SESSION['store_code'] ?? '';
            if (empty($storeCode)) {
                sendResponse(['status' => 'error', 'message' => 'No active store selected.']);
            }

            $store = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($storeCode));

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
            sendResponse(['status' => 'success', 'locators' => $locators]);
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
            if ($loc['status'] === 'in_use' && strtolower(trim($loc['assigned_operator'])) !== strtolower($operator)) {
                throw new Exception("This locator is already claimed by operator: " . $loc['assigned_operator']);
            }

            // Check if this operator name is already claimed/active in ANY OTHER locator
            $sqlCheckOp = "SELECT locator_name FROM `{$store}_locators` WHERE status = 'in_use' AND LOWER(TRIM(assigned_operator)) = ? AND LOWER(TRIM(locator_name)) != ?";
            $checkOpRows = $db->query($sqlCheckOp, [strtolower($operator), strtolower($name)]);
            if (!empty($checkOpRows)) {
                $otherLoc = $checkOpRows[0]['locator_name'];
                throw new Exception("Operator name '$operator' is already active in another locator: '$otherLoc'.");
            }

            $db->execute("UPDATE `{$store}_locators` SET status = 'in_use', assigned_operator = ?, synced = 0 WHERE locator_name = ?", [$operator, $name]);

            // Query current scanned count (total scans count) for this locator
            $countRows = $db->query("SELECT COUNT(*) as count FROM `{$store}_countsheet` WHERE SlotNo = ?", [$name]);
            $scanned_count = !empty($countRows) ? (int) $countRows[0]['count'] : 0;

            sendResponse([
                'status' => 'success',
                'message' => "Locator '$name' claimed successfully!",
                'scanned_count' => $scanned_count
            ]);
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
            $db->execute("UPDATE `{$store}_locators` SET status = 'open', assigned_operator = NULL, synced = 0 WHERE id = ?", [$id]);
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

            // Fetch unsynced locators
            $locators = $db->query("SELECT * FROM `{$store}_locators` WHERE synced = 0");

            // Fetch unsynced scans
            $scans = $db->query("
                SELECT RecNo as id, UPC as barcode, Qty as original_qty, 
                       IF(Edited = 1, EditedQty, Qty) as quantity, 
                       SlotNo as location, ScannedBy as scanned_by, 
                       DATE_FORMAT(CountDate, '%Y-%m-%d %H:%i:%s') as scanned_at,
                       Descr as product_name, SKU as sku,
                       Added as added, Edited as edited, EditedQty as edited_qty,
                       Posted as posted, Variance as variance
                FROM `{$store}_countsheet`
                WHERE synced = 0
            ");

            // Fetch items catalog
            $products = [];
            $checkItemsTbl = $db->query("SHOW TABLES LIKE ?", ["{$store}_items"]);
            if (!empty($checkItemsTbl)) {
                $products = $db->query("SELECT UPC, SKU, Descr, Type, Attr, Size, Price, Aux1, Qty FROM `{$store}_items`");
            }

            if (empty($locators) && empty($scans) && empty($products)) {
                sendResponse([
                    'status' => 'success',
                    'message' => 'Everything is already synchronized with the cloud.'
                ]);
                break;
            }

            // Prepare payload
            $payload = [
                'secret_token' => $secretToken,
                'store_code' => $_SESSION['store_code'],
                'store_details' => $storeDetails,
                'locators' => $locators,
                'scans' => $scans,
                'products' => $products
            ];

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

            // Update local synced status
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
            $secretToken = trim($config['sync_secret_token'] ?? '');
            if (empty($cloudUrl)) {
                throw new Exception("Cloud Sync URL is not configured.");
            }

            $targetUrl = rtrim($cloudUrl, '/') . '/api.php?action=get_cloud_stores&secret_token=' . urlencode($secretToken);

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
            $secretToken = trim($config['sync_secret_token'] ?? '');
            if (empty($cloudUrl)) {
                throw new Exception("Cloud Sync URL is not configured.");
            }

            $targetUrl = rtrim($cloudUrl, '/') . '/api.php?action=get_cloud_store_details&store_code=' . urlencode($store) . '&secret_token=' . urlencode($secretToken);

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
                $msg = $resData['message'] ?? 'Download from cloud failed.';
                throw new Exception("Cloud API Error (HTTP $httpCode): " . $msg);
            }

            $cloudStore = $resData['store'];
            $locators = $resData['locators'];
            $products = $resData['products'] ?? [];

            $db = new OWI_DB();

            // Create the local store and tables
            $db->createStoreTables($store, $cloudStore['created_by'] ?? null);

            // Sync the closed status
            $db->execute("UPDATE stores SET closed = ? WHERE LOWER(store_code) = ?", [(int) $cloudStore['closed'], $store]);

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

            // Populate store items table with specific store products from cloud
            $productsImported = 0;
            if (!empty($products)) {
                $db->execute("TRUNCATE TABLE `{$store}_items`");

                $chunkSize = 200;
                $chunks = array_chunk($products, $chunkSize);

                $colNames = ['UPC', 'SKU', 'Descr', 'Type', 'Attr', 'Size', 'Price', 'Aux1', 'Qty'];
                $colSql = implode(', ', array_map(function($c) { return "`{$c}`"; }, $colNames));
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
                        $params[] = isset($row['Price']) ? (float)$row['Price'] : (isset($row['price']) ? (float)$row['price'] : 0.00);
                        $params[] = $row['Aux1'] ?? $row['AUX1'] ?? $row['aux1'] ?? null;
                        $params[] = isset($row['Qty']) ? (float)$row['Qty'] : (isset($row['qty']) ? (float)$row['qty'] : 0.00);
                    }
                    $sqlChunk = "INSERT INTO `{$store}_items` ({$colSql}) VALUES " . implode(', ', $placeholders);
                    $db->execute($sqlChunk, $params);
                }
                $productsImported = count($products);
            }

            sendResponse([
                'status' => 'success',
                'message' => "Successfully imported store session '" . strtoupper($store) . "' with " . count($locators) . " locators and " . $productsImported . " specific products!"
            ]);
            break;

        case 'import_cloud_products':
            $store = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($_GET['store_code'] ?? ''));
            $config = loadConfig();
            $cloudUrl = trim($config['cloud_sync_url'] ?? '');
            $secretToken = trim($config['sync_secret_token'] ?? '');
            if (empty($cloudUrl)) {
                throw new Exception("Cloud Sync URL is not configured.");
            }

            $db = new OWI_DB();

            if (!empty($store)) {
                // Fetch store-specific details/products from cloud
                $targetUrl = rtrim($cloudUrl, '/') . '/api.php?action=get_cloud_store_details&store_code=' . urlencode($store) . '&secret_token=' . urlencode($secretToken);
                
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
                    $params[] = isset($p['Price']) ? (float)$p['Price'] : (isset($p['price']) ? (float)$p['price'] : 0.00);
                    $params[] = $p['Aux1'] ?? $p['aux1'] ?? null;
                    $params[] = isset($p['Qty']) ? (float)$p['Qty'] : (isset($p['qty']) ? (float)$p['qty'] : 0.00);
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

        case 'get_cloud_store_details':
            verifySyncToken();
            $store = $_GET['store_code'] ?? '';
            if (empty($store)) {
                throw new Exception("Store code required.");
            }
            $db = new OWI_DB();
            
            $storeRows = [];
            try {
                $storeRows = $db->query("SELECT * FROM stores WHERE LOWER(store_code) = ?", [$store]);
            } catch (Exception $e) {
                // Table 'stores' or column doesn't exist on cloud
            }

            $locators = [];
            try {
                $locators = $db->query("SELECT * FROM `{$store}_locators`");
            } catch (Exception $e) {
                // Table '{store}_locators' doesn't exist on cloud
            }

            // Fetch products specific to this store based on stores_id store number
            $products = [];
            try {
                $storeLookup = $db->query("SELECT str_no FROM stores_id WHERE LOWER(str_code) = ? OR str_no = ? LIMIT 1", [strtolower($store), $store]);
                if (!empty($storeLookup) && is_numeric($storeLookup[0]['str_no'])) {
                    $strNo = (int) $storeLookup[0]['str_no'];
                } else {
                    $numMatch = preg_replace('/[^0-9]/', '', $store);
                    if ($numMatch !== '') {
                        $strNo = (int) $numMatch;
                    } else {
                        $strNo = null;
                    }
                }

                if ($strNo !== null) {
                    $products = $db->query("SELECT UPC, SKU, Descr, Type, Attr, Size, Price, Aux1, `QTY_STORE_{$strNo}` as Qty FROM items");
                } else {
                    try {
                        $tableCheck = $db->query("SHOW TABLES LIKE '{$store}_items'");
                        if (!empty($tableCheck)) {
                            $products = $db->query("SELECT UPC, SKU, Descr, Type, Attr, Size, Price, Aux1, Qty FROM `{$store}_items`");
                        } else {
                            $products = $db->query("SELECT UPC, SKU, Descr, Type, Attr, Size, Price, Aux1, Qty FROM items");
                        }
                    } catch (Exception $ex) {
                        $products = $db->query("SELECT UPC, SKU, Descr, Type, Attr, Size, Price, Aux1, Qty FROM items");
                    }
                }
            } catch (Exception $e) {
                $products = [];
            }

            sendResponse([
                'status' => 'success',
                'store' => $storeRows[0] ?? null,
                'locators' => $locators,
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
            $secretToken = trim($config['sync_secret_token'] ?? '');
            if (empty($cloudUrl)) {
                throw new Exception("Cloud Sync URL is not configured.");
            }

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

        $storeDetails = $input['store_details'] ?? [];
        $createdBy = $storeDetails['created_by'] ?? null;
        $db->createStoreTables($storeCode, $createdBy);

        // Sync the closed status from the local payload to the cloud
        if (isset($storeDetails['closed'])) {
            $db->execute("UPDATE stores SET closed = ? WHERE LOWER(store_code) = ?", [(int) $storeDetails['closed'], $storeCode]);
        }

        $locators = $input['locators'] ?? [];
        foreach ($locators as $loc) {
            $locName = $loc['locator_name'];
            $status = $loc['status'] ?? 'open';
            $operator = $loc['assigned_operator'] ?? null;

            $check = $db->query("SELECT id FROM `{$storeCode}_locators` WHERE locator_name = ?", [$locName]);
            if (!empty($check)) {
                $db->execute(
                    "UPDATE `{$storeCode}_locators` SET status = ?, assigned_operator = ?, synced = 1 WHERE locator_name = ?",
                    [$status, $operator, $locName]
                );
            } else {
                $db->execute(
                    "INSERT INTO `{$storeCode}_locators` (locator_name, status, assigned_operator, synced) VALUES (?, ?, ?, 1)",
                    [$locName, $status, $operator]
                );
            }
        }

        $scans = $input['scans'] ?? [];
        foreach ($scans as $scan) {
            $recNo = (int) $scan['id'];
            $barcode = $scan['barcode'];
            $sku = $scan['sku'] ?? '';
            $desc = $scan['product_name'] ?? '';
            $qty = (float) $scan['original_qty'];
            $editedQty = isset($scan['edited_qty']) ? (float) $scan['edited_qty'] : null;
            $posted = (int) ($scan['posted'] ?? 0);
            $added = (int) ($scan['added'] ?? 0);
            $edited = (int) ($scan['edited'] ?? 0);
            $scannedBy = $scan['scanned_by'] ?? 'Handheld';
            $countDate = $scan['scanned_at'] ?? date('Y-m-d H:i:s');
            $location = $scan['location'];
            $variance = isset($scan['variance']) ? (float) $scan['variance'] : 0.00;

            $check = $db->query("SELECT RecNo FROM `{$storeCode}_countsheet` WHERE RecNo = ?", [$recNo]);
            if (!empty($check)) {
                $db->execute(
                    "UPDATE `{$storeCode}_countsheet` 
                     SET SlotNo = ?, CountDate = ?, UPC = ?, SKU = ?, Descr = ?, Qty = ?, EditedQty = ?, Posted = ?, Added = ?, Edited = ?, ScannedBy = ?, synced = 1, Variance = ?
                     WHERE RecNo = ?",
                    [$location, $countDate, $barcode, $sku, $desc, $qty, $editedQty, $posted, $added, $edited, $scannedBy, $variance, $recNo]
                );
            } else {
                $db->execute(
                    "INSERT INTO `{$storeCode}_countsheet` 
                     (RecNo, SlotNo, CountDate, UPC, SKU, Descr, Qty, EditedQty, Posted, Added, Edited, ScannedBy, Variance, synced)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)",
                    [$recNo, $location, $countDate, $barcode, $sku, $desc, $qty, $editedQty, $posted, $added, $edited, $scannedBy, $variance]
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
            $colSql = implode(', ', array_map(function($c) { return "`{$c}`"; }, $colNames));
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

        logAudit('RECEIVE_SYNC', "Received sync payload for store session '" . strtoupper($storeCode) . "' containing " . count($locators) . " locators, " . count($scans) . " scan records, and " . count($products) . " catalog items.", strtoupper($storeCode));

        sendResponse([
            'status' => 'success',
            'message' => 'Sync payload processed successfully.',
            'synced_locators' => count($locators),
            'synced_scans' => count($scans),
            'synced_products' => count($products)
        ]);

    } catch (Exception $e) {
        sendResponse(['status' => 'error', 'message' => $e->getMessage()]);
    }
}

// Verify sync token authentication helper
function verifySyncToken()
{
    $rawInput = json_decode(file_get_contents('php://input'), true);
    $secretToken = $rawInput['secret_token'] ?? ($_GET['secret_token'] ?? '');

    $config = loadConfig();
    $expectedToken = $config['sync_secret_token'] ?? '';

    if (!empty($expectedToken) && $secretToken !== $expectedToken) {
        http_response_code(401);
        sendResponse(['status' => 'error', 'message' => 'Unauthorized sync token.']);
        exit;
    }
}
