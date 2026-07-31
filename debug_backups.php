<?php
// OWIPI Cloud Backups Diagnostic Debugger
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");

require_once __DIR__ . '/config.php';

$action = $_GET['do'] ?? '';

$db = null;
$dbError = null;
try {
    $db = new OWI_DB();
} catch (Exception $e) {
    $dbError = $e->getMessage();
}

// Handle diagnostic actions
if ($action === 'create_test_row' && $db) {
    try {
        $ts = date('Ymd_His');
        $bId = "backup_test_" . $ts . ".sql";
        $db->execute("CREATE TABLE IF NOT EXISTS cloud_backups_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            backup_id VARCHAR(255) NOT NULL,
            store_code VARCHAR(50) NOT NULL,
            backup_type VARCHAR(50) NOT NULL DEFAULT 'sql_script',
            scans_count INT DEFAULT 0,
            locators_count INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $db->execute(
            "INSERT INTO cloud_backups_log (backup_id, store_code, backup_type, scans_count, locators_count, created_at) VALUES (?, 'TES', 'sql_script', 5, 2, NOW())",
            [$bId]
        );
        header("Location: debug_backups.php?msg=created");
        exit;
    } catch (Exception $eT) {
        $dbError = "Failed to insert test row: " . $eT->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>OWIPI Cloud Backups Debugger</title>
    <style>
        body { font-family: monospace; background: #0f172a; color: #e2e8f0; padding: 20px; line-height: 1.5; }
        h1, h2 { color: #38bdf8; border-bottom: 1px solid #334155; padding-bottom: 8px; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 8px; padding: 16px; margin-bottom: 20px; }
        .success { color: #4ade80; font-weight: bold; }
        .error { color: #f87171; font-weight: bold; }
        .warning { color: #fbbf24; font-weight: bold; }
        pre { background: #090d16; padding: 12px; border-radius: 6px; overflow-x: auto; color: #a7f3d0; font-size: 0.85rem; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #334155; padding: 8px 12px; text-align: left; font-size: 0.85rem; }
        th { background: #0f172a; color: #94a3b8; }
        a.btn { display: inline-block; padding: 8px 16px; background: #0284c7; color: white; border-radius: 6px; text-decoration: none; font-weight: bold; margin-right: 10px; margin-top: 10px; }
        a.btn:hover { background: #0369a1; }
    </style>
</head>
<body>

    <h1>🔍 OWIPI Cloud Backups Diagnostic Debugger</h1>
    <p>Server Time (UTC): <strong><?= date('Y-m-d H:i:s') ?></strong></p>

    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'created'): ?>
        <div class="card success" style="background: rgba(74,222,128,0.1); border-color: #4ade80;">
            ✅ Test backup row successfully inserted into <code>cloud_backups_log</code> table!
        </div>
    <?php endif; ?>

    <!-- 1. Database Connection Status -->
    <div class="card">
        <h2>1. Database Connection & Configuration</h2>
        <?php
        $config = loadConfig();
        echo "<div><strong>Configured Database Server:</strong> " . htmlspecialchars($config['server'] ?? 'N/A') . "</div>";
        echo "<div><strong>Configured Database Name:</strong> " . htmlspecialchars($config['database'] ?? 'N/A') . "</div>";
        echo "<div><strong>Configured DB Username:</strong> " . htmlspecialchars($config['username'] ?? 'N/A') . "</div>";
        if ($dbError) {
            echo "<div class='error'>❌ Connection Error: " . htmlspecialchars($dbError) . "</div>";
        } else {
            echo "<div class='success'>✅ Database connection established successfully via OWI_DB()</div>";
        }
        ?>
    </div>

    <!-- 2. cloud_backups_log Table Inspection -->
    <div class="card">
        <h2>2. Database Table: <code>cloud_backups_log</code></h2>
        <?php
        if ($db):
            try {
                $tables = $db->query("SHOW TABLES LIKE 'cloud_backups_log'");
                if (empty($tables)) {
                    echo "<div class='warning'>⚠️ Table 'cloud_backups_log' DOES NOT EXIST yet in MySQL database.</div>";
                } else {
                    echo "<div class='success'>✅ Table 'cloud_backups_log' exists in database.</div>";

                    $structure = $db->query("DESCRIBE cloud_backups_log");
                    echo "<h3>Table Structure (Columns):</h3>";
                    echo "<table><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
                    foreach ($structure as $col) {
                        echo "<tr><td>{$col['Field']}</td><td>{$col['Type']}</td><td>{$col['Null']}</td><td>{$col['Key']}</td><td>" . ($col['Default'] ?? 'NULL') . "</td></tr>";
                    }
                    echo "</table>";

                    $rows = $db->query("SELECT * FROM cloud_backups_log ORDER BY id DESC");
                    echo "<h3>Row Count: " . count($rows) . "</h3>";
                    if (!empty($rows)) {
                        echo "<h3>Raw Data Rows:</h3>";
                        echo "<pre>" . htmlspecialchars(print_r($rows, true)) . "</pre>";
                    } else {
                        echo "<div class='warning'>⚠️ 0 rows currently found in 'cloud_backups_log' table.</div>";
                    }
                }
            } catch (Exception $eTbl) {
                echo "<div class='error'>❌ Query Exception: " . htmlspecialchars($eTbl->getMessage()) . "</div>";
            }
        endif;
        ?>
        <div>
            <a href="debug_backups.php?do=create_test_row" class="btn">➕ Insert Test Backup Row into DB</a>
        </div>
    </div>

    <!-- 3. Physical Backups Directory Inspection -->
    <div class="card">
        <h2>3. Physical File Storage: <code>/backups</code> Directory</h2>
        <?php
        $backupDir = __DIR__ . '/backups';
        echo "<div><strong>Directory Path:</strong> " . htmlspecialchars($backupDir) . "</div>";
        if (is_dir($backupDir)) {
            echo "<div class='success'>✅ Directory '/backups' exists. Perms: " . substr(sprintf('%o', fileperms($backupDir)), -4) . "</div>";
            $sqlFiles = glob($backupDir . "/*.sql");
            $jsonFiles = glob($backupDir . "/*.json");
            $allFiles = array_merge($sqlFiles ? $sqlFiles : [], $jsonFiles ? $jsonFiles : []);

            echo "<h3>Found Files (" . count($allFiles) . "):</h3>";
            if (!empty($allFiles)) {
                echo "<table><tr><th>Filename</th><th>Size (Bytes)</th><th>Last Modified</th></tr>";
                foreach ($allFiles as $f) {
                    echo "<tr><td>" . htmlspecialchars(basename($f)) . "</td><td>" . filesize($f) . "</td><td>" . date('Y-m-d H:i:s', filemtime($f)) . "</td></tr>";
                }
                echo "</table>";
            } else {
                echo "<div class='warning'>⚠️ No .sql or .json backup files found in /backups directory.</div>";
            }
        } else {
            echo "<div class='warning'>⚠️ Directory '/backups' does not exist on disk yet.</div>";
        }
        ?>
    </div>

    <!-- 4. API get_cloud_backups Response Test -->
    <div class="card">
        <h2>4. Live API Payload: <code>api.php?action=get_cloud_backups</code></h2>
        <?php
        $apiUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/api.php?action=get_cloud_backups&_t=" . time();
        echo "<div><strong>Testing API Endpoint:</strong> <a href='" . htmlspecialchars($apiUrl) . "' target='_blank' style='color:#38bdf8;'>" . htmlspecialchars($apiUrl) . "</a></div>";

        $apiJson = @file_get_contents($apiUrl);
        if ($apiJson !== false) {
            echo "<h3>Raw API Response Text:</h3>";
            echo "<pre>" . htmlspecialchars($apiJson) . "</pre>";
        } else {
            echo "<div class='error'>❌ Failed to fetch API response directly.</div>";
        }
        ?>
    </div>

    <div style="margin-top: 20px;">
        <a href="debug_backups.php" class="btn" style="background: #10b981;">🔄 Refresh Debugger Page</a>
        <a href="index.php" class="btn" style="background: #64748b;">⬅️ Return to Dashboard</a>
    </div>

</body>
</html>
