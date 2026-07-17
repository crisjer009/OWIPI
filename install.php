<?php
// OWIPI Automated Installer & local deployment utility
header('Content-Type: text/html; charset=utf-8');
session_start();

$isLocalhost = in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1']) || $_SERVER['HTTP_HOST'] === 'localhost';

if (!$isLocalhost) {
    // Cloud context: We are running on the web server.
    // Display instructions and a button to download this installer script as a standalone file.
    if (isset($_GET['action']) && $_GET['action'] === 'download_script') {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="install.php"');
        header('Content-Length: ' . filesize(__FILE__));
        readfile(__FILE__);
        exit;
    }
    
    // Show download page
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>OWIPI Local Installer</title>
        <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700&display=swap" rel="stylesheet">
        <style>
            body {
                font-family: 'Outfit', sans-serif;
                background: #0b0f19;
                color: #e2e8f0;
                padding: 3rem 1.5rem;
                max-width: 650px;
                margin: 0 auto;
                line-height: 1.6;
            }
            .card {
                background: #111827;
                border: 1px solid rgba(255,255,255,0.08);
                border-radius: 12px;
                padding: 2.5rem;
                box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            }
            h1 { color: #38bdf8; font-size: 1.8rem; margin-top: 0; }
            h3 { color: #f8fafc; font-size: 1.2rem; }
            .btn {
                display: inline-block;
                padding: 0.85rem 1.75rem;
                background: #0284c7;
                color: white;
                text-decoration: none;
                border-radius: 8px;
                font-weight: 600;
                margin-top: 1.5rem;
                border: none;
                cursor: pointer;
                transition: background 0.2s;
            }
            .btn:hover { background: #0369a1; }
            code {
                background: rgba(255,255,255,0.07);
                padding: 0.2rem 0.4rem;
                border-radius: 4px;
                font-family: monospace;
                color: #38bdf8;
            }
            ol li { margin-bottom: 0.75rem; }
        </style>
    </head>
    <body>
        <div class="card">
            <h1>☁️ OWIPI Local Installer</h1>
            <p>Use this utility to deploy a clean OWIPI offline scanning gateway directly to any laptop in the warehouse.</p>
            
            <h3>How to use:</h3>
            <ol>
                <li>Click the button below to download the standalone installer file (<code>install.php</code>).</li>
                <li>Copy the downloaded <code>install.php</code> file to your laptop's XAMPP directory at <code>C:\xampp\htdocs\</code>.</li>
                <li>Open the laptop's web browser and visit: <code>http://localhost/install.php</code>.</li>
                <li>The script will connect back to this cloud server to pull the codebase, set up your database schema, clone all user accounts, and download the items masterfile!</li>
            </ol>
            
            <a href="install.php?action=download_script" class="btn">Download Standalone Installer Script</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Localhost context: We are running locally on the laptop inside htdocs.
$step = $_GET['step'] ?? 'form';

?>
<!DOCTYPE html>
<html>
<head>
    <title>OWIPI System Installer</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Outfit', sans-serif;
            background: #0b0f19;
            color: #e2e8f0;
            padding: 3rem 1.5rem;
            max-width: 600px;
            margin: 0 auto;
            line-height: 1.6;
        }
        .card {
            background: #111827;
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 12px;
            padding: 2.5rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
        }
        h1 { color: #38bdf8; font-size: 1.8rem; margin-top: 0; text-align: center; }
        label {
            display: block;
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            color: #94a3b8;
        }
        .form-control {
            width: 100%;
            box-sizing: border-box;
            height: 42px;
            padding: 0 0.75rem;
            border-radius: 6px;
            border: 1px solid rgba(255,255,255,0.15);
            background: #0f172a;
            color: #fff;
            font-family: inherit;
            margin-bottom: 1.25rem;
        }
        .btn {
            width: 100%;
            height: 46px;
            background: #0284c7;
            color: #fff;
            border: none;
            border-radius: 6px;
            font-weight: bold;
            cursor: pointer;
            font-size: 0.95rem;
            font-family: inherit;
            transition: background 0.2s;
        }
        .btn:hover { background: #0369a1; }
        .log-item {
            margin: 0.5rem 0;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>☁️ OWIPI System Installer</h1>
        
        <?php if ($step === 'form'): 
            // Detect cloud sync URL defaults based on current request referrer
            $referrer = $_SERVER['HTTP_REFERER'] ?? '';
            $defaultCloudUrl = '';
            if (!empty($referrer) && strpos($referrer, 'localhost') === false) {
                $defaultCloudUrl = preg_replace('/\/install\.php$/i', '', $referrer);
            }
        ?>
            <p style="font-size:0.85rem; color: #94a3b8; margin-bottom: 1.5rem; text-align: center;">Enter your Cloud credentials to automatically download the codebase, configure local credentials, and synchronize users and catalog files.</p>
            
            <form action="install.php?step=run" method="POST">
                <div class="form-group">
                    <label>Cloud Server URL</label>
                    <input type="url" name="cloud_url" value="<?= htmlspecialchars($defaultCloudUrl) ?>" placeholder="e.g. https://pginv.officewarehouse.com.ph/OWIPI" required class="form-control">
                </div>
                
                <div class="form-group">
                    <label>Sync Secret Token</label>
                    <input type="text" name="secret_token" placeholder="Enter your cloud sync token..." required class="form-control">
                </div>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1rem;">
                    <div class="form-group">
                        <label>Local Database Name</label>
                        <input type="text" name="db_name" value="owi_physical_inventory" required class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Local MySQL User</label>
                        <input type="text" name="db_user" value="root" required class="form-control">
                    </div>
                </div>

                <button type="submit" class="btn">Deploy & Initialize System</button>
            </form>
            
        <?php elseif ($step === 'run'): ?>
            <div style="background: rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.05); border-radius: 8px; padding: 1.5rem; margin-top: 1rem;">
                <?php
                $cloudUrl = trim($_POST['cloud_url'] ?? '');
                $secretToken = trim($_POST['secret_token'] ?? '');
                $dbName = trim($_POST['db_name'] ?? 'owi_physical_inventory');
                $dbUser = trim($_POST['db_user'] ?? 'root');
                
                $zipFile = __DIR__ . "/owipi-temp.zip";
                $tempExtractDir = __DIR__ . "/owipi-temp-extract";
                $targetDir = __DIR__ . "/OWIPI";

                // 1. Download system ZIP directly from the Cloud server!
                echo "<div class='log-item'>1. Requesting codebase package from cloud server...</div>";
                $zipUrl = rtrim($cloudUrl, '/') . '/api.php?action=download_system_zip&secret_token=' . urlencode($secretToken);
                
                $ch = curl_init($zipUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_TIMEOUT, 60);
                $data = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode !== 200 || !$data || strlen($data) < 1000) {
                    // Fallback to GitHub ZIP in case sync token is wrong or zip fails
                    echo "<div class='log-item' style='color:#eab308;'>⚠ Cloud download failed. Falling back to public GitHub codebase...</div>";
                    $zipUrl = "https://github.com/crisjer009/OWIPI/archive/refs/heads/main.zip";
                    $ch = curl_init($zipUrl);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
                    $data = curl_exec($ch);
                    curl_close($ch);
                }

                if (!$data) {
                    die("<div class='log-item' style='color: #ef4444;'>❌ Error: Could not download the system. Check cloud sync URL or internet connection.</div>");
                }
                file_put_contents($zipFile, $data);
                echo "<div class='log-item' style='color: #10b981;'>✓ Codebase package downloaded successfully.</div>";

                // 2. Extract ZIP
                echo "<div class='log-item'>2. Extracting package archive...</div>";
                $zip = new ZipArchive;
                if ($zip->open($zipFile) === TRUE) {
                    if (!is_dir($tempExtractDir)) {
                        mkdir($tempExtractDir, 0777, true);
                    }
                    $zip->extractTo($tempExtractDir);
                    $zip->close();
                    
                    $extractedFolders = glob($tempExtractDir . "/*", GLOB_ONLYDIR);
                    if (!empty($extractedFolders)) {
                        $sourceDir = $extractedFolders[0];
                        if (is_dir($targetDir)) {
                            // Delete old folder
                            function deleteFolder($dir) {
                                if (!is_dir($dir)) return;
                                $files = array_diff(scandir($dir), array('.', '..'));
                                foreach ($files as $file) {
                                    (is_dir("$dir/$file")) ? deleteFolder("$dir/$file") : unlink("$dir/$file");
                                }
                                rmddir($dir);
                            }
                            // Custom recursive helper for directory deletion
                            function rmddir($dirPath) {
                                if (!is_dir($dirPath)) return;
                                $files = array_diff(scandir($dirPath), array('.', '..'));
                                foreach ($files as $file) {
                                    (is_dir("$dirPath/$file")) ? rmddir("$dirPath/$file") : unlink("$dirPath/$file");
                                }
                                rmdir($dirPath);
                            }
                            rmddir($targetDir);
                        }
                        rename($sourceDir, $targetDir);
                        echo "<div class='log-item' style='color: #10b981;'>✓ Files extracted and deployed to C:/xampp/htdocs/OWIPI.</div>";
                    } else {
                        // Zip directly contains the files without subdirectory (from api.php packager)
                        if (is_dir($targetDir)) {
                            function rmddir($dirPath) {
                                if (!is_dir($dirPath)) return;
                                $files = array_diff(scandir($dirPath), array('.', '..'));
                                foreach ($files as $file) {
                                    (is_dir("$dirPath/$file")) ? rmddir("$dirPath/$file") : unlink("$dirPath/$file");
                                }
                                rmdir($dirPath);
                            }
                            rmddir($targetDir);
                        }
                        rename($tempExtractDir, $targetDir);
                        echo "<div class='log-item' style='color: #10b981;'>✓ Files extracted and deployed directly to C:/xampp/htdocs/OWIPI.</div>";
                    }
                    @unlink($zipFile);
                    if (is_dir($tempExtractDir)) {
                        function rmddir($dirPath) {
                            if (!is_dir($dirPath)) return;
                            $files = array_diff(scandir($dirPath), array('.', '..'));
                            foreach ($files as $file) {
                                (is_dir("$dirPath/$file")) ? rmddir("$dirPath/$file") : unlink("$dirPath/$file");
                            }
                            rmdir($dirPath);
                        }
                        rmddir($tempExtractDir);
                    }
                } else {
                    die("<div class='log-item' style='color: #ef4444;'>❌ Error: Failed to open ZIP archive.</div>");
                }

                // 3. Write Config File
                echo "<div class='log-item'>3. Creating connection config file...</div>";
                $configData = [
                    "server" => "localhost",
                    "port" => "3306",
                    "database" => $dbName,
                    "username" => $dbUser,
                    "password" => "",
                    "cloud_sync_url" => $cloudUrl,
                    "sync_secret_token" => $secretToken,
                    "print_margin_top" => 0,
                    "print_margin_left" => 10
                ];
                file_put_contents($targetDir . "/db_config.json", json_encode($configData, JSON_PRETTY_PRINT));
                echo "<div class='log-item' style='color: #10b981;'>✓ Local connection configuration established.</div>";

                // 4. Initialize Local Database
                echo "<div class='log-item'>4. Initializing local database tables...</div>";
                try {
                    // Create database if it doesn't exist
                    $pdo = new PDO("mysql:host=localhost", $dbUser, "");
                    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    
                    // Connect and run database setup
                    require_once $targetDir . '/config.php';
                    $db = new OWI_DB();
                    
                    $sqlFile = $targetDir . "/database.sql";
                    $importedBackup = false;
                    if (file_exists($sqlFile)) {
                        echo "<div class='log-item'>- Found default database.sql backup. Importing structure & data...</div>";
                        $db->importSqlFile($sqlFile);
                        echo "<div class='log-item' style='color: #10b981;'>✓ Database imported successfully from default database.sql backup.</div>";
                        $importedBackup = true;
                    } else {
                        $db->initializeDatabase();
                        echo "<div class='log-item' style='color: #10b981;'>✓ Database tables created (Audit Logs & Stores are empty).</div>";
                    }
                } catch (Exception $e) {
                    die("<div class='log-item' style='color: #ef4444;'>❌ Database Error: " . $e->getMessage() . "</div>");
                }

                if (!$importedBackup) {
                    // 5. Fetch Products from Cloud
                    echo "<div class='log-item'>5. Downloading products masterfile from cloud...</div>";
                    $targetUrl = rtrim($cloudUrl, '/') . '/api.php?action=get_cloud_products&secret_token=' . urlencode($secretToken);
                    
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $targetUrl);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    $result = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    $resData = json_decode($result, true);
                    if ($httpCode === 200 && $resData && ($resData['status'] ?? '') === 'success') {
                        $products = $resData['products'] ?? [];
                        if (!empty($products)) {
                            $db->execute("TRUNCATE TABLE items");
                            $chunkSize = 500;
                            $chunks = array_chunk($products, $chunkSize);
                            
                            foreach ($chunks as $chunk) {
                                $sqlInsert = "INSERT INTO items (UPC, SKU, Descr, Type, Attr, Size, Qty) VALUES ";
                                $placeholders = [];
                                $params = [];
                                
                                foreach ($chunk as $p) {
                                    $placeholders[] = "(?, ?, ?, ?, ?, ?, ?)";
                                    $params[] = $p['UPC'];
                                    $params[] = $p['SKU'];
                                    $params[] = $p['Descr'];
                                    $params[] = $p['Type'] ?? 'GENERAL';
                                    $params[] = $p['Attr'] ?? null;
                                    $params[] = $p['Size'] ?? null;
                                    $params[] = isset($p['Qty']) ? (float)$p['Qty'] : 0.00;
                                }
                                
                                $sqlInsert .= implode(', ', $placeholders);
                                $db->execute($sqlInsert, $params);
                            }
                            echo "<div class='log-item' style='color: #10b981;'>✓ Successfully imported " . count($products) . " items into local product master catalog!</div>";
                        } else {
                            echo "<div class='log-item' style='color: #eab308;'>⚠ Warning: Cloud returned an empty product catalog.</div>";
                        }
                    } else {
                        echo "<div class='log-item' style='color: #eab308;'>⚠ Warning: Failed to fetch products automatically (" . ($resData['message'] ?? 'API unreachable') . ").</div>";
                    }

                    // 6. Fetch User Accounts from Cloud
                    echo "<div class='log-item'>6. Syncing user profiles and scanner accounts from cloud...</div>";
                    $usersUrl = rtrim($cloudUrl, '/') . '/api.php?action=get_cloud_users&secret_token=' . urlencode($secretToken);
                    
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $usersUrl);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    $resultUsers = curl_exec($ch);
                    $httpCodeUsers = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    $resDataUsers = json_decode($resultUsers, true);
                    if ($httpCodeUsers === 200 && $resDataUsers && ($resDataUsers['status'] ?? '') === 'success') {
                        $users = $resDataUsers['users'] ?? [];
                        if (!empty($users)) {
                            $db->execute("TRUNCATE TABLE users");
                            foreach ($users as $u) {
                                $db->execute(
                                    "INSERT INTO users (username, password, role) VALUES (?, ?, ?)",
                                    [$u['username'], $u['password'], $u['role']]
                                );
                            }
                            echo "<div class='log-item' style='color: #10b981;'>✓ Successfully imported " . count($users) . " user accounts from cloud!</div>";
                        } else {
                            echo "<div class='log-item' style='color: #eab308;'>⚠ Warning: Cloud returned empty users list.</div>";
                        }
                    } else {
                        echo "<div class='log-item' style='color: #eab308;'>⚠ Warning: Failed to fetch user accounts automatically (" . ($resDataUsers['message'] ?? 'API unreachable') . "). Default accounts (sys_admin / operator) initialized.</div>";
                    }
                } else {
                    echo "<div class='log-item' style='color: #10b981;'>✓ Step 5 & 6 Skipped: Default users, stores, and products catalog were successfully loaded from your local database.sql backup!</div>";
                }

                echo "<br><hr style='border: 1px solid rgba(255,255,255,0.1);'><br>";
                echo "<h3 style='color: #10b981; text-align: center;'>🎉 Installation Complete!</h3>";
                echo "<p style='text-align: center;'>Your local laptop is fully configured, database tables are created, and products are ready!</p>";
                echo "<div style='text-align: center; margin-top: 1.5rem;'>";
                echo "<a href='/OWIPI/' style='display: inline-block; padding: 0.75rem 1.5rem; background: #0284c7; color: white; text-decoration: none; border-radius: 6px; font-weight: bold;'>Go to local OWIPI Dashboard →</a>";
                echo "</div>";
                ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
