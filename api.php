<?php
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

// Enforce Authentication
$adminActions = ['get_config', 'save_config', 'test_connection', 'init_db', 'clear_scans', 'add_product', 'delete_product'];
$userActions = ['get_diagnostics', 'submit_scan', 'get_scans', 'get_products', 'get_stores', 'select_store', 'logout_store', 'get_locators', 'add_locator', 'delete_locator', 'claim_locator', 'close_locator', 'approve_locator', 'edit_scan', 'get_print_spacing', 'save_print_spacing', 'get_users', 'add_user', 'delete_user', 'import_masterfile', 'get_audit_logs', 'get_sync_config', 'save_sync_config', 'trigger_cloud_sync', 'get_scans_html'];

$storeDependentActions = ['submit_scan', 'get_scans', 'clear_scans', 'get_locators', 'add_locator', 'delete_locator', 'claim_locator', 'close_locator', 'approve_locator', 'edit_scan', 'trigger_cloud_sync', 'get_scans_html'];

try {
    $bypassAuth = false;
    $incomingStoreCode = $rawInput['store_code'] ?? ($_GET['store_code'] ?? '');
    if (($action === 'submit_scan' || $action === 'claim_locator' || $action === 'close_locator' || $action === 'get_product_info' || $action === 'get_scans_html') && !empty($incomingStoreCode)) {
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
                throw new Exception("Failed to write config file.");
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

        case 'get_stores':
            $db = new OWI_DB();
            if ($_SESSION['role'] === 'system_admin' || $_SESSION['role'] === 'admin') {
                $sql = "SELECT id, store_code, DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') as created_at FROM stores ORDER BY store_code ASC";
                $stores = $db->query($sql);
            } else {
                $userId = (int) ($_SESSION['user_id'] ?? 0);
                $sql = "SELECT id, store_code, DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') as created_at FROM stores WHERE created_by = ? OR created_by IS NULL ORDER BY store_code ASC";
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
            $store = strtolower($input['store_code'] ?? ($_SESSION['store_code'] ?? ''));
            if (empty($store)) {
                throw new Exception("Store code is required.");
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

            // Check if product exists in global items catalog (resolving by UPC or SKU)
            $sqlFindProduct = "SELECT UPC, SKU, Descr, Type FROM items WHERE UPC = ? OR SKU = ?";
            $productRows = $db->query($sqlFindProduct, [$barcode, $barcode]);

            $product_found = false;
            $product_name = 'Unknown Product';
            $product_type = '';
            $sku = '';
            $real_barcode = $barcode;

            if (!empty($productRows)) {
                $product_found = true;
                $product_name = $productRows[0]['Descr'];
                $product_type = $productRows[0]['Type'];
                $sku = $productRows[0]['SKU'];
                $real_barcode = $productRows[0]['UPC'];
            }

            // Insert scan log into dynamic store countsheet table
            $sqlInsertScan = "
                INSERT INTO `{$store}_countsheet` (SlotNo, UPC, SKU, Descr, Qty, ScannedBy, CountDate) 
                VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ";
            $db->execute($sqlInsertScan, [$location, $real_barcode, $sku, $product_name, $qty, $scanned_by]);

            // Retrieve updated scanned count (total number of scans/rows) for this locator
            $countRows = $db->query("SELECT COUNT(*) as count FROM `{$store}_countsheet` WHERE SlotNo = ?", [$location]);
            $scanned_count = !empty($countRows) ? (int)$countRows[0]['count'] : 0;

            sendResponse([
                'status' => 'success',
                'message' => 'Scan logged successfully!',
                'data' => [
                    'barcode' => $barcode,
                    'quantity' => $qty,
                    'location' => $location,
                    'scanned_by' => $scanned_by,
                    'product_found' => $product_found,
                    'product_name' => $product_name,
                    'product_type' => $product_type,
                    'sku' => $sku,
                    'scanned_count' => $scanned_count
                ]
            ]);
            break;

        case 'get_scans':
            $db = new OWI_DB();
            $store = strtolower($_SESSION['store_code']);

            // Fetch scans from dynamic store countsheet table
            $sqlScans = "
                SELECT RecNo as id, UPC as barcode, Qty as original_qty, 
                       IF(Edited = 1, EditedQty, Qty) as quantity, 
                       SlotNo as location, ScannedBy as scanned_by, 
                       DATE_FORMAT(CountDate, '%Y-%m-%d %H:%i:%s') as scanned_at,
                       Descr as product_name, SKU as sku,
                       Added as added, Edited as edited, EditedQty as edited_qty
                FROM `{$store}_countsheet`
                ORDER BY RecNo DESC
            ";
            $scans = $db->query($sqlScans);
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

            // Look up product in catalog (resolving by UPC or SKU)
            $sqlFindProduct = "SELECT UPC, SKU, Descr FROM items WHERE UPC = ? OR SKU = ?";
            $productRows = $db->query($sqlFindProduct, [$barcode, $barcode]);

            $product_name = 'Unknown Product';
            $sku = '';
            $real_barcode = $barcode;

            if (!empty($productRows)) {
                $product_name = $productRows[0]['Descr'];
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

            logAudit('Edit Scanned Item', "Updated item in {$oldDetails} -> New UPC: {$real_barcode}, New Qty: {$qty}");

            sendResponse([
                'status' => 'success',
                'message' => 'Scan updated successfully!'
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
            $db = new OWI_DB();
            $sqlFindProduct = "SELECT UPC, SKU, Descr, Type FROM items WHERE UPC = ? OR SKU = ?";
            $productRows = $db->query($sqlFindProduct, [$barcode, $barcode]);
            
            $product_found = false;
            $product_name = 'Unknown Product';
            $product_type = '';
            
            if (!empty($productRows)) {
                $product_found = true;
                $product_name = $productRows[0]['Descr'];
                $product_type = $productRows[0]['Type'];
            }
            
            sendResponse([
                'status' => 'success',
                'product_found' => $product_found,
                'product_name' => $product_name,
                'product_type' => $product_type
            ]);
            break;

        case 'get_products':
            $db = new OWI_DB();
            $sqlProducts = "SELECT UPC as barcode, SKU as sku, Descr as product_name, Type as type FROM items ORDER BY Descr ASC";
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

            if (empty($barcode) || empty($name)) {
                throw new Exception("UPC (Barcode) and Product Description are required.");
            }

            $db = new OWI_DB();

            // Check if product exists to decide action name
            $checkProd = $db->query("SELECT UPC FROM items WHERE UPC = ?", [$barcode]);
            $actionName = !empty($checkProd) ? 'Edit Catalog Product' : 'Add Catalog Product';

            // Insert/Update global items catalog
            $sqlInsert = "
                INSERT INTO items (UPC, SKU, Descr, Type) 
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    SKU = VALUES(SKU), 
                    Descr = VALUES(Descr), 
                    Type = VALUES(Type)
            ";
            $db->execute($sqlInsert, [$barcode, $sku, $name, $type]);

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

            $importedCount = 0;
            $headerChecked = false;

            // Map header indexes
            $aluIdx = -1;
            $desc1Idx = -1;
            $desc2Idx = -1;

            // Start transaction for speed
            $db->execute("START TRANSACTION");

            try {
                // Clear existing database catalog table first (Option 1)
                $db->execute("TRUNCATE TABLE items");

                $sqlInsert = "
                    INSERT INTO items (UPC, SKU, Descr, Type) 
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        SKU = VALUES(SKU), 
                        Descr = VALUES(Descr), 
                        Type = VALUES(Type)
                ";

                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }

                    // Split by tab
                    $cols = explode("\t", $line);
                    if (empty($cols)) {
                        continue;
                    }

                    if (!$headerChecked) {
                        // Header check: find indexes
                        foreach ($cols as $idx => $headerName) {
                            $headerName = trim(strtolower($headerName));
                            if ($headerName === 'alu') {
                                $aluIdx = $idx;
                            } elseif ($headerName === 'desc1') {
                                $desc1Idx = $idx;
                            } elseif ($headerName === 'desc2') {
                                $desc2Idx = $idx;
                            }
                        }

                        // If headers were missing or not matched, default to standard legacy layout
                        if ($aluIdx === -1)
                            $aluIdx = 0;
                        if ($desc1Idx === -1)
                            $desc1Idx = 3;
                        if ($desc2Idx === -1)
                            $desc2Idx = 4;

                        $headerChecked = true;

                        // Check if this line is the header line itself, and skip importing it
                        $isHeaderRow = false;
                        foreach ($cols as $colVal) {
                            if (trim(strtolower($colVal)) === 'alu') {
                                $isHeaderRow = true;
                                break;
                            }
                        }
                        if ($isHeaderRow) {
                            continue;
                        }
                    }

                    $alu = isset($cols[$aluIdx]) ? trim($cols[$aluIdx]) : '';
                    $desc1 = isset($cols[$desc1Idx]) ? trim($cols[$desc1Idx]) : '';
                    $desc2 = isset($cols[$desc2Idx]) ? trim($cols[$desc2Idx]) : '';

                    if ($alu === '') {
                        continue;
                    }

                    // Pad ALU to 13 digits to form UPC
                    $upc = str_pad($alu, 13, '0', STR_PAD_LEFT);
                    $sku = $alu;
                    $descr = $desc1;
                    $type = $desc2;

                    $db->execute($sqlInsert, [$upc, $sku, $descr, $type]);
                    $importedCount++;
                }

                $db->execute("COMMIT");
            } catch (Exception $e) {
                $db->execute("ROLLBACK");
                throw $e;
            }

            sendResponse([
                'status' => 'success',
                'message' => "Successfully imported $importedCount products into store catalog!"
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
            $store = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($_SESSION['store_code']));

            // Self-healing: Ensure locators table is dynamically provisioned if session was already active
            try {
                $checkTbl = $db->query("SHOW TABLES LIKE '{$store}_locators'");
                if (empty($checkTbl)) {
                    $db->createStoreTables($_SESSION['store_code']);
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
            $db = new OWI_DB();
            $store = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($_SESSION['store_code']));

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
            $scanned_count = !empty($countRows) ? (int)$countRows[0]['count'] : 0;

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
            $store = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($_SESSION['store_code']));
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
                'cloud_sync_url' => $config['cloud_sync_url'] ?? '',
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

            // Retrieve store details
            $storeRows = $db->query("SELECT * FROM stores WHERE LOWER(store_code) = ?", [$store]);
            if (empty($storeRows)) {
                throw new Exception("Store code does not exist in master stores table.");
            }
            $storeDetails = $storeRows[0];

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
                       Posted as posted
                FROM `{$store}_countsheet`
                WHERE synced = 0
            ");

            if (empty($locators) && empty($scans)) {
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
                'scans' => $scans
            ];

            // Send via cURL
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, rtrim($cloudUrl, '/') . '/api.php?action=receive_sync');
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
                        $name = !empty($row['Descr']) ? $row['Descr'] : 'Unknown Product';
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

function handleReceiveSync() {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            throw new Exception("Invalid JSON sync payload.");
        }

        $secretToken = $input['secret_token'] ?? '';
        $config = loadConfig();
        $expectedToken = $config['sync_secret_token'] ?? '';

        if (empty($expectedToken) || $secretToken !== $expectedToken) {
            http_response_code(401);
            sendResponse(['status' => 'error', 'message' => 'Unauthorized sync token.']);
        }

        $storeCode = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($input['store_code'] ?? ''));
        if (empty($storeCode)) {
            throw new Exception("Invalid store code in payload.");
        }

        $db = new OWI_DB();
        
        $storeDetails = $input['store_details'] ?? [];
        $createdBy = $storeDetails['created_by'] ?? null;
        $db->createStoreTables($storeCode, $createdBy);

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
            $editedQty = isset($scan['edited_qty']) ? (float)$scan['edited_qty'] : null;
            $posted = (int) ($scan['posted'] ?? 0);
            $added = (int) ($scan['added'] ?? 0);
            $edited = (int) ($scan['edited'] ?? 0);
            $scannedBy = $scan['scanned_by'] ?? 'Handheld';
            $countDate = $scan['scanned_at'] ?? date('Y-m-d H:i:s');
            $location = $scan['location'];

            $check = $db->query("SELECT RecNo FROM `{$storeCode}_countsheet` WHERE RecNo = ?", [$recNo]);
            if (!empty($check)) {
                $db->execute(
                    "UPDATE `{$storeCode}_countsheet` 
                     SET SlotNo = ?, CountDate = ?, UPC = ?, SKU = ?, Descr = ?, Qty = ?, EditedQty = ?, Posted = ?, Added = ?, Edited = ?, ScannedBy = ?, synced = 1
                     WHERE RecNo = ?",
                    [$location, $countDate, $barcode, $sku, $desc, $qty, $editedQty, $posted, $added, $edited, $scannedBy, $recNo]
                );
            } else {
                $db->execute(
                    "INSERT INTO `{$storeCode}_countsheet` 
                     (RecNo, SlotNo, CountDate, UPC, SKU, Descr, Qty, EditedQty, Posted, Added, Edited, ScannedBy, synced)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)",
                    [$recNo, $location, $countDate, $barcode, $sku, $desc, $qty, $editedQty, $posted, $added, $edited, $scannedBy]
                );
            }
        }

        sendResponse([
            'status' => 'success',
            'message' => 'Sync payload processed successfully.',
            'synced_locators' => count($locators),
            'synced_scans' => count($scans)
        ]);

    } catch (Exception $e) {
        sendResponse(['status' => 'error', 'message' => $e->getMessage()]);
    }
}
