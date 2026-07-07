<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/config.php';

$response = ['status' => 'error', 'message' => 'Invalid action'];
$action = isset($_GET['action']) ? $_GET['action'] : '';

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
$userActions = ['get_diagnostics', 'submit_scan', 'get_scans', 'get_products', 'get_stores', 'select_store', 'logout_store', 'get_locators', 'add_locator', 'delete_locator', 'claim_locator', 'close_locator', 'approve_locator', 'edit_scan', 'get_print_spacing', 'save_print_spacing', 'get_users', 'add_user', 'delete_user', 'import_masterfile', 'get_audit_logs'];

$storeDependentActions = ['submit_scan', 'get_scans', 'clear_scans', 'get_locators', 'add_locator', 'delete_locator', 'claim_locator', 'close_locator', 'approve_locator', 'edit_scan'];

try {
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
                'print_margin_top' => isset($config['print_margin_top']) ? (int)$config['print_margin_top'] : 0,
                'print_margin_left' => isset($config['print_margin_left']) ? (int)$config['print_margin_left'] : 0
            ]);
            break;

        case 'save_print_spacing':
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                throw new Exception("Invalid JSON inputs.");
            }
            $config = loadConfig();
            $config['print_margin_top'] = isset($input['print_margin_top']) ? (int)$input['print_margin_top'] : 0;
            $config['print_margin_left'] = isset($input['print_margin_left']) ? (int)$input['print_margin_left'] : 0;
            
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

            $config = [
                'server' => isset($input['server']) ? trim($input['server']) : 'localhost',
                'port' => isset($input['port']) ? trim($input['port']) : '3306',
                'database' => isset($input['database']) ? trim($input['database']) : 'owi_physical_inventory',
                'username' => isset($input['username']) ? trim($input['username']) : 'root',
                'password' => isset($input['password']) ? trim($input['password']) : '',
                'print_margin_top' => isset($input['print_margin_top']) ? (int)$input['print_margin_top'] : 0,
                'print_margin_left' => isset($input['print_margin_left']) ? (int)$input['print_margin_left'] : 0
            ];

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
            $store = strtolower($_SESSION['store_code']);

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
                        "UPDATE `{$store}_locators` SET status = 'in_use', assigned_operator = ? WHERE locator_name = ?",
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
            $sqlFindProduct = "SELECT UPC, SKU, Descr FROM items WHERE UPC = ? OR SKU = ?";
            $productRows = $db->query($sqlFindProduct, [$barcode, $barcode]);

            $product_found = false;
            $product_name = 'Unknown Product';
            $sku = '';
            $real_barcode = $barcode;

            if (!empty($productRows)) {
                $product_found = true;
                $product_name = $productRows[0]['Descr'];
                $sku = $productRows[0]['SKU'];
                $real_barcode = $productRows[0]['UPC'];
            }

            // Insert scan log into dynamic store countsheet table
            $sqlInsertScan = "
                INSERT INTO `{$store}_countsheet` (SlotNo, UPC, SKU, Descr, Qty, ScannedBy, CountDate) 
                VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ";
            $db->execute($sqlInsertScan, [$location, $real_barcode, $sku, $product_name, $qty, $scanned_by]);

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
                    'sku' => $sku
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
                SET UPC = ?, SKU = ?, Descr = ?, EditedQty = ?, Edited = 1
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
                        if ($aluIdx === -1) $aluIdx = 0;
                        if ($desc1Idx === -1) $desc1Idx = 3;
                        if ($desc2Idx === -1) $desc2Idx = 4;

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

            $locators = $db->query("SELECT * FROM `{$store}_locators` ORDER BY id ASC");
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

            $db->execute("UPDATE `{$store}_locators` SET status = 'in_use', assigned_operator = ? WHERE locator_name = ?", [$operator, $name]);
            sendResponse(['status' => 'success', 'message' => "Locator '$name' claimed successfully!"]);
            break;

        case 'close_locator':
            $data = json_decode(file_get_contents('php://input'), true);
            $name = trim($data['locator_name'] ?? '');
            if ($name === '') {
                throw new Exception("Locator name is required.");
            }
            $db = new OWI_DB();
            $store = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($_SESSION['store_code']));
            $db->execute("UPDATE `{$store}_locators` SET status = 'closed' WHERE locator_name = ?", [$name]);
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
            $db->execute("UPDATE `{$store}_locators` SET status = 'open', assigned_operator = NULL WHERE id = ?", [$id]);
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

        default:
            throw new Exception("Unknown action: " . $action);
    }
} catch (Exception $e) {
    sendResponse([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
