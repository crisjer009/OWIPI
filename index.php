<?php
require_once __DIR__ . '/config.php';
checkAuth(false); // Make sure user is logged in

$isSysAdmin = (isset($_SESSION['role']) && $_SESSION['role'] === 'system_admin');
$isAdmin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');
if (!$isSysAdmin && !$isAdmin) {
    header('Location: scan.php');
    exit;
}

$driverLoaded = OWI_DB::isDriverLoaded();
$diagnostics = OWI_DB::getDiagnostics();
$localIP = getServerLocalIP();
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$systemHost = $_SERVER['HTTP_HOST'] ?? $localIP;

// Override loopback addresses with active network IP so cellphones can connect
$hostParts = explode(':', $systemHost);
$hostOnly = $hostParts[0];
if ($hostOnly === 'localhost' || $hostOnly === '127.0.0.1' || $hostOnly === '::1') {
    $systemHost = $localIP;
}

$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
$scriptDir = rtrim($scriptDir, '/');
$scanUrl = $protocol . $systemHost . $scriptDir . "/scan.php?autologin=" . ($_SESSION['user_id'] ?? '') . "&store=" . ($_SESSION['store_code'] ?? '') . "&user=" . urlencode($_SESSION['username'] ?? '');
$config = loadConfig();

// Try to test connection if driver is loaded
$dbStatus = 'disconnected';
$dbError = '';
if ($driverLoaded) {
    try {
        $db = new OWI_DB();
        $db->connect(true);
        $dbStatus = 'connected';
    } catch (Exception $e) {
        $dbStatus = 'error';
        $dbError = $e->getMessage();
    }
}

// Pre-calculate inventory progress tracker metrics for admin dashboard
$storesData = [];
$isSysAdmin = (isset($_SESSION['role']) && $_SESSION['role'] === 'system_admin');
$isAdmin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');

if ($driverLoaded && $dbStatus === 'connected') {
    try {
        $db = new OWI_DB();
        $sql = "SELECT s.id, s.store_code, s.closed, u.username as creator 
                FROM stores s 
                LEFT JOIN users u ON s.created_by = u.id 
                ORDER BY s.store_code ASC";
        $storeRows = $db->query($sql);

        foreach ($storeRows as $row) {
            $code = strtoupper($row['store_code']);
            $clean = strtolower($row['store_code']);

            $totalLocators = 0;
            $closedLocators = 0;
            $percent = 0;
            $status = 'Not Initialized';

            if (isset($row['closed']) && (int) $row['closed'] === 1) {
                $status = 'Closed';
                try {
                    $checkTbl = $db->query("SHOW TABLES LIKE '{$clean}_locators'");
                    if (!empty($checkTbl)) {
                        $totalRows = $db->query("SELECT COUNT(*) as count FROM `{$clean}_locators`");
                        $totalLocators = (int) ($totalRows[0]['count'] ?? 0);
                        if ($totalLocators > 0) {
                            $closedRows = $db->query("SELECT COUNT(*) as count FROM `{$clean}_locators` WHERE status = 'closed'");
                            $closedLocators = (int) ($closedRows[0]['count'] ?? 0);
                            $percent = round(($closedLocators / $totalLocators) * 100);
                        }
                    }
                } catch (Exception $ex) {
                }
            } else {
                try {
                    $checkTbl = $db->query("SHOW TABLES LIKE '{$clean}_locators'");
                    if (!empty($checkTbl)) {
                        $totalRows = $db->query("SELECT COUNT(*) as count FROM `{$clean}_locators`");
                        $totalLocators = (int) ($totalRows[0]['count'] ?? 0);

                        if ($totalLocators > 0) {
                            $closedRows = $db->query("SELECT COUNT(*) as count FROM `{$clean}_locators` WHERE status = 'closed'");
                            $closedLocators = (int) ($closedRows[0]['count'] ?? 0);

                            $percent = round(($closedLocators / $totalLocators) * 100);

                            if ($closedLocators === $totalLocators) {
                                $status = 'Finished';
                            } else {
                                $status = 'Ongoing';
                            }
                        } else {
                            $status = 'Empty';
                        }
                    }
                } catch (Exception $ex) {
                    // Table doesn't exist yet
                }
            }

            $storesData[] = [
                'store_code' => $code,
                'total' => $totalLocators,
                'closed' => $closedLocators,
                'percent' => $percent,
                'status' => $status,
                'creator' => !empty($row['creator']) ? $row['creator'] : 'System'
            ];
        }
    } catch (Exception $e) {
        // Master database error
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OWI Physical Inventory - Gateway Console</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">
    <style>
        :root {
            --bg-color: #0b0f19;
            --card-bg: rgba(17, 24, 39, 0.7);
            --card-border: rgba(255, 255, 255, 0.08);
            --text-primary: #f3f4f6;
            --text-secondary: #9ca3af;
            --accent-color: #3b82f6;
            --accent-glow: rgba(59, 130, 246, 0.35);
            --success-color: #10b981;
            --success-glow: rgba(16, 185, 129, 0.2);
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --danger-glow: rgba(239, 68, 68, 0.2);
            --sidebar-width: 280px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
        }

        body {
            background-color: var(--bg-color);
            background-image:
                radial-gradient(at 0% 0%, rgba(29, 78, 216, 0.15) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(16, 185, 129, 0.08) 0px, transparent 50%);
            background-attachment: fixed;
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            overflow-x: hidden;
        }

        /* Sidebar Styling */
        aside {
            width: var(--sidebar-width);
            background: rgba(17, 24, 39, 0.85);
            border-right: 1px solid var(--card-border);
            padding: 2rem 1.5rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: fixed;
            height: 100vh;
            z-index: 10;
            backdrop-filter: blur(10px);
        }

        .logo-area {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            margin-bottom: 3rem;
        }

        .logo-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--accent-color), #06b6d4);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 0 15px var(--accent-glow);
            flex-shrink: 0;
        }

        .logo-icon svg {
            width: 22px;
            height: 22px;
            fill: white;
        }

        .logo-text {
            font-family: 'Outfit', sans-serif;
            font-size: 1.1rem;
            font-weight: 800;
            line-height: 1.1;
            background: linear-gradient(135deg, #fff, #9ca3af);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            letter-spacing: -0.2px;
        }

        .logo-subtitle {
            font-size: 0.65rem;
            color: var(--accent-color);
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 1.5px;
            margin-top: 1px;
            line-height: 1;
        }

        .nav-menu {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            flex-grow: 1;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.85rem 1rem;
            color: var(--text-secondary);
            text-decoration: none;
            border-radius: 10px;
            font-weight: 500;
            transition: all 0.2s ease;
            cursor: pointer;
        }

        .nav-item:hover,
        .nav-item.active {
            color: white;
            background: rgba(255, 255, 255, 0.05);
        }

        .nav-item.active {
            background: rgba(59, 130, 246, 0.15);
            border: 1px solid rgba(59, 130, 246, 0.3);
            color: white;
        }

        .nav-item svg {
            width: 20px;
            height: 20px;
            fill: currentColor;
        }

        .system-status-widget {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 1rem;
        }

        .widget-title {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-secondary);
            margin-bottom: 0.75rem;
            font-weight: 600;
        }

        .status-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            font-size: 0.85rem;
        }

        .status-row:last-child {
            margin-bottom: 0;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
        }

        .status-badge::before {
            content: '';
            width: 6px;
            height: 6px;
            border-radius: 50%;
            display: inline-block;
        }

        .status-connected {
            background: rgba(16, 185, 129, 0.15);
            color: var(--success-color);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .status-connected::before {
            background: var(--success-color);
        }

        .status-disconnected {
            background: rgba(239, 68, 68, 0.15);
            color: var(--danger-color);
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .status-disconnected::before {
            background: var(--danger-color);
        }

        .status-warning {
            background: rgba(245, 158, 11, 0.15);
            color: var(--warning-color);
            border: 1px solid rgba(245, 158, 11, 0.3);
        }

        .status-warning::before {
            background: var(--warning-color);
        }

        /* Main Content Styling */
        main {
            margin-left: var(--sidebar-width);
            flex-grow: 1;
            padding: 1.5rem;
            max-width: 1400px;
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        h1 {
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            font-size: 2rem;
            letter-spacing: -0.5px;
        }

        .header-desc {
            color: var(--text-secondary);
            font-size: 0.95rem;
            margin-top: 0.25rem;
        }

        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        @media (max-width: 1024px) {
            .grid {
                grid-template-columns: 1fr;
            }
        }

        /* Card Styling */
        .card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            padding: 1.75rem;
            backdrop-filter: blur(20px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            transition: transform 0.3s ease;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            padding-bottom: 0.75rem;
        }

        .card-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.2rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-title svg {
            width: 22px;
            height: 22px;
            fill: var(--accent-color);
        }

        /* Form Controls */
        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-group label {
            display: block;
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--card-border);
            border-radius: 8px;
            padding: 0.75rem 1rem;
            color: white;
            font-size: 0.95rem;
            transition: all 0.2s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--accent-color);
            box-shadow: 0 0 0 3px var(--accent-glow);
            background: rgba(255, 255, 255, 0.05);
        }

        select.form-control {
            background-color: #1e293b;
            color: #ffffff;
            height: 42px !important;
            line-height: 42px !important;
            padding: 0 12px !important;
            box-sizing: border-box;
            cursor: pointer;
        }

        select.form-control option {
            background-color: #0f172a;
            color: #ffffff;
            padding: 8px 12px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            background: var(--accent-color);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 0.75rem 1.5rem;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px var(--accent-glow);
        }

        .btn:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        .btn:active {
            transform: translateY(0);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-primary);
            border: 1px solid var(--card-border);
            box-shadow: none;
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .btn-danger {
            background: var(--danger-color);
            box-shadow: 0 4px 12px var(--danger-glow);
        }

        .btn-success {
            background: var(--success-color);
            box-shadow: 0 4px 12px var(--success-glow);
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        /* QR Code & IP Panel */
        .qr-card-content {
            display: flex;
            align-items: center;
            gap: 2rem;
        }

        @media (max-width: 640px) {
            .qr-card-content {
                flex-direction: column;
                text-align: center;
                gap: 1rem;
            }
        }

        #qrcode {
            background: white;
            padding: 10px;
            border-radius: 12px;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .qr-instructions {
            flex-grow: 1;
        }

        .qr-instructions ol {
            padding-left: 1.2rem;
            margin-top: 0.75rem;
            color: var(--text-secondary);
            font-size: 0.9rem;
            line-height: 1.5;
        }

        .qr-instructions ol li {
            margin-bottom: 0.5rem;
        }

        .ip-badge {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.2);
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-family: monospace;
            font-size: 1.1rem;
            color: #60a5fa;
            display: inline-block;
            margin: 0.5rem 0;
        }

        /* Table Design */
        .table-container {
            overflow-x: auto;
            margin-top: 1.5rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 0.9rem;
        }

        th {
            background: rgba(255, 255, 255, 0.02);
            color: var(--text-secondary);
            font-weight: 600;
            padding: 1rem;
            border-bottom: 1px solid var(--card-border);
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.5px;
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.04);
            color: var(--text-primary);
        }

        tr:hover td {
            background: rgba(255, 255, 255, 0.01);
        }

        .empty-state {
            padding: 3rem;
            text-align: center;
            color: var(--text-secondary);
        }

        .empty-state svg {
            width: 48px;
            height: 48px;
            fill: var(--text-secondary);
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* Diagnostic & Missing Driver alert box */
        .alert-box {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 2rem;
        }

        .alert-box-title {
            color: #fca5a5;
            font-weight: 600;
            font-size: 1.05rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
        }

        .alert-box-title svg {
            width: 20px;
            height: 20px;
            fill: currentColor;
        }

        .alert-box p {
            color: #fca5a5;
            font-size: 0.9rem;
            line-height: 1.5;
            margin-bottom: 1rem;
        }

        .troubleshoot-guide {
            background: rgba(0, 0, 0, 0.25);
            border-radius: 8px;
            padding: 1rem;
            font-size: 0.85rem;
            color: var(--text-primary);
            border: 1px solid rgba(255, 255, 255, 0.04);
        }

        .troubleshoot-guide h4 {
            font-weight: 600;
            color: var(--accent-color);
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .troubleshoot-guide ol {
            padding-left: 1.2rem;
            line-height: 1.6;
        }

        .troubleshoot-guide ol li {
            margin-bottom: 0.5rem;
        }

        .code-block {
            background: #010409;
            color: #ff7b72;
            padding: 0.5rem;
            border-radius: 6px;
            font-family: monospace;
            font-size: 0.8rem;
            display: block;
            margin-top: 0.25rem;
            overflow-x: auto;
            border: 1px solid #30363d;
        }

        .system-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
            padding: 0.75rem;
            background: rgba(255, 255, 255, 0.02);
            border-radius: 8px;
            border: 1px solid var(--card-border);
        }

        .info-cell {
            font-size: 0.8rem;
        }

        .info-cell span {
            display: block;
            color: var(--text-secondary);
            font-size: 0.7rem;
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 0.15rem;
        }

        /* Toast Notifications */
        .toast {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            background: rgba(17, 24, 39, 0.9);
            border: 1px solid var(--card-border);
            padding: 1rem 1.5rem;
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            z-index: 100;
            transform: translateY(150%);
            transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            backdrop-filter: blur(10px);
        }

        .toast.show {
            transform: translateY(0);
        }

        .toast-success {
            border-left: 4px solid var(--success-color);
        }

        .toast-error {
            border-left: 4px solid var(--danger-color);
        }

        .toast-info {
            border-left: 4px solid var(--accent-color);
        }

        .toast-icon svg {
            width: 20px;
            height: 20px;
        }

        .toast-success .toast-icon svg {
            fill: var(--success-color);
        }

        .toast-error .toast-icon svg {
            fill: var(--danger-color);
        }

        .toast-info .toast-icon svg {
            fill: var(--accent-color);
        }

        .toast-msg {
            font-size: 0.9rem;
            font-weight: 500;
        }

        /* Views (tabs) control */
        .view-content {
            display: none;
        }

        .view-content.active {
            display: block;
        }

        .badge {
            background: rgba(255, 255, 255, 0.08);
            border-radius: 4px;
            padding: 0.15rem 0.4rem;
            font-size: 0.75rem;
            font-weight: 600;
        }

        /* Compact overrides specifically for Product Catalog Widescreen View */
        #view-products .card {
            padding: 1.15rem;
            height: 100%;
            display: flex;
            flex-direction: column;
            margin: 0;
            min-height: 0;
        }

        #view-products .card-header {
            margin-bottom: 0.75rem;
            padding-bottom: 0.5rem;
            flex-shrink: 0;
        }

        #view-products form {
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        #view-products .form-group {
            margin-bottom: 0.65rem;
        }

        #view-products .form-control {
            height: 38px;
            padding: 0 0.85rem;
            font-size: 0.85rem;
        }

        #view-products table {
            border-collapse: collapse;
            width: 100%;
        }

        #view-products table th {
            position: sticky;
            top: 0;
            background: #151b2d;
            z-index: 2;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-bottom: 2px solid var(--card-border);
            padding: 0.45rem 0.6rem;
            font-size: 0.82rem;
        }

        #view-products table td {
            border: 1px solid rgba(255, 255, 255, 0.08);
            padding: 0.45rem 0.6rem;
            font-size: 0.82rem;
            vertical-align: middle;
        }

        #view-products .table-container {
            margin-top: 0.5rem;
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            overflow-x: hidden;
            border: 1px solid var(--card-border);
            border-radius: 8px;
            padding-right: 8px;
        }

        .products-grid {
            display: grid;
            grid-template-columns: 1.3fr 1fr 0.9fr;
            gap: 1.5rem;
            margin-bottom: 1rem;
            height: calc(100vh - 220px);
            min-height: 350px;
        }

        @media (max-width: 1024px) {
            body {
                flex-direction: column;
            }

            aside {
                position: relative;
                width: 100%;
                height: auto;
                padding: 1rem;
                border-right: none;
                border-bottom: 1px solid var(--card-border);
            }

            .logo-area {
                margin-bottom: 1.5rem;
                justify-content: center;
            }

            .nav-menu {
                flex-direction: row;
                flex-wrap: wrap;
                justify-content: center;
                gap: 0.5rem;
            }

            .nav-item {
                padding: 0.5rem 0.75rem;
                font-size: 0.8rem;
            }

            main {
                margin-left: 0;
                padding: 1rem;
                width: 100%;
            }

            .system-status-widget {
                display: none;
            }

            .products-grid {
                grid-template-columns: 1fr;
                height: auto;
                min-height: 0;
            }
        }
    </style>
</head>

<body>

    <style>
        .store-selector-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(11, 15, 25, 0.95);
            backdrop-filter: blur(15px);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .store-selector-card {
            width: 100%;
            max-width: 440px;
            background: rgba(17, 24, 39, 0.85);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 20px;
            padding: 2.5rem;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
        }

        select.form-control {
            height: 48px;
            padding: 0 1.1rem;
            appearance: none;
            -webkit-appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='white'%3e%3cpath d='M7 10l5 5 5-5z'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 20px;
            line-height: 48px;
            color: white;
            background-color: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 10px;
            width: 100%;
        }

        select.form-control option {
            background: #111827;
            color: white;
        }
    </style>
    <div class="store-selector-overlay" id="store-select-overlay" style="display: none;">
        <div class="store-selector-card">
            <div style="text-align:center; margin-bottom: 2rem;">
                <div
                    style="width:50px; height:50px; background:linear-gradient(135deg, var(--accent-color), #06b6d4); border-radius:14px; display:inline-flex; align-items:center; justify-content:center; box-shadow:0 0 20px var(--accent-glow); margin-bottom:1rem;">
                    <svg viewBox="0 0 24 24" style="width:26px;height:26px;fill:white;">
                        <path
                            d="M12 2C6.48 2 2 4.02 2 6.5v11c0 2.48 4.48 4.5 10 4.5s10-2.02 10-4.5v-11C22 4.02 17.52 2 12 2zm0 18c-4.41 0-8-1.79-8-4v-2.15c1.99.96 4.79 1.65 8 1.65s6.01-.69 8-1.65V16c0 2.21-3.59 4-8 4z" />
                    </svg>
                </div>
                <h2 style="font-family:'Outfit',sans-serif; font-size:1.4rem; font-weight:700; margin-bottom:0.25rem;">
                    Select Store Session</h2>
                <p style="color:var(--text-secondary); font-size:0.85rem;">Select an existing store or create a new
                    dynamic virtual database.</p>
            </div>

            <form onsubmit="handleSelectStore(event)" id="store-select-form">
                <div class="form-group" id="existing-stores-group" style="display:none;">
                    <label for="active_store_select">Choose Existing Store</label>
                    <select id="active_store_select" class="form-control" style="margin-bottom: 1rem;">
                        <!-- Dynamically populated -->
                    </select>
                </div>

                <div class="form-group" id="new-store-group">
                    <div style="margin-bottom: 1rem;">
                        <label for="active_store_input" id="store-input-label">Create / Connect New Store Code</label>
                        <input type="text" id="active_store_input" class="form-control" placeholder="e.g. TES, HQ, CEBU"
                            style="text-transform: uppercase;" autocomplete="off">
                    </div>
                    <div>
                        <label for="active_store_locators">Number of Locators Needed</label>
                        <input type="number" id="active_store_locators" class="form-control" min="1" max="1000"
                            placeholder="e.g. 10" value="10">
                    </div>
                </div>

                <button type="submit" class="btn" style="width:100%; margin-top: 1rem;">Activate Store Session</button>

                <div
                    style="text-align:center; margin-top: 1.25rem; display:flex; justify-content:space-between; align-items:center;">
                    <a href="javascript:void(0)" onclick="toggleStoreInputMode()"
                        style="font-size:0.8rem; color:var(--accent-color); text-decoration:none; font-weight:600;"
                        id="toggle-store-mode-btn">Choose existing</a>
                    <a href="javascript:void(0)" onclick="closeStoreSelector()"
                        style="font-size:0.8rem; color:var(--text-secondary); text-decoration:none; font-weight:600;">Skip
                        for now ✕</a>
                </div>
            </form>
            <div
                style="margin-top: 1.5rem; border-top: 1px dashed rgba(255,255,255,0.1); padding-top: 1rem; text-align: center;">
                <a href="javascript:void(0)" onclick="openCloudStoreDownloader()"
                    style="font-size:0.85rem; color:var(--success-color); text-decoration:none; font-weight:700; display: inline-flex; align-items: center; gap: 0.25rem; cursor: pointer;">
                    ☁️ Download Store from Cloud
                </a>
            </div>
        </div>
    </div>

    <!-- Cloud Store Downloader Overlay -->
    <div class="store-selector-overlay" id="cloud-store-download-overlay" style="display: none;">
        <div class="store-selector-card">
            <div style="text-align:center; margin-bottom: 2rem;">
                <div
                    style="width:50px; height:50px; background:linear-gradient(135deg, var(--success-color), #059669); border-radius:14px; display:inline-flex; align-items:center; justify-content:center; box-shadow:0 0 20px rgba(16, 185, 129, 0.35); margin-bottom:1rem;">
                    <svg viewBox="0 0 24 24" style="width:26px;height:26px;fill:white;">
                        <path
                            d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM17 13l-5 5-5-5h3V9h4v4h3z" />
                    </svg>
                </div>
                <h2 style="font-family:'Outfit',sans-serif; font-size:1.4rem; font-weight:700; margin-bottom:0.25rem;">
                    Download Store from Cloud</h2>
                <p style="color:var(--text-secondary); font-size:0.85rem;">Fetch active store sessions and templates
                    configured on the cloud server.</p>
            </div>

            <form onsubmit="handleImportCloudStore(event)" id="cloud-store-download-form">
                <div class="form-group" id="cloud-stores-select-container">
                    <label for="cloud_store_select">Select Cloud Store Session</label>
                    <select id="cloud_store_select" class="form-control" style="margin-bottom: 1rem;" required>
                        <option value="">Loading active stores from cloud...</option>
                    </select>
                </div>

                <button type="submit" class="btn" id="btn-import-cloud-store"
                    style="width:100%; margin-top: 1rem; background: var(--success-color); border-color: var(--success-color);">Download
                    & Import Store</button>

                <div style="text-align:center; margin-top: 1.25rem;">
                    <a href="javascript:void(0)" onclick="closeCloudStoreDownloader()"
                        style="font-size:0.8rem; color:var(--text-secondary); text-decoration:none; font-weight:600;">Cancel
                        ✕</a>
                </div>
            </form>
        </div>
    </div>
    <script>
        let storeInputMode = 'create';

        window.addEventListener('DOMContentLoaded', () => {
            fetchExistingStores();
            fetchPendingSyncRequests();
        });

        function fetchPendingSyncRequests() {
            const listEl = document.getElementById('pending-syncs-list');
            const cardEl = document.getElementById('pending-syncs-card');
            if (!listEl) return;

            fetch('api.php?action=get_pending_syncs')
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success' && data.requests && data.requests.length > 0) {
                        if (cardEl) cardEl.style.display = 'block';
                        let html = '<div style="display: flex; flex-direction: column; gap: 0.75rem;">';
                        data.requests.forEach(req => {
                            html += `
                                <div style="background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; padding: 1rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                                    <div>
                                        <div style="font-weight: 700; font-size: 1rem; color: var(--accent-color);">Store: ${req.store_code.toUpperCase()}</div>
                                        <div style="font-size: 0.8rem; color: var(--text-secondary); margin-top: 2px;">
                                            Requested by: <strong>${req.requested_by}</strong> • ${req.created_at}
                                        </div>
                                        <div style="font-size: 0.8rem; color: #9ca3af; margin-top: 4px;">
                                            Local Scans: <strong style="color:white;">${req.local_scans_count}</strong> | Cloud Scans: <strong style="color:white;">${req.cloud_scans_count}</strong>
                                        </div>
                                    </div>
                                    <div style="display: flex; gap: 0.5rem;">
                                        <button type="button" onclick="approveSyncRequest(${req.id})" class="btn" style="background: var(--success-color); color: white; width: auto; font-size: 0.8rem; padding: 6px 14px; font-weight: 600; cursor: pointer;">Approve Overwrite</button>
                                        <button type="button" onclick="rejectSyncRequest(${req.id})" class="btn btn-secondary" style="background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid #ef4444; width: auto; font-size: 0.8rem; padding: 6px 14px; font-weight: 600; cursor: pointer;">Reject</button>
                                    </div>
                                </div>
                            `;
                        });
                        html += '</div>';
                        listEl.innerHTML = html;
                    } else {
                        listEl.innerHTML = '<p style="color: var(--text-muted); font-size: 0.85rem; font-style: italic;">No pending sync requests awaiting approval.</p>';
                    }
                })
                .catch(err => {
                    listEl.innerHTML = '<p style="color: #ef4444; font-size: 0.85rem;">Failed to load pending sync requests.</p>';
                });
        }

        function dismissPendingSyncCard() {
            const cardEl = document.getElementById('pending-syncs-card');
            if (cardEl) cardEl.style.display = 'none';
        }

        async function approveSyncRequest(id) {
            const ok = await showCustomConfirm(
                "Are you sure you want to approve this store sync request and overwrite cloud data?",
                "Approve Sync Request",
                "Approve & Sync",
                "Cancel"
            );
            if (!ok) return;

            fetch('api.php?action=approve_sync_request', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `id=${id}`
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showToast(data.message, 'success');
                        fetchPendingSyncRequests();
                    } else {
                        showCustomAlert("Approval failed: " + data.message, "Approval Failed");
                    }
                })
                .catch(err => showCustomAlert("Request failed: " + err, "Network Error"));
        }

        async function rejectSyncRequest(id) {
            const ok = await showCustomConfirm(
                "Are you sure you want to reject this sync request?",
                "Reject Sync Request",
                "Reject Request",
                "Cancel"
            );
            if (!ok) return;

            fetch('api.php?action=reject_sync_request', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `id=${id}`
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showToast("Sync request rejected.", 'info');
                        fetchPendingSyncRequests();
                    } else {
                        showCustomAlert("Rejection failed: " + data.message, "Rejection Failed");
                    }
                })
                .catch(err => showCustomAlert("Request failed: " + err, "Network Error"));
        }

        function fetchExistingStores() {
            fetch('api.php?action=get_stores')
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success' && data.stores && data.stores.length > 0) {
                        const select = document.getElementById('active_store_select');
                        const targetSelect = document.getElementById('masterfile_target_store');
                        if (select) select.innerHTML = '';
                        if (targetSelect) {
                            targetSelect.innerHTML = '<option value="">Global Master Catalog (Default items table)</option>';
                        }
                        data.stores.forEach(store => {
                            if (select) {
                                const opt = document.createElement('option');
                                opt.value = store.store_code;
                                opt.innerText = `Store: ${store.store_code}`;
                                select.appendChild(opt);
                            }

                            if (targetSelect) {
                                const targetOpt = document.createElement('option');
                                targetOpt.value = store.store_code;
                                targetOpt.innerText = `Store: ${store.store_code} (${store.store_code.toLowerCase()}_items)`;
                                targetSelect.appendChild(targetOpt);
                            }
                        });
                        setStoreMode('select');
                        const toggleContainer = document.getElementById('toggle-store-mode-container');
                        if (toggleContainer) toggleContainer.style.display = 'block';
                    } else {
                        setStoreMode('create');
                        const toggleContainer = document.getElementById('toggle-store-mode-container');
                        if (toggleContainer) toggleContainer.style.display = 'none';
                        const toggleBtn = document.getElementById('toggle-store-mode-btn');
                        if (toggleBtn) toggleBtn.style.display = 'none';
                    }
                })
                .catch(err => console.error("Error loading stores:", err));
        }

        function openCloudStoreDownloader() {
            // Close the main selector first if it is open
            closeStoreSelector();

            // Show our download overlay
            document.getElementById('cloud-store-download-overlay').style.display = 'flex';

            const selectEl = document.getElementById('cloud_store_select');
            selectEl.innerHTML = '<option value="">Loading active stores from cloud...</option>';

            fetch('api.php?action=fetch_cloud_stores')
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        if (data.stores && data.stores.length > 0) {
                            let html = '<option value="">-- Choose a store --</option>';
                            data.stores.forEach(s => {
                                const statusTxt = parseInt(s.closed) === 1 ? 'CLOSED' : 'ONGOING';
                                html += `<option value="${s.store_code}">${s.store_code.toUpperCase()} (${statusTxt})</option>`;
                            });
                            selectEl.innerHTML = html;
                        } else {
                            selectEl.innerHTML = '<option value="">No store sessions found on cloud.</option>';
                        }
                    } else {
                        showToast(data.message || 'Failed to fetch cloud stores.', 'error');
                        selectEl.innerHTML = `<option value="">Error: ${data.message || 'Failed to fetch'}</option>`;
                    }
                })
                .catch(err => {
                    showToast('Failed to fetch stores: ' + err, 'error');
                    selectEl.innerHTML = `<option value="">Error: Connection failed</option>`;
                });
        }

        function closeCloudStoreDownloader() {
            document.getElementById('cloud-store-download-overlay').style.display = 'none';
        }

        function handleImportCloudStore(event) {
            event.preventDefault();
            const storeCode = document.getElementById('cloud_store_select').value;
            if (!storeCode) {
                showCustomAlert('Please select a store to download.', 'Selection Required');
                return;
            }

            const btn = document.getElementById('btn-import-cloud-store');
            btn.disabled = true;
            btn.innerText = 'Downloading store...';

            showToast(`Downloading '${storeCode.toUpperCase()}' from cloud...`, 'info');

            fetch(`api.php?action=import_cloud_store&store_code=${encodeURIComponent(storeCode)}`)
                .then(res => res.json())
                .then(data => {
                    btn.disabled = false;
                    btn.innerText = 'Download & Import Store';

                    if (data.status === 'success') {
                        showToast(data.message, 'success');
                        closeCloudStoreDownloader();
                        setTimeout(() => window.location.reload(), 1500);
                    } else {
                        showToast(data.message, 'error');
                    }
                })
                .catch(err => {
                    btn.disabled = false;
                    btn.innerText = 'Download & Import Store';
                    showToast('Import failed: ' + err, 'error');
                });
        }

        function setStoreMode(mode) {
            storeInputMode = mode;
            const selectGroup = document.getElementById('existing-stores-group');
            const inputGroupContainer = document.getElementById('new-store-group');
            const inputField = document.getElementById('active_store_input');
            const btn = document.getElementById('toggle-store-mode-btn');

            if (mode === 'select') {
                if (selectGroup) selectGroup.style.display = 'block';
                if (inputGroupContainer) inputGroupContainer.style.display = 'none';
                if (inputField) inputField.required = false;
                if (btn) btn.innerText = "Or Create New Store";
            } else {
                if (selectGroup) selectGroup.style.display = 'none';
                if (inputGroupContainer) inputGroupContainer.style.display = 'block';
                if (inputField) inputField.required = true;
                if (btn) btn.innerText = "Choose from existing stores";
            }
        }

        function toggleStoreInputMode() {
            setStoreMode(storeInputMode === 'select' ? 'create' : 'select');
        }

        function openStoreSelector() {
            document.getElementById('store-select-overlay').style.display = 'flex';
            fetchExistingStores();
        }

        function closeStoreSelector() {
            document.getElementById('store-select-overlay').style.display = 'none';
        }

        function handleSelectStore(event) {
            event.preventDefault();
            let storeCode = '';
            let locatorsCount = 0;

            if (storeInputMode === 'select') {
                storeCode = document.getElementById('active_store_select').value;
            } else {
                storeCode = document.getElementById('active_store_input').value.trim();
                locatorsCount = parseInt(document.getElementById('active_store_locators').value) || 10;
            }

            if (storeCode === '') {
                alert("Please enter or select a Store Code.");
                return;
            }

            fetch('api.php?action=select_store', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    store_code: storeCode,
                    locators_count: locatorsCount,
                    mode: storeInputMode
                })
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        window.location.reload();
                    } else {
                        alert("Error: " + data.message);
                    }
                })
                .catch(err => alert("Request failed: " + err));
        }
    </script>

    <!-- Sidebar -->
    <aside>
        <div>
            <div class="logo-area">
                <div class="logo-icon">
                    <svg viewBox="0 0 24 24">
                        <path
                            d="M4 4h4v4H4V4zm6 0h4v4h-4V4zm6 0h4v4h-4V4zM4 10h4v4H4v-4zm6 0h4v4h-4v-4zm6 0h4v4h-4v-4zM4 16h4v4H4v-4zm6 0h4v4h-4v-4zm6 0h4v4h-4v-4z" />
                    </svg>
                </div>
                <div>
                    <div class="logo-text">OWI PHYSICAL</div>
                    <div class="logo-subtitle">STORE [<?= htmlspecialchars($_SESSION['store_code'] ?? 'NONE') ?>]</div>
                </div>
            </div>

            <nav class="nav-menu">
                <div class="nav-item active" onclick="switchView('dashboard', this)">
                    <svg viewBox="0 0 24 24">
                        <path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z" />
                    </svg>
                    Dashboard
                </div>
                <?php if ($isSysAdmin): ?>
                    <div class="nav-item" onclick="switchView('database', this)">
                        <svg viewBox="0 0 24 24">
                            <path
                                d="M12 2C6.48 2 2 4.02 2 6.5v11c0 2.48 4.48 4.5 10 4.5s10-2.02 10-4.5v-11C22 4.02 17.52 2 12 2zm0 18c-4.41 0-8-1.79-8-4v-2.15c1.99.96 4.79 1.65 8 1.65s6.01-.69 8-1.65V16c0 2.21-3.59 4-8 4zm0-6c-4.41 0-8-1.79-8-4v-2.15c1.99.96 4.79 1.65 8 1.65s6.01-.69 8-1.65V10c0 2.21-3.59 4-8 4zm0-6c-4.41 0-8-1.79-8-4s3.59-4 8-4 8 1.79 8 4-3.59 4-8 4z" />
                        </svg>
                        MySQL Setup
                    </div>
                <?php endif; ?>
                <div class="nav-item" onclick="switchView('checker', this)">
                    <svg viewBox="0 0 24 24" style="width:20px;height:20px;fill:currentColor;">
                        <path d="M3 5h2v14H3zm4 0h1v14H7zm3 0h2v14h-2zm4 0h1v14h-1zm3 0h3v14h-3zm-9 16h12v2H7z" />
                    </svg>
                    Test Scan Checker
                </div>
                <div class="nav-item" onclick="switchView('products', this)">
                    <svg viewBox="0 0 24 24">
                        <path
                            d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 17h-2v-2h2v2zm2.07-7.75l-.9.92C13.45 12.9 13 13.5 13 15h-2v-.5c0-1.1.45-2.1 1.17-2.83l1.24-1.26c.37-.36.59-.86.59-1.41 0-1.1-.9-2-2-2s-2 .9-2 2H7c0-2.76 2.24-5 5-5s5 2.24 5 5c0 1.04-.42 1.99-1.07 2.75z" />
                    </svg>
                    Items Masterfile
                </div>
                <?php if ($isSysAdmin || $isAdmin): ?>
                    <div class="nav-item" onclick="switchView('users', this)">
                        <svg viewBox="0 0 24 24" style="width:20px;height:20px;fill:currentColor;">
                            <path
                                d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z" />
                        </svg>
                        User Accounts
                    </div>
                    <div class="nav-item" onclick="switchView('audit', this)">
                        <svg viewBox="0 0 24 24" style="width:20px;height:20px;fill:currentColor;">
                            <path
                                d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z" />
                        </svg>
                        Audit Logs
                    </div>
                <?php endif; ?>
                <a class="nav-item" href="scan.php" target="_blank">
                    <svg viewBox="0 0 24 24">
                        <path
                            d="M4 4h7V2H4c-1.1 0-2 .9-2 2v7h2V4zm6 9l-4 4h3v5h2v-5h3l-4-4zm10-9v7h2V4c0-1.1-.9-2-2-2h-7v2h7zM14 17h3v5h2v-5h3l-4-4-4 4z" />
                    </svg>
                    Open Phone Scanner ↗
                </a>
                <a class="nav-item" href="logout.php" style="color: var(--danger-color); margin-top: 1rem;">
                    <svg viewBox="0 0 24 24" style="fill: var(--danger-color);">
                        <path
                            d="M16 17v-3H9v-4h7V7l5 5-5 5M14 2a2 2 0 0 0-2 2v6h2V4h8v16h-8v-6h-2v6a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2h-8z" />
                    </svg>
                    Log Out
                </a>
            </nav>
        </div>

        <?php if ($isSysAdmin): ?>
            <!-- Connection Widget -->
            <div class="system-status-widget">
                <div class="widget-title">User Session</div>
                <div
                    style="font-size: 0.85rem; margin-bottom: 0.75rem; color: var(--accent-color); font-weight: 600; display: flex; align-items: center; gap: 0.4rem;">
                    <svg viewBox="0 0 24 24" style="width:16px;height:16px;fill:currentColor;">
                        <path
                            d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z" />
                    </svg>
                    <?= htmlspecialchars($_SESSION['username'] ?? 'Admin') ?>
                </div>
                <div class="status-row" style="font-size: 0.8rem; margin-bottom: 0.75rem;">
                    <span>Active Store:</span>
                    <div style="display:flex; align-items:center; gap:6px;">
                        <span class="badge"
                            style="color:var(--success-color); background:rgba(16,185,129,0.1); font-weight:700;"><?= htmlspecialchars($_SESSION['store_code'] ?? '') ?></span>
                        <a href="javascript:void(0)" onclick="logoutStore()"
                            style="color:var(--accent-color); font-size:0.7rem; text-decoration:none; font-weight:600;">[Switch]</a>
                    </div>
                </div>
                <div class="widget-title">Gateway Status</div>
                <div class="status-row">
                    <span>MySQL DB:</span>
                    <span id="sidebar-db-status" class="status-badge status-<?= $dbStatus ?>">
                        <?= ucfirst($dbStatus) ?>
                    </span>
                </div>
                <div class="status-row">
                    <span>PHP Driver:</span>
                    <span class="status-badge <?= $driverLoaded ? 'status-connected' : 'status-disconnected' ?>">
                        <?= $driverLoaded ? 'PDO MySQL' : 'Missing' ?>
                    </span>
                </div>
                <div class="status-row" style="margin-top: 0.5rem; font-size: 0.75rem; color: var(--text-secondary);">
                    <span>IP: <?= htmlspecialchars($localIP) ?></span>
                </div>
            </div>
        <?php endif; ?>
    </aside>

    <!-- Main Content Area -->
    <main>

        <!-- View: Dashboard -->
        <div id="view-dashboard" class="view-content active">
            <?php if ($isSysAdmin): ?>
                <header>
                    <div>
                        <h1>Control Dashboard</h1>
                        <div class="header-desc">System configuration, integrations, and server status.</div>
                    </div>
                </header>

                <!-- Diagnostics Alert if Drivers are Missing (Extremely rare in XAMPP) -->
                <?php if (!$driverLoaded): ?>
                    <div class="alert-box">
                        <div class="alert-box-title">
                            <svg viewBox="0 0 24 24">
                                <path
                                    d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z" />
                            </svg>
                            MySQL PHP Driver Missing
                        </div>
                        <p>
                            Your XAMPP installation is missing the MySQL database drivers for PHP (<code>pdo_mysql</code>).
                        </p>
                        <div class="troubleshoot-guide">
                            <h4>🔧 How to enable MySQL PDO:</h4>
                            <ol>
                                <li>Open your PHP configuration file (<code>C:\xampp\php\php.ini</code>).</li>
                                <li>Search for <code>;extension=pdo_mysql</code>.</li>
                                <li>Remove the semicolon (<code>;</code>) to uncomment and enable it:
                                    <span class="code-block">extension=pdo_mysql</span>
                                </li>
                                <li><strong>Restart your Apache server</strong> in the XAMPP Control Panel. Then reload this
                                    page.</li>
                            </ol>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- MySQL Integration Info Panel -->
                <div class="card" style="max-width: 600px; margin-bottom: 2rem;">
                    <div class="card-header">
                        <h2 class="card-title">
                            <svg viewBox="0 0 24 24">
                                <path
                                    d="M19.43 12.98c.04-.32.07-.64.07-.98s-.03-.66-.07-.98l2.11-1.65c.19-.15.24-.42.12-.64l-2-3.46c-.12-.22-.39-.3-.61-.22l-2.49 1c-.52-.4-1.08-.73-1.69-.98l-.38-2.65C14.46 2.18 14.25 2 14 2h-4c-.25 0-.46.18-.49.42l-.38 2.65c-.61.25-1.17.59-1.69.98l-2.49-1c-.23-.09-.49 0-.61.22l-2 3.46c-.13.22-.07.49.12.64l2.11 1.65c-.04.32-.07.65-.07.98s.03.66.07.98l-2.11 1.65c-.19.15-.24.42-.12.64l2 3.46c.12.22.39.3.61.22l2.49-1c.52.4 1.08.73-1.69-.98l.38 2.65c.03.24.24.42.49.42h4c.25 0 .46-.18.49-.42l.38-2.65c.61-.25 1.17-.59 1.69-.98l2.49 1c.23.09.49 0 .61-.22l2-3.46c.12-.22.07-.49-.12-.64l-2.11-1.65zM12 15.5c-1.93 0-3.5-1.57-3.5-3.5s1.57-3.5 3.5-3.5 3.5 1.57 3.5 3.5-1.57 3.5-3.5 3.5z" />
                            </svg>
                            MySQL (OWI PHYSICAL INVENTORY) Integration
                        </h2>
                    </div>
                    <div style="font-size: 0.9rem; line-height: 1.6;">
                        <p style="margin-bottom: 0.75rem; color: var(--text-secondary);">
                            The system database integration status and active parameters.
                        </p>
                        <div
                            style="background: rgba(0,0,0,0.15); border-radius: 8px; padding: 1rem; border: 1px solid var(--card-border);">
                            <div style="margin-bottom: 0.4rem;"><strong>Host Host/IP:</strong> <span
                                    class="badge"><?= htmlspecialchars($config['server']) ?>:<?= htmlspecialchars($config['port'] ?? '3306') ?></span>
                            </div>
                            <div style="margin-bottom: 0.4rem;"><strong>Database Name:</strong> <span
                                    class="badge"><?= htmlspecialchars($config['database']) ?></span></div>
                            <div style="margin-bottom: 0.4rem;"><strong>MySQL User:</strong> <span
                                    class="badge"><?= htmlspecialchars($config['username']) ?></span></div>
                            <?php if ($dbStatus === 'connected'): ?>
                                <div
                                    style="color: var(--success-color); font-weight: 600; margin-top: 0.75rem; display: flex; align-items: center; gap: 0.25rem;">
                                    <span style="font-size: 1.25rem; line-height: 1;">●</span> Gateway successfully connected to
                                    MySQL.
                                </div>
                            <?php else: ?>
                                <div
                                    style="color: var(--danger-color); font-weight: 600; margin-top: 0.75rem; display: flex; align-items: center; gap: 0.25rem;">
                                    <span style="font-size: 1.25rem; line-height: 1;">●</span> DB Connection Error:
                                    <?= htmlspecialchars($dbError) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div style="margin-top: 1rem; display: flex; flex-wrap: wrap; gap: 1rem;">
                            <button onclick="switchView('database')" class="btn btn-secondary btn-sm"
                                style="padding: 0.5rem 1rem; font-size: 0.85rem;">Modify Config</button>
                            <?php if ($driverLoaded): ?>
                                <button onclick="initializeDatabase()" class="btn btn-success btn-sm"
                                    style="padding: 0.5rem 1rem; font-size: 0.85rem;">Initialize DB Tables</button>
                                <button onclick="restoreDatabaseBackup()" class="btn btn-danger btn-sm"
                                    style="padding: 0.5rem 1rem; font-size: 0.85rem; background: #d73a49; border-color: #cb2431;">Import
                                    database.sql Backup</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <header style="margin-top: <?= $isSysAdmin ? '2rem' : '0' ?>;">
                <div>
                    <h1>Store Inventory Progress</h1>
                    <div class="header-desc">Real-time locator completion metrics across all your active store
                        databases.</div>
                </div>
            </header>

            <!-- Pending Cloud Sync Approvals Card for Admins -->
            <?php if (in_array($_SESSION['role'] ?? '', ['system_admin', 'admin'])): ?>
                <div class="card" style="max-width: 600px; margin-top: 1.25rem; margin-bottom: 1.5rem; border: 1px solid rgba(234, 179, 8, 0.35); background: rgba(234, 179, 8, 0.04); border-radius: 12px; position: relative;" id="pending-syncs-card">
                    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 0.75rem; border-bottom: 1px solid rgba(255,255,255,0.08);">
                        <h2 class="card-title" style="font-size: 1.05rem; display: flex; align-items: center; gap: 8px; margin: 0; font-weight: 700; color: white;">
                            <span style="display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 50%; background: #eab308; color: #000; font-size: 0.85rem; font-weight: 900;">!</span>
                            <span>Pending Cloud Sync Approvals</span>
                        </h2>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <button type="button" onclick="fetchPendingSyncRequests()" class="btn btn-secondary btn-sm"
                                style="width: auto; font-size: 0.8rem; padding: 4px 12px; cursor: pointer; border-radius: 6px;">Refresh</button>
                            <button type="button" onclick="dismissPendingSyncCard()" style="background: none; border: none; color: #8b949e; font-size: 1.3rem; cursor: pointer; padding: 0 6px; line-height: 1; border-radius: 4px;" title="Dismiss">&times;</button>
                        </div>
                    </div>
                    <div id="pending-syncs-list" style="margin-top: 1rem;">
                        <p style="color: var(--text-secondary); font-size: 0.85rem; font-style: italic;">Checking for pending sync requests...
                        </p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (empty($storesData)): ?>
                <div class="card" style="max-width: 600px; padding: 2.5rem; text-align: center;">
                    <div
                        style="width: 56px; height: 56px; background: rgba(245, 158, 11, 0.1); border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 1rem;">
                        <svg viewBox="0 0 24 24" style="width: 30px; height: 30px; fill: var(--warning-color);">
                            <path
                                d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z" />
                        </svg>
                    </div>
                    <h3
                        style="font-family:'Outfit', sans-serif; font-size:1.25rem; font-weight:700; margin-bottom: 0.5rem;">
                        No Stores Found</h3>
                    <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 1.5rem;">
                        You haven't created or connected any stores yet. Choose / Create a store to start.
                    </p>
                    <button onclick="openStoreSelector()" class="btn"
                        style="width: auto; padding: 0.65rem 1.25rem; font-size: 0.85rem; font-weight: 600;">
                        Create / Select Store Session
                    </button>
                    <button onclick="openCloudStoreDownloader()" class="btn btn-secondary"
                        style="width: auto; padding: 0.65rem 1.25rem; font-size: 0.85rem; font-weight: 600; margin-left: 10px; border: 1px solid var(--success-color); color: var(--success-color); background: rgba(16, 185, 129, 0.1);">
                        ☁️ Download Store from Cloud
                    </button>
                </div>
            <?php else: ?>
                <div
                    style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; margin-top: 1rem;">
                    <?php foreach ($storesData as $s): ?>
                        <?php
                        $statusColor = 'var(--text-secondary)';
                        $statusBg = 'rgba(255,255,255,0.05)';
                        if ($s['status'] === 'Closed') {
                            $statusColor = '#f85149';
                            $statusBg = 'rgba(248, 81, 73, 0.1)';
                        } elseif ($s['status'] === 'Finished') {
                            $statusColor = 'var(--success-color)';
                            $statusBg = 'rgba(16, 185, 129, 0.1)';
                        } elseif ($s['status'] === 'Ongoing') {
                            $statusColor = 'var(--accent-color)';
                            $statusBg = 'rgba(59, 130, 246, 0.1)';
                        }
                        ?>
                        <div class="card"
                            style="display: flex; flex-direction: column; justify-content: space-between; padding: 1.5rem; min-height: 180px;">
                            <div>
                                <div
                                    style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem;">
                                    <h3
                                        style="font-family: 'Outfit', sans-serif; font-size: 1.3rem; font-weight: 800; letter-spacing: -0.5px; color: var(--text-primary); margin: 0;">
                                        <?= htmlspecialchars($s['store_code']) ?>
                                        <div
                                            style="font-size: 0.75rem; color: var(--text-secondary); font-weight: 500; margin-top: 4px; letter-spacing: 0;">
                                            Created by: <span
                                                style="color: var(--accent-color); font-weight: 600;"><?= htmlspecialchars($s['creator']) ?></span>
                                        </div>
                                    </h3>
                                    <span class="badge"
                                        style="color: <?= $statusColor ?>; background: <?= $statusBg ?>; font-weight: 700; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; padding: 0.2rem 0.5rem; border-radius: 4px;">
                                        <?= htmlspecialchars($s['status']) ?>
                                    </span>
                                </div>

                                <!-- Progress Bar Container -->
                                <div style="margin: 1.25rem 0;">
                                    <div
                                        style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.4rem; font-size: 0.8rem;">
                                        <span style="color: var(--text-secondary); font-weight: 500;">Store Completion</span>
                                        <span style="color: var(--text-primary); font-weight: 700;"><?= $s['percent'] ?>%</span>
                                    </div>
                                    <div
                                        style="width: 100%; height: 8px; background: rgba(255,255,255,0.06); border-radius: 4px; overflow: hidden;">
                                        <div
                                            style="width: <?= $s['percent'] ?>%; height: 100%; background: linear-gradient(90deg, var(--accent-color), #06b6d4); border-radius: 4px; transition: width 0.5s ease;">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div
                                style="margin-top: 1rem; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 1rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                                <span style="font-size: 0.8rem; color: var(--text-secondary);">
                                    <strong><?= $s['closed'] ?></strong> of <strong><?= $s['total'] ?></strong> closed
                                </span>
                                <div style="display: flex; gap: 6px; align-items: center;">
                                    <?php if (in_array($_SESSION['role'] ?? '', ['system_admin', 'admin']) && ($s['status'] === 'Finished' || $s['status'] === 'Closed')): ?>
                                        <button onclick="reopenStoreSession('<?= htmlspecialchars($s['store_code']) ?>')"
                                            class="btn btn-secondary btn-sm"
                                            style="padding: 3px 10px; font-size: 0.75rem; border: 1px solid var(--accent-color); color: var(--accent-color); background: rgba(59, 130, 246, 0.1); margin: 0; cursor: pointer; border-radius: 4px; font-weight: 600;"
                                            title="Re-open store session so users can access locators again">
                                            🔄 Re-open Store
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($isSysAdmin): ?>
                                        <button onclick="confirmDeleteStore('<?= htmlspecialchars($s['store_code']) ?>')"
                                            class="btn btn-secondary btn-sm"
                                            style="padding: 3px 10px; font-size: 0.75rem; border: 1px solid #ef4444; color: #ef4444; background: rgba(239, 68, 68, 0.08); margin: 0; cursor: pointer; border-radius: 4px; font-weight: 600;">
                                            Delete
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- View: MySQL Setup -->
        <div id="view-database" class="view-content">
            <header>
                <div>
                    <h1>MySQL Connection Setup</h1>
                    <div class="header-desc">Define credentials to connect to your local or remote MySQL database</div>
                </div>
            </header>

            <!-- MySQL Server Connection Settings Card -->
            <div class="card" style="max-width: 600px;">
                <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
                    <h2 class="card-title">
                        <svg viewBox="0 0 24 24">
                            <path
                                d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-2 10H7v-2h10v2z" />
                        </svg>
                        MySQL Database Connection
                    </h2>
                    <?php if ($dbStatus === 'connected'): ?>
                        <button type="button" class="btn btn-secondary btn-sm" id="btn-toggle-db-form"
                            onclick="toggleDbForm()"
                            style="width: auto; font-size: 0.8rem; padding: 4px 12px; cursor: pointer;">Show Fields</button>
                    <?php endif; ?>
                </div>

                <div id="db-connected-status-summary"
                    style="display: <?php echo ($dbStatus === 'connected') ? 'block' : 'none'; ?>; padding: 0.5rem 0;">
                    <div
                        style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                        <div
                            style="color: var(--success-color); font-weight: 600; display: flex; align-items: center; gap: 0.5rem; font-size: 0.95rem;">
                            <span style="font-size: 1.25rem; line-height: 1;">●</span> MySQL Server Connected
                            Successfully.
                        </div>
                        <button type="button" onclick="backupDatabaseLocal()" class="btn btn-secondary"
                            style="width: auto; font-size: 0.8rem; padding: 6px 14px; border: 1px solid var(--accent-color); color: var(--accent-color); background: rgba(59, 130, 246, 0.08); font-weight: 600; cursor: pointer; border-radius: 6px;">
                            💾 Set Current DB as Default
                        </button>
                    </div>
                    <p style="color: var(--text-secondary); font-size: 0.8rem; margin-top: 0.5rem; margin-bottom: 0;">
                        Host:
                        <code><?= htmlspecialchars($config['server']) ?>:<?= htmlspecialchars($config['port'] ?? '3306') ?></code>
                        | Database: <code><?= htmlspecialchars($config['database']) ?></code>
                    </p>
                </div>

                <form id="config-form" onsubmit="saveDbConfig(event)"
                    style="display: <?php echo ($dbStatus === 'connected') ? 'none' : 'block'; ?>; margin-top: 1rem;">
                    <div class="form-group" style="display: grid; grid-template-columns: 3fr 1fr; gap: 1rem;">
                        <div>
                            <label for="db_server">MySQL Server Host</label>
                            <input type="text" id="db_server" class="form-control"
                                value="<?= htmlspecialchars($config['server']) ?>" placeholder="e.g. localhost"
                                required>
                        </div>
                        <div>
                            <label for="db_port">Port</label>
                            <input type="text" id="db_port" class="form-control"
                                value="<?= htmlspecialchars($config['port'] ?? '3306') ?>" placeholder="3306" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="db_database">Database Name</label>
                        <input type="text" id="db_database" class="form-control"
                            value="<?= htmlspecialchars($config['database']) ?>"
                            placeholder="e.g. owi_physical_inventory" required>
                    </div>
                    <div class="form-group">
                        <label for="db_username">MySQL Username</label>
                        <input type="text" id="db_username" class="form-control"
                            value="<?= htmlspecialchars($config['username']) ?>" placeholder="e.g. root" required>
                    </div>
                    <div class="form-group">
                        <label for="db_password">MySQL Password</label>
                        <input type="password" id="db_password" class="form-control"
                            value="<?= htmlspecialchars($config['password']) ?>" placeholder="Leave blank if none">
                    </div>

                    <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                        <button type="submit" class="btn">Save & Verify Connection</button>
                        <button type="button" onclick="testConnection()" class="btn btn-secondary">Test Connection
                            Only</button>
                    </div>
                </form>
            </div>

            <!-- Sync Token Configuration Card -->
            <div class="card" style="max-width: 600px; margin-top: 2rem;">
                <div class="card-header">
                    <h2 class="card-title">
                        <svg viewBox="0 0 24 24">
                            <path
                                d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z" />
                        </svg>
                        Security & Synchronization Token
                    </h2>
                </div>
                <div class="form-group">
                    <label for="sync_secret_token">Secret Sync Token (Cloud & Local Authorization)</label>
                    <input type="text" id="sync_secret_token" class="form-control"
                        value="<?= htmlspecialchars($config['sync_secret_token'] ?? '') ?>"
                        placeholder="e.g. my_secure_token_123">
                    <small style="color:var(--text-muted); font-size:0.7rem; display:block; margin-top:4px;">Define a
                        custom secret token here. This exact same token must be configured on local hosts to allow
                        successful data synchronization.</small>
                    <button type="button" onclick="saveTokenOnly()" class="btn btn-secondary"
                        style="margin-top: 8px; width: auto; font-size: 0.8rem; padding: 5px 12px; cursor: pointer;">Save
                        Token Only</button>
                </div>
            </div>



            <!-- Print Spacing Settings -->
            <div class="card" style="max-width: 600px; margin-top: 2rem;">
                <div class="card-header">
                    <h2 class="card-title">
                        <svg viewBox="0 0 24 24" style="width:22px; height:22px; fill:var(--accent-color);">
                            <path
                                d="M19 8H5c-1.66 0-3 1.34-3 3v6h4v4h12v-4h4v-6c0-1.66-1.34-3-3-3zm-3 11H8v-5h8v5zm3-7c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm-1-9H6v4h12V3z" />
                        </svg>
                        Print Spacing Settings
                    </h2>
                </div>
                <form id="print-config-form" onsubmit="savePrintConfig(event)">
                    <div class="form-group" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div>
                            <label for="print_margin_top">Top Margin / Spacing (mm)</label>
                            <input type="number" id="print_margin_top" class="form-control"
                                value="<?= htmlspecialchars($config['print_margin_top'] ?? '0') ?>" min="0" max="200"
                                required>
                        </div>
                        <div>
                            <label for="print_margin_left">Left Margin / Spacing (mm)</label>
                            <input type="number" id="print_margin_left" class="form-control"
                                value="<?= htmlspecialchars($config['print_margin_left'] ?? '0') ?>" min="0" max="200"
                                required>
                        </div>
                    </div>
                    <button type="submit" class="btn" style="margin-top: 1rem;">Save Spacing Settings</button>
                </form>
            </div>
        </div>

        <!-- View: Products Catalog -->
        <div id="view-products" class="view-content">
            <header>
                <div>
                    <h1>Items Masterfile</h1>
                    <div class="header-desc">Register barcodes with names so scans display correct product details</div>
                </div>
            </header>

            <div class="products-grid">
                <!-- Product List (Column 1) -->
                <div class="card" style="margin: 0;">
                    <div class="card-header"
                        style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem;">
                        <h2 class="card-title"
                            style="margin-bottom:0; display: flex; align-items: center; gap: 0.5rem;">
                            Current Registered Products (Items)
                            <span id="catalog-count-badge" class="badge"
                                style="background: rgba(16, 185, 129, 0.15); color: #34d399; font-size: 0.8rem; padding: 4px 10px; font-weight: 700; border-radius: 12px; border: 1px solid rgba(16, 185, 129, 0.3);">0
                                Items</span>
                        </h2>
                        <input type="text" id="catalog-search" class="form-control" placeholder="Search catalog..."
                            oninput="filterCatalog()"
                            style="max-width: 140px; height: 36px; font-size: 0.85rem; padding: 0 0.75rem; margin:0;">
                    </div>
                    <div class="table-container">
                        <table id="products-table" style="width: 100%;">
                            <thead>
                                <tr>
                                    <th style="width: 25%;">UPC</th>
                                    <th style="width: 40%;">Description (Descr)</th>
                                    <th style="width: 15%;">SKU</th>
                                    <th style="width: 20%;">Type</th>
                                </tr>
                            </thead>
                            <tbody id="products-tbody">
                                <tr>
                                    <td colspan="4" class="empty-state">No products registered yet.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Add Product (Column 2) -->
                <div class="card" style="margin: 0;">
                    <div class="card-header">
                        <h2 class="card-title">Add / Update Product Catalog</h2>
                    </div>
                    <div id="catalog-form-banner"
                        style="display: none; background: rgba(59, 130, 246, 0.15); border: 1px solid rgba(59, 130, 246, 0.3); border-radius: 8px; padding: 0.5rem 0.75rem; margin-bottom: 0.75rem; font-size: 0.8rem; justify-content: space-between; align-items: center; box-sizing: border-box; flex-shrink: 0;">
                        <span style="font-weight: 500;">✏️ Editing: <span id="catalog-banner-upc"
                                style="color:var(--accent-color); font-weight:700;"></span></span>
                        <a href="javascript:void(0)" onclick="resetProductForm()"
                            style="color:#ef4444; font-weight:600; text-decoration:none; font-size:0.75rem;">Cancel</a>
                    </div>
                    <form id="product-form" onsubmit="saveProduct(event)">
                        <div class="form-group">
                            <label for="prod_barcode">UPC (Barcode)</label>
                            <input type="text" id="prod_barcode" class="form-control" placeholder="e.g. 0000000022121"
                                required>
                        </div>
                        <div class="form-group">
                            <label for="prod_name">Product Description (Descr)</label>
                            <input type="text" id="prod_name" class="form-control"
                                placeholder="e.g. TRNSCND USB 2.0 JF310 16GB" required>
                        </div>
                        <div class="form-group">
                            <label for="prod_sku">SKU Code</label>
                            <input type="text" id="prod_sku" class="form-control" placeholder="e.g. 022121">
                        </div>
                        <div class="form-group">
                            <label for="prod_type">Item Type</label>
                            <input type="text" id="prod_type" class="form-control" placeholder="e.g. ACCESSORIES"
                                value="GENERAL">
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-top: 0.75rem;">
                            <button type="submit" id="prod-submit-btn" class="btn" style="width: 100%; margin: 0;">Add
                                Item</button>
                            <button type="button" class="btn btn-secondary" onclick="resetProductForm()"
                                style="width: 100%; margin: 0;">Clear Form</button>
                        </div>
                    </form>
                </div>

                <!-- Upload Catalog Masterfile (Column 3) -->
                <div class="card" style="margin: 0;">
                    <div class="card-header">
                        <h2 class="card-title">Upload Store Masterfile</h2>
                    </div>
                    <div style="font-size:0.85rem; color:var(--text-secondary); margin-bottom:1rem; line-height:1.5;">
                        Import products in bulk. Supports <strong>ITEMS.txt</strong> (CSV) or tab-separated
                        <strong>MASTERFILE...txt</strong> files.
                    </div>
                    <form id="import-form" onsubmit="uploadMasterfile(event)">
                        <div class="form-group" style="margin-bottom: 1rem;">
                            <label for="masterfile_target_store">Target Masterfile Database</label>
                            <select id="masterfile_target_store" class="form-control" style="font-size: 0.85rem;">
                                <option value="">Global Master Catalog (Default items table)</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom: 1rem;">
                            <label for="masterfile_input">Select CSV / TSV File</label>
                            <input type="file" id="masterfile_input" class="form-control" accept=".txt,.csv,.tsv"
                                required style="height: 42px; font-size: 0.85rem; padding: 0.4rem 0.75rem;">
                        </div>
                        <button type="submit" id="upload-btn" class="btn"
                            style="width:100%; margin-top: 0.5rem; background:linear-gradient(135deg, var(--accent-color), #2563eb);">Upload
                            & Import</button>
                    </form>
                    <div
                        style="margin-top: 1.5rem; border-top: 1px dashed rgba(255,255,255,0.1); padding-top: 1.25rem; text-align: center;">
                        <div style="font-size:0.8rem; color:var(--text-secondary); margin-bottom:0.75rem;">
                            Or fetch the latest catalog directly from your cloud server database.
                        </div>
                        <button type="button" onclick="syncMasterfileFromCloud()" id="btn-sync-master-cloud"
                            class="btn btn-secondary"
                            style="width:100%; border: 1px solid var(--success-color); color: var(--success-color); background: rgba(16, 185, 129, 0.08); font-weight: 600; cursor: pointer;">
                            ☁️ Sync Masterfile from Cloud
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- View: User Accounts -->
        <div id="view-users" class="view-content">
            <header>
                <div>
                    <h1>User Account Management</h1>
                    <div class="header-desc">Create and manage operator and administrator accounts for local scanning
                        access</div>
                </div>
            </header>

            <div class="grid">
                <!-- Add User Form -->
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Create New Account</h2>
                    </div>
                    <form id="user-form" onsubmit="createUser(event)">
                        <div class="form-group">
                            <label for="new_username">Username</label>
                            <input type="text" id="new_username" class="form-control" placeholder="Username" required
                                autocomplete="username" oninput="this.value = this.value.toUpperCase()">
                        </div>
                        <div class="form-group">
                            <label for="new_password">Password</label>
                            <input type="password" id="new_password" class="form-control"
                                placeholder="Enter secure password" required autocomplete="new-password">
                        </div>
                        <div class="form-group">
                            <label for="new_role">Account Role / Access Level</label>
                            <select id="new_role" class="form-control">
                                <?php if ($isSysAdmin): ?>
                                    <option value="system_admin">system_admin (System Admin - Full System Access)</option>
                                    <option value="admin">admin (Store Admin - Manage Counts & Spacing)</option>
                                <?php endif; ?>
                                <option value="user" selected>user (Operator - Scan Only)</option>
                            </select>
                        </div>

                        <button type="submit" class="btn" style="margin-top: 1rem; width: 100%;">Create Account</button>
                    </form>
                    <div
                        style="margin-top: 1.5rem; border-top: 1px dashed rgba(255,255,255,0.1); padding-top: 1.25rem; text-align: center;">
                        <div style="font-size:0.8rem; color:var(--text-secondary); margin-bottom:0.75rem;">
                            Or sync and download all existing accounts from your cloud server.
                        </div>
                        <button type="button" onclick="syncUsersFromCloud()" id="btn-sync-users-cloud"
                            class="btn btn-secondary"
                            style="width:100%; border: 1px solid var(--success-color); color: var(--success-color); background: rgba(16, 185, 129, 0.08); font-weight: 600; cursor: pointer;">
                            ☁️ Sync Users from Cloud
                        </button>
                    </div>
                </div>

                <!-- Users List Table -->
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Registered Accounts</h2>
                    </div>
                    <div class="table-container" style="max-height: 480px;">
                        <table id="users-table">
                            <thead>
                                <tr>
                                    <th>Username</th>
                                    <th>Role</th>
                                    <th>Created At</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="users-tbody">
                                <tr>
                                    <td colspan="4" class="empty-state">Loading users list...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- View: Audit Logs -->
        <div id="view-audit" class="view-content">
            <header>
                <div>
                    <h1>System Audit Logs</h1>
                    <div class="header-desc">Track and audit modifications made to countsheets, locators, and catalog
                        records</div>
                </div>
            </header>

            <div class="card"
                style="margin: 0; padding: 1.15rem; display: flex; flex-direction: column; height: calc(100vh - 220px); min-height: 350px;">
                <div class="table-container" style="flex-grow: 1; overflow-y: auto; max-height: 100%;">
                    <table style="width: 100%;">
                        <thead>
                            <tr>
                                <th style="width: 18%;">Timestamp</th>
                                <th style="width: 12%;">User</th>
                                <th style="width: 12%;">Store Code</th>
                                <th style="width: 18%;">Action</th>
                                <th style="width: 40%;">Details</th>
                            </tr>
                        </thead>
                        <tbody id="audit-logs-tbody">
                            <tr>
                                <td colspan="5" class="empty-state">Loading system audit logs...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- View: Test Scan Checker Simulator -->
        <div id="view-checker" class="view-content">
            <header>
                <div>
                    <h1>Test Scan Checker Simulator</h1>
                    <div class="header-desc">Simulate a barcode scan or look up a SKU to instantly verify if it exists
                        in the master database.</div>
                </div>
            </header>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; align-items: start;">
                <!-- Simulator Input Card -->
                <div class="card" style="margin: 0; padding: 2rem;">
                    <div class="card-header"
                        style="border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 0.75rem; margin-bottom: 1.5rem;">
                        <h2 class="card-title">Barcode Simulator</h2>
                    </div>

                    <form id="checker-form" onsubmit="runTestScanCheck(event)">
                        <div class="form-group">
                            <label for="checker_barcode" style="color: var(--accent-color); font-weight: 700;">Scan /
                                Enter Barcode or SKU</label>
                            <input type="text" id="checker_barcode" class="form-control"
                                placeholder="Type or scan item barcode / SKU code..."
                                style="height: 48px; font-size: 1.1rem; border-color: rgba(59, 130, 246, 0.4); text-align: center; font-family: monospace; outline: none; background: rgba(0,0,0,0.25);"
                                required autocomplete="off">
                        </div>
                        <div style="display: flex; gap: 10px; margin-top: 1.5rem;">
                            <button type="submit" class="btn" style="flex: 2; height: 44px; font-weight: 700;">🔍 Test
                                Lookup</button>
                            <button type="button" onclick="resetChecker()" class="btn btn-secondary"
                                style="flex: 1; height: 44px; background: rgba(255,255,255,0.05); color: #fff; border: 1px solid rgba(255,255,255,0.1);">Clear</button>
                        </div>
                    </form>
                </div>

                <!-- Simulation Response Display Card -->
                <div class="card" id="checker-response-card"
                    style="margin: 0; padding: 2rem; min-height: 250px; display: flex; flex-direction: column; justify-content: center; align-items: center; border: 1px dashed rgba(255,255,255,0.1); background: rgba(0,0,0,0.1);">
                    <div id="checker-empty-state" style="text-align: center; color: var(--text-secondary);">
                        <svg viewBox="0 0 24 24"
                            style="width: 64px; height: 64px; fill: rgba(255,255,255,0.15); margin-bottom: 1rem;">
                            <path d="M3 5h2v14H3zm4 0h1v14H7zm3 0h2v14h-2zm4 0h1v14h-1zm3 0h3v14h-3zm-9 16h12v2H7z" />
                        </svg>
                        <p style="font-size: 0.95rem; font-weight: 500;">Ready to test. Input or scan a code on the left
                            to verify.</p>
                    </div>

                    <div id="checker-result-found" style="display: none; width: 100%;">
                        <div
                            style="background: rgba(16, 185, 129, 0.12); border: 1px solid rgba(16, 185, 129, 0.25); border-radius: 8px; padding: 12px; display: flex; align-items: center; justify-content: center; gap: 8px; margin-bottom: 1.5rem; color: #10b981; font-weight: 700; text-transform: uppercase; font-size: 0.9rem; letter-spacing: 0.5px;">
                            <span>🟢 MATCH FOUND IN DATABASE</span>
                        </div>
                        <div
                            style="background: rgba(0,0,0,0.2); border-radius: 8px; padding: 1.25rem; border: 1px solid rgba(255,255,255,0.05); font-size: 0.9rem; line-height: 1.6;">
                            <div
                                style="margin-bottom: 0.5rem; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 0.5rem;">
                                <strong style="color: var(--text-secondary);">Product Name:</strong>
                                <br><span id="checker-res-name"
                                    style="color: white; font-size: 1.15rem; font-weight: 700; display: block; margin-top: 4px;">-</span>
                            </div>
                            <div
                                style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 0.5rem;">
                                <div>
                                    <strong style="color: var(--text-secondary);">Barcode (UPC):</strong>
                                    <div id="checker-res-barcode"
                                        style="font-family: monospace; color: var(--accent-color); font-weight: 700; font-size: 1rem;">
                                        -</div>
                                </div>
                                <div>
                                    <strong style="color: var(--text-secondary);">SKU Code:</strong>
                                    <div id="checker-res-sku"
                                        style="font-family: monospace; color: white; font-weight: 600;">-</div>
                                </div>
                            </div>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                                <div>
                                    <strong style="color: var(--text-secondary);">Masterfile Qty:</strong>
                                    <div id="checker-res-qty"
                                        style="color: #10b981; font-weight: 800; font-size: 1.2rem;">-</div>
                                </div>
                                <div>
                                    <strong style="color: var(--text-secondary);">Attributes:</strong>
                                    <div id="checker-res-attr" style="color: white;">-</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="checker-result-notfound" style="display: none; width: 100%; text-align: center;">
                        <div
                            style="background: rgba(239, 68, 68, 0.12); border: 1px solid rgba(239, 68, 68, 0.25); border-radius: 8px; padding: 12px; display: flex; align-items: center; justify-content: center; gap: 8px; margin-bottom: 1.5rem; color: #ef4444; font-weight: 700; text-transform: uppercase; font-size: 0.9rem; letter-spacing: 0.5px;">
                            <span>🔴 ITEM NOT FOUND (INF)</span>
                        </div>
                        <svg viewBox="0 0 24 24" style="width: 56px; height: 56px; fill: #ef4444; margin-bottom: 1rem;">
                            <path
                                d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z" />
                        </svg>
                        <p style="color: white; font-weight: 700; font-size: 1.05rem; margin-bottom: 0.5rem;">Scanned
                            barcode/SKU does not exist in master catalog.</p>
                        <p style="color: var(--text-secondary); font-size: 0.85rem; max-width: 280px; margin: 0 auto;">
                            When scanned, this item will save as an <strong>Item Not Found (INF)</strong> record with
                            0.00 master quantity.</p>
                    </div>
                </div>
            </div>
        </div>

    </main>

    <!-- Toast Notification Banner -->
    <div id="toast" class="toast">
        <div class="toast-icon">
            <svg id="toast-success-icon" style="display:none;" viewBox="0 0 24 24">
                <path
                    d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z" />
            </svg>
            <svg id="toast-error-icon" style="display:none;" viewBox="0 0 24 24">
                <path
                    d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z" />
            </svg>
            <svg id="toast-info-icon" style="display:none;" viewBox="0 0 24 24">
                <path
                    d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z" />
            </svg>
        </div>
        <div id="toast-message" class="toast-msg">Notification Message</div>
    </div>

    <!-- QR Code Library -->
    <script src="js/qrcode.min.js"></script>
    <script>
        // System variables
        const serverIp = "<?= $localIP ?>";
        const scanUrl = "<?= $scanUrl ?>";
        let autoPollInterval = null;

        // Generate QR code for cellular connection
        window.addEventListener('DOMContentLoaded', () => {
            const qrContainer = document.getElementById("qrcode");
            if (qrContainer) {
                new QRCode(qrContainer, {
                    text: scanUrl,
                    width: 150,
                    height: 150,
                    colorDark: "#000000",
                    colorLight: "#ffffff",
                    correctLevel: QRCode.CorrectLevel.L
                });
            }

            // Start automatic scan logs polling
            loadScans();
            autoPollInterval = setInterval(loadScans, 3000);

            // Load products list
            loadProducts();
        });

        // Switch Views (tabs)
        function switchView(viewId, element) {
            // Hide all views
            document.querySelectorAll('.view-content').forEach(view => {
                view.classList.remove('active');
            });
            // Show target view
            const targetView = document.getElementById('view-' + viewId);
            if (targetView) targetView.classList.add('active');

            // Update active menu link
            document.querySelectorAll('.nav-item').forEach(item => {
                item.classList.remove('active');
            });
            if (element) {
                element.classList.add('active');
            } else {
                // Find matching side nav item dynamically
                document.querySelectorAll('.nav-menu .nav-item').forEach(item => {
                    const onclickText = item.getAttribute('onclick') || '';
                    if (onclickText.includes(`'${viewId}'`)) {
                        item.classList.add('active');
                    }
                });
            }

            if (viewId === 'users') {
                loadUsers();
            }
            if (viewId === 'audit') {
                loadAuditLogs();
            }
            if (viewId === 'checker') {
                setTimeout(() => {
                    const inp = document.getElementById('checker_barcode');
                    if (inp) {
                        inp.value = '';
                        inp.focus();
                    }
                }, 50);
            }
        }

        // Toast Messages
        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast');
            const toastMsg = document.getElementById('toast-message');

            toast.className = `toast toast-${type} show`;
            toastMsg.innerText = message;

            // Hide all icons
            document.getElementById('toast-success-icon').style.display = 'none';
            document.getElementById('toast-error-icon').style.display = 'none';
            document.getElementById('toast-info-icon').style.display = 'none';

            // Show active icon
            document.getElementById(`toast-${type}-icon`).style.display = 'block';

            setTimeout(() => {
                toast.classList.remove('show');
            }, 4000);
        }

        // Sync Items Masterfile from Cloud
        async function syncMasterfileFromCloud() {
            const targetSelect = document.getElementById('masterfile_target_store');
            const storeCode = targetSelect ? targetSelect.value : '';
            const label = storeCode ? `store ${storeCode.toUpperCase()} database` : "entire Items Masterfile";

            const ok = await showCustomConfirm(
                `Are you sure you want to download and sync the catalog for ${label} from the cloud? This will overwrite the local store database catalog.`,
                "Sync Masterfile from Cloud",
                "Download & Sync",
                "Cancel"
            );
            if (!ok) return;

            const btn = document.getElementById('btn-sync-master-cloud');
            btn.disabled = true;
            btn.innerText = 'Syncing catalog from cloud...';

            showToast(`Fetching catalog for ${label} from cloud...`, "info");

            fetch(`api.php?action=import_cloud_products&store_code=${encodeURIComponent(storeCode)}`)
                .then(res => res.json())
                .then(data => {
                    btn.disabled = false;
                    btn.innerText = '☁️ Sync Masterfile from Cloud';
                    if (data.status === 'success') {
                        showToast(data.message, "success");
                        loadProducts(); // Reload local product list
                    } else {
                        showToast(data.message, "error");
                    }
                })
                .catch(err => {
                    btn.disabled = false;
                    btn.innerText = '☁️ Sync Masterfile from Cloud';
                    showToast("Failed to sync masterfile: " + err, "error");
                });
        }

        // Sync items specific to a store from the cloud
        async function downloadStoreItemsFromCloud(storeCode) {
            const ok = await showCustomConfirm(
                `Are you sure you want to download and sync the database items for store ${storeCode.toUpperCase()} from the cloud?`,
                "Download Store Items",
                "Download Items",
                "Cancel"
            );
            if (!ok) return;
            showToast(`Downloading items for ${storeCode.toUpperCase()} from cloud...`, 'info');
            fetch(`api.php?action=import_cloud_products&store_code=${encodeURIComponent(storeCode)}`)
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showToast(data.message, 'success');
                        setTimeout(() => window.location.reload(), 1500);
                    } else {
                        showToast(data.message, 'error');
                    }
                })
                .catch(err => {
                    showToast('Failed to download items: ' + err, 'error');
                });
        }

        // Sync User Accounts from Cloud
        async function syncUsersFromCloud() {
            const ok = await showCustomConfirm(
                "Are you sure you want to download and sync all user accounts from the cloud? This will overwrite local user accounts.",
                "Sync Users from Cloud",
                "Sync Users",
                "Cancel"
            );
            if (!ok) return;

            const btn = document.getElementById('btn-sync-users-cloud');
            btn.disabled = true;
            btn.innerText = 'Syncing user accounts...';

            showToast("Fetching user accounts from cloud...", "info");

            fetch('api.php?action=import_cloud_users')
                .then(res => res.json())
                .then(data => {
                    btn.disabled = false;
                    btn.innerText = '☁️ Sync Users from Cloud';
                    if (data.status === 'success') {
                        showToast(data.message, "success");
                        loadUsers(); // Reload local users list
                    } else {
                        showToast(data.message, "error");
                    }
                })
                .catch(err => {
                    btn.disabled = false;
                    btn.innerText = '☁️ Sync Users from Cloud';
                    showToast("Failed to sync users: " + err, "error");
                });
        }

        // Re-open Finished/Closed Store Session for Admins
        async function reopenStoreSession(storeCode) {
            const ok = await showCustomConfirm(
                `Are you sure you want to re-open store session '${storeCode.toUpperCase()}'? This will allow operators and the store creator to access and scan in this store again.`,
                "Re-open Store Session",
                "Re-open Store",
                "Cancel"
            );
            if (!ok) return;

            showToast(`Re-opening store ${storeCode.toUpperCase()}...`, "info");

            fetch(`api.php?action=reopen_store&store_code=${encodeURIComponent(storeCode)}`, {
                method: 'POST'
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showToast(data.message, "success");
                        setTimeout(() => window.location.reload(), 1500);
                    } else {
                        showCustomAlert("Failed to re-open store: " + data.message, "Re-open Failed");
                    }
                })
                .catch(err => {
                    showCustomAlert("Request failed: " + err, "Network Error");
                });
        }

        // Delete Store Session
        async function confirmDeleteStore(storeCode) {
            const ok = await showCustomConfirm(
                `WARNING: Are you sure you want to permanently delete the store session '${storeCode.toUpperCase()}'? This will DROP all locators, scans, and configs, and cannot be undone.`,
                "Delete Store Session",
                "Permanently Delete",
                "Cancel"
            );
            if (!ok) return;

            showToast(`Deleting store ${storeCode.toUpperCase()}...`, "info");

            fetch(`api.php?action=delete_store&store_code=${encodeURIComponent(storeCode)}`)
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showToast(data.message, "success");
                        setTimeout(() => window.location.reload(), 1500);
                    } else {
                        showToast(data.message, "error");
                    }
                })
                .catch(err => showToast("Failed to delete store: " + err, "error"));
        }

        // Test Database Connection
        function testConnection() {
            showToast("Testing connection to MySQL Server...", "info");
            fetch('api.php?action=test_connection')
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showToast(data.message, "success");
                        updateDbBadge('connected');
                    } else {
                        showToast(data.message, "error");
                        updateDbBadge('error');
                    }
                })
                .catch(err => {
                    showToast("AJAX request failed: " + err, "error");
                    updateDbBadge('error');
                });
        }

        // Initialize database tables
        function initializeDatabase() {
            showToast("Checking & creating database schema in MySQL...", "info");
            fetch('api.php?action=init_db')
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showToast(data.message, "success");
                        updateDbBadge('connected');
                        loadScans();
                        loadProducts();
                    } else {
                        showToast(data.message, "error");
                    }
                })
                .catch(err => {
                    showToast("Database initialisation failed: " + err, "error");
                });
        }

        // Restore database tables and catalog from database.sql
        async function restoreDatabaseBackup() {
            const ok = await showCustomConfirm(
                "WARNING: This will drop and reset all database tables (users, items, stores) back to the default state using database.sql. Any un-synced scans will be cleared. Do you want to proceed?",
                "Restore Database Backup",
                "Reset & Import",
                "Cancel"
            );
            if (!ok) return;
            showToast("Restoring database structure and importing catalog from database.sql...", "info");
            fetch('api.php?action=restore_default_db')
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showToast(data.message, "success");
                        setTimeout(() => {
                            window.location.reload();
                        }, 1500);
                    } else {
                        showToast(data.message, "error");
                    }
                })
                .catch(err => {
                    showToast("Database restoration failed: " + err, "error");
                });
        }

        // Save current database as default backup
        async function backupDatabaseLocal() {
            const ok = await showCustomConfirm(
                "Are you sure you want to save your current database (schema and data) as the default data for future installations? This will write a database.sql file in your repository.",
                "Save Default Database Backup",
                "Save Backup",
                "Cancel"
            );
            if (!ok) return;
            showToast("Generating default database backup file...", "info");
            fetch('api.php?action=backup_db')
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showToast(data.message, "success");
                    } else {
                        showToast("Backup failed: " + data.message, "error");
                    }
                })
                .catch(err => {
                    showToast("Backup failed: " + err, "error");
                });
        }

        // Save DB Configuration Settings
        function saveDbConfig(event) {
            event.preventDefault();

            const server = document.getElementById('db_server').value;
            const port = document.getElementById('db_port').value;
            const database = document.getElementById('db_database').value;
            const username = document.getElementById('db_username').value;
            const password = document.getElementById('db_password').value;
            const sync_secret_token = document.getElementById('sync_secret_token').value;

            const print_margin_top = document.getElementById('print_margin_top').value;
            const print_margin_left = document.getElementById('print_margin_left').value;

            const payload = { server, port, database, username, password, print_margin_top, print_margin_left, sync_secret_token };

            showToast("Saving MySQL config...", "info");

            fetch('api.php?action=save_config', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showToast(data.message, data.connection_failed ? "error" : "success");
                        updateDbBadge(data.connection_failed ? 'error' : 'connected');
                        if (!data.connection_failed) {
                            setTimeout(() => window.location.reload(), 1500);
                        }
                    } else {
                        showToast(data.message, "error");
                    }
                })
                .catch(err => {
                    showToast("Failed to save configuration: " + err, "error");
                });
        }

        // Save Secret Sync Token Only
        function saveTokenOnly() {
            const token = document.getElementById('sync_secret_token').value.trim();
            showToast("Saving Secret Sync Token...", "info");

            fetch('api.php?action=save_sync_token', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ sync_secret_token: token })
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showToast(data.message, "success");
                        setTimeout(() => window.location.reload(), 1000);
                    } else {
                        showToast(data.message, "error");
                    }
                })
                .catch(err => {
                    showToast("Failed to save token: " + err, "error");
                });
        }

        // Toggle MySQL Connection form visibility
        function toggleDbForm() {
            const form = document.getElementById('config-form');
            const btn = document.getElementById('btn-toggle-db-form');
            const summary = document.getElementById('db-connected-status-summary');
            if (form.style.display === 'none') {
                form.style.display = 'block';
                if (summary) summary.style.display = 'none';
                if (btn) btn.innerText = 'Hide Fields';
            } else {
                form.style.display = 'none';
                if (summary) summary.style.display = 'block';
                if (btn) btn.innerText = 'Show Fields';
            }
        }

        // Save Print Config Spacing Settings
        function savePrintConfig(event) {
            event.preventDefault();

            const server = document.getElementById('db_server').value;
            const port = document.getElementById('db_port').value;
            const database = document.getElementById('db_database').value;
            const username = document.getElementById('db_username').value;
            const password = document.getElementById('db_password').value;
            const sync_secret_token = document.getElementById('sync_secret_token').value;

            const print_margin_top = document.getElementById('print_margin_top').value;
            const print_margin_left = document.getElementById('print_margin_left').value;

            const payload = { server, port, database, username, password, print_margin_top, print_margin_left, sync_secret_token };

            showToast("Saving print spacing configuration...", "info");

            fetch('api.php?action=save_config', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showToast("Print spacing settings saved successfully!", "success");
                        setTimeout(() => window.location.reload(), 1000);
                    } else {
                        showToast(data.message, "error");
                    }
                })
                .catch(err => {
                    showToast("Failed to save print spacing config: " + err, "error");
                });
        }

        // Update database badges status
        function updateDbBadge(status) {
            const badge = document.getElementById('sidebar-db-status');
            if (badge) {
                badge.className = `status-badge status-${status}`;
                badge.innerText = status.charAt(0).toUpperCase() + status.slice(1);
            }
        }

        // Load Scan Log
        function loadScans() {
            const tbody = document.getElementById('scans-tbody');
            if (!tbody) return;
            fetch('api.php?action=get_scans')
                .then(res => res.json())
                .then(data => {
                    const tbody = document.getElementById('scans-tbody');
                    if (data.status === 'success') {
                        if (data.scans && data.scans.length > 0) {
                            let html = '';
                            data.scans.forEach(scan => {
                                const prodName = scan.product_name ? scan.product_name : '<span style="color:var(--text-secondary);font-style:italic;">Catalog Item Not Found</span>';
                                html += `
                                    <tr>
                                        <td>#${scan.id}</td>
                                        <td style="font-family:monospace; font-weight:600; color:#3b82f6;">${scan.barcode}</td>
                                        <td>${prodName}</td>
                                        <td><span class="badge">${scan.sku ? scan.sku : 'N/A'}</span></td>
                                        <td style="font-weight:600;">${parseFloat(scan.quantity).toFixed(0)}</td>
                                        <td>Slot ${scan.location}</td>
                                        <td>${scan.scanned_by}</td>
                                        <td style="color:var(--text-secondary); font-size:0.85rem;">${scan.scanned_at}</td>
                                    </tr>
                                `;
                            });
                            tbody.innerHTML = html;
                        } else {
                            tbody.innerHTML = `
                                <tr>
                                    <td colspan="8" class="empty-state">
                                        <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg>
                                        <p>No barcodes scanned yet. Connect a phone to begin scanning!</p>
                                    </td>
                                </tr>
                            `;
                        }
                    } else {
                        // Error connecting or loading
                        tbody.innerHTML = `
                            <tr>
                                <td colspan="8" class="empty-state" style="color:var(--danger-color)">
                                    <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
                                    <p>Database Error: ${data.message}</p>
                                    <p style="font-size:0.85rem;margin-top:0.5rem;color:var(--text-secondary)">Please check if MySQL is running in XAMPP and configuration settings are correct.</p>
                                </td>
                            </tr>
                        `;
                    }
                })
                .catch(err => {
                    console.error("AJAX error loading scans:", err);
                });
        }

        // Confirm and clear all scan logs (handled by async confirmClearScans below)

        // Export data to CSV
        function exportToCSV() {
            fetch('api.php?action=get_scans')
                .then(res => res.json())
                .then(data => {
                    if (data.status !== 'success' || !data.scans || data.scans.length === 0) {
                        showToast("No scan logs found to export.", "error");
                        return;
                    }

                    let csvContent = "data:text/csv;charset=utf-8,";
                    csvContent += "RecNo,UPC,Description,SKU,Quantity,SlotNo,Scanned By,Count Date\n";

                    data.scans.forEach(scan => {
                        const row = [
                            scan.id,
                            `"${scan.barcode}"`,
                            `"${scan.product_name || 'Unknown'}"`,
                            `"${scan.sku || 'N/A'}"`,
                            scan.quantity,
                            scan.location,
                            `"${scan.scanned_by || 'Handheld'}"`,
                            scan.scanned_at
                        ];
                        csvContent += row.join(",") + "\n";
                    });

                    const encodedUri = encodeURI(csvContent);
                    const link = document.createElement("a");
                    link.setAttribute("href", encodedUri);
                    link.setAttribute("download", `OWI_PhysicalInventory_Countsheet_${new Date().toISOString().slice(0, 10)}.csv`);
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                    showToast("Countsheet exported to CSV successfully!", "success");
                })
                .catch(err => showToast("Export failed: " + err, "error"));
        }

        // Save new product catalog details
        function saveProduct(event) {
            event.preventDefault();

            const barcode = document.getElementById('prod_barcode').value;
            const product_name = document.getElementById('prod_name').value;
            const sku = document.getElementById('prod_sku').value;
            const type = document.getElementById('prod_type').value;

            const payload = { barcode, product_name, sku, type };

            fetch('api.php?action=add_product', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showToast(data.message, "success");
                        resetProductForm();
                        loadProducts();
                        loadScans(); // Reload dashboard logs to show names instantly if barcode matched
                    } else {
                        showToast(data.message, "error");
                    }
                })
                .catch(err => {
                    showToast("Failed to save product: " + err, "error");
                });
        }

        let catalogProducts = []; // Local cache of catalog products

        // Load Products Catalog list
        function loadProducts() {
            fetch('api.php?action=get_products')
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success' && data.products) {
                        catalogProducts = data.products;
                        renderCatalog(catalogProducts);
                    } else {
                        const tbody = document.getElementById('products-tbody');
                        tbody.innerHTML = `
                            <tr>
                                <td colspan="4" class="empty-state">No items registered in MySQL items catalog.</td>
                            </tr>
                        `;
                    }
                })
                .catch(err => console.error("Error loading products:", err));
        }

        // Helper to generate context-specific tag colors for item types
        function getTypeBadge(type) {
            const t = (type || 'GENERAL').trim().toUpperCase();
            let bg = 'rgba(59, 130, 246, 0.1)';
            let color = '#60a5fa';

            if (t.includes('PAPER') || t.includes('PAD') || t.includes('BOOK') || t.includes('WRITING')) {
                bg = 'rgba(16, 185, 129, 0.1)'; color = '#34d399';
            } else if (t.includes('ELECTRONICS') || t.includes('ACCESSORIES') || t.includes('PRINTER')) {
                bg = 'rgba(139, 92, 246, 0.1)'; color = '#a78bfa';
            } else if (t !== 'GENERAL') {
                bg = 'rgba(245, 158, 11, 0.1)'; color = '#fbbf24';
            }
            return `<span class="badge" style="background:${bg};color:${color};text-transform:uppercase;">${t}</span>`;
        }

        function renderCatalog(productsList) {
            const tbody = document.getElementById('products-tbody');
            const countBadge = document.getElementById('catalog-count-badge');
            const totalCount = catalogProducts ? catalogProducts.length : 0;
            const currentCount = productsList ? productsList.length : 0;

            if (countBadge) {
                if (currentCount < totalCount) {
                    countBadge.innerText = `${currentCount.toLocaleString()} / ${totalCount.toLocaleString()} Items`;
                } else {
                    countBadge.innerText = `${totalCount.toLocaleString()} Items`;
                }
            }

            if (productsList && productsList.length > 0) {
                let html = '';
                productsList.forEach(prod => {
                    const cleanName = prod.product_name.replace(/'/g, "\\'");
                    const cleanSku = (prod.sku || '').replace(/'/g, "\\'");
                    const cleanType = (prod.type || 'GENERAL').replace(/'/g, "\\'");

                    html += `
                        <tr style="cursor: pointer;" onclick="populateProductForm('${prod.barcode}', '${cleanName}', '${cleanSku}', '${cleanType}')">
                            <td style="font-family:monospace;font-weight:600;color:#10b981;font-size:0.8rem;">${prod.barcode}</td>
                            <td style="font-weight:500;word-break:break-word;">${prod.product_name}</td>
                            <td><span class="badge">${prod.sku ? prod.sku : 'N/A'}</span></td>
                            <td>${getTypeBadge(prod.type)}</td>
                        </tr>
                    `;
                });
                tbody.innerHTML = html;
            } else {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="4" class="empty-state">No matching products found.</td>
                    </tr>
                `;
            }
        }

        function filterCatalog() {
            const query = document.getElementById('catalog-search').value.toLowerCase().trim();
            if (query === '') {
                renderCatalog(catalogProducts);
                return;
            }

            const filtered = catalogProducts.filter(prod => {
                const upc = (prod.barcode || '').toLowerCase();
                const sku = (prod.sku || '').toLowerCase();
                const desc = (prod.product_name || '').toLowerCase();
                const type = (prod.type || 'GENERAL').toLowerCase();
                return upc.includes(query) || sku.includes(query) || desc.includes(query) || type.includes(query);
            });

            renderCatalog(filtered);
        }

        // Prefill form for editing
        function populateProductForm(barcode, name, sku, type) {
            document.getElementById('prod_barcode').value = barcode;
            document.getElementById('prod_name').value = name;
            document.getElementById('prod_sku').value = sku;
            document.getElementById('prod_type').value = type;

            // Show Edit mode details
            document.getElementById('catalog-form-banner').style.display = 'flex';
            document.getElementById('catalog-banner-upc').innerText = barcode;
            document.getElementById('prod-submit-btn').innerText = "Update Item";

            showToast(`Editing item: ${name}`, "info");
        }

        // Reset the Add/Update form
        function resetProductForm() {
            document.getElementById('product-form').reset();
            document.getElementById('prod_type').value = "GENERAL";

            // Hide banner and reset button label
            document.getElementById('catalog-form-banner').style.display = 'none';
            document.getElementById('prod-submit-btn').innerText = "Add Item";
        }

        // Confirm and clear all scan logs
        async function confirmClearScans() {
            const ok = await showCustomConfirm(
                "Are you sure you want to permanently clear all scan logs from the MySQL countsheet database? This cannot be undone.",
                "Clear All Scan Logs",
                "Clear Scans",
                "Cancel"
            );
            if (!ok) return;
            fetch('api.php?action=clear_scans')
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showToast(data.message, "success");
                        loadScans();
                    } else {
                        showToast(data.message, "error");
                    }
                })
                .catch(err => showToast("Failed to clear scans: " + err, "error"));
        }

        // Delete product from the global catalog
        async function deleteProduct(event, barcode) {
            event.stopPropagation(); // Avoid prefilling the form when hitting delete!

            const ok = await showCustomConfirm(
                `Are you sure you want to delete product barcode "${barcode}" from the catalog?`,
                "Delete Catalog Item",
                "Delete Item",
                "Cancel"
            );
            if (!ok) return;

            fetch('api.php?action=delete_product', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ barcode })
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showToast(data.message, "success");
                        resetProductForm();
                        loadProducts();
                        loadScans(); // Reload logs in case that item was deleted
                    } else {
                        showToast(data.message, "error");
                    }
                })
                .catch(err => {
                    showToast("Failed to delete product: " + err, "error");
                });
        }

        // Upload Masterfile catalog in bulk
        function uploadMasterfile(event) {
            event.preventDefault();

            const fileInput = document.getElementById('masterfile_input');
            if (fileInput.files.length === 0) {
                showToast("Please select a file to import.", "error");
                return;
            }

            const targetStoreSelect = document.getElementById('masterfile_target_store');
            const targetStore = targetStoreSelect ? targetStoreSelect.value : '';

            const file = fileInput.files[0];
            const formData = new FormData();
            formData.append('file', file);
            if (targetStore) {
                formData.append('store_code', targetStore);
            }

            const uploadBtn = document.getElementById('upload-btn');
            const originalText = uploadBtn.innerText;
            uploadBtn.disabled = true;
            uploadBtn.innerText = "Importing masterfile...";

            const targetLabel = targetStore ? `Store ${targetStore} (${targetStore.toLowerCase()}_items)` : 'Global Master Catalog (items)';
            showToast(`Parsing & importing masterfile into ${targetLabel}...`, "info");

            fetch('api.php?action=import_masterfile', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    uploadBtn.disabled = false;
                    uploadBtn.innerText = originalText;

                    if (data.status === 'success') {
                        showToast(data.message, "success");
                        document.getElementById('import-form').reset();
                        loadProducts();
                        loadScans();
                    } else {
                        showToast(data.message, "error");
                    }
                })
                .catch(err => {
                    uploadBtn.disabled = false;
                    uploadBtn.innerText = originalText;
                    showToast("Import failed: " + err, "error");
                });
        }

        // Load Users List
        function loadUsers() {
            fetch('api.php?action=get_users')
                .then(res => res.json())
                .then(data => {
                    const tbody = document.getElementById('users-tbody');
                    if (data.status === 'success' && data.users) {
                        if (data.users.length > 0) {
                            let html = '';
                            data.users.forEach(user => {
                                const loggedInRole = '<?= $_SESSION['role'] ?>';
                                const isSelf = user.username === '<?= htmlspecialchars($_SESSION['username'] ?? '') ?>';
                                const isPrimaryAdmin = user.username === 'sys_admin';
                                const isSystemAdminRole = user.role === 'system_admin';
                                const isAdminRole = user.role === 'admin';

                                let deleteButton = '';
                                if (isSelf) {
                                    deleteButton = `<span style="color:var(--text-secondary); font-size:0.75rem; font-style:italic;">Logged In</span>`;
                                } else if (isPrimaryAdmin) {
                                    deleteButton = `<span style="color:var(--text-secondary); font-size:0.75rem; font-style:italic;">Primary</span>`;
                                } else if (loggedInRole === 'admin' && (isSystemAdminRole || isAdminRole)) {
                                    deleteButton = `<span style="color:var(--text-secondary); font-size:0.75rem; font-style:italic;">Protected</span>`;
                                } else {
                                    deleteButton = `<button onclick="deleteUser(${user.id}, '${user.username}')" class="btn btn-danger btn-sm" style="padding: 0.25rem 0.5rem; font-size: 0.75rem; box-shadow: none;">Delete</button>`;
                                }

                                html += `
                                    <tr>
                                        <td style="font-weight:600; color:#3b82f6;">${user.username}</td>
                                        <td><span class="badge" style="background:${user.role === 'system_admin' ? 'rgba(59,130,246,0.15);color:#60a5fa;' : 'rgba(16,185,129,0.15);color:#10b981;'}">${user.role}</span></td>
                                        <td style="color:var(--text-secondary); font-size:0.8rem;">${user.created_at}</td>
                                        <td>${deleteButton}</td>
                                    </tr>
                                `;
                            });
                            tbody.innerHTML = html;
                        } else {
                            tbody.innerHTML = `<tr><td colspan="4" class="empty-state">No users registered.</td></tr>`;
                        }
                    } else {
                        tbody.innerHTML = `<tr><td colspan="4" class="empty-state" style="color:var(--danger-color)">Error loading: ${data.message}</td></tr>`;
                    }
                })
                .catch(err => console.error("Error loading users:", err));
        }

        // Load system audit logs
        function loadAuditLogs() {
            fetch('api.php?action=get_audit_logs')
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success' && data.logs) {
                        const tbody = document.getElementById('audit-logs-tbody');
                        if (!tbody) return;

                        if (data.logs.length === 0) {
                            tbody.innerHTML = '<tr><td colspan="5" class="empty-state">No audit logs recorded yet.</td></tr>';
                            return;
                        }

                        let html = '';
                        data.logs.forEach(log => {
                            html += `
                                <tr style="border-bottom: 1px solid var(--card-border);">
                                    <td style="white-space: nowrap; color: var(--text-secondary); padding: 10px 8px;">${log.timestamp}</td>
                                    <td style="font-weight: 600; color: var(--accent-color); padding: 10px 8px;">${log.username}</td>
                                    <td style="padding: 10px 8px;"><span class="badge" style="background: rgba(255, 255, 255, 0.05);">${log.store_code || 'GLOBAL'}</span></td>
                                    <td style="padding: 10px 8px;"><span class="badge" style="background: rgba(59, 130, 246, 0.1); color: #60a5fa; font-weight:700;">${log.action}</span></td>
                                    <td style="color: var(--text-primary); font-size: 0.85rem; padding: 10px 8px;">${log.details}</td>
                                </tr>
                            `;
                        });
                        tbody.innerHTML = html;
                    }
                })
                .catch(err => console.error("Error loading audit logs:", err));
        }

        // Create new user account
        function createUser(event) {
            event.preventDefault();
            const username = document.getElementById('new_username').value.trim().toUpperCase();
            const password = document.getElementById('new_password').value;
            const role = document.getElementById('new_role').value;

            const payload = { username, password, role };

            fetch('api.php?action=add_user', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showToast(data.message, "success");
                        document.getElementById('user-form').reset();
                        loadUsers();
                    } else {
                        showToast(data.message, "error");
                    }
                })
                .catch(err => showToast("Failed to create user: " + err, "error"));
        }

        // Delete user account (handled by async deleteUser below)

        // Catalog Simulator Check
        function runTestScanCheck(event) {
            event.preventDefault();
            const inputEl = document.getElementById('checker_barcode');
            const barcode = inputEl.value.trim();
            if (barcode === '') return;

            showToast("Verifying barcode in database...", "info");

            const emptyCard = document.getElementById('checker-empty-state');
            const foundCard = document.getElementById('checker-result-found');
            const notFoundCard = document.getElementById('checker-result-notfound');
            const responseCard = document.getElementById('checker-response-card');

            // Fetch product info from API
            fetch('api.php?action=get_product_info&barcode=' + encodeURIComponent(barcode))
                .then(res => res.json())
                .then(data => {
                    emptyCard.style.display = 'none';
                    responseCard.style.borderStyle = 'solid';

                    if (data.status === 'success' && data.product_found) {
                        foundCard.style.display = 'block';
                        notFoundCard.style.display = 'none';

                        document.getElementById('checker-res-name').innerText = data.product_name;
                        document.getElementById('checker-res-barcode').innerText = data.barcode || barcode;
                        document.getElementById('checker-res-sku').innerText = data.sku || 'N/A';
                        document.getElementById('checker-res-qty').innerText = parseFloat(data.master_qty || 0).toFixed(0);
                        document.getElementById('checker-res-attr').innerText = (data.sku ? 'MASTERFILE ITEM' : 'GENERAL');

                        responseCard.style.borderColor = 'rgba(16, 185, 129, 0.4)';
                        responseCard.style.background = 'rgba(16, 185, 129, 0.08)';
                        showToast("Match found in database!", "success");
                    } else {
                        foundCard.style.display = 'none';
                        notFoundCard.style.display = 'block';

                        responseCard.style.borderColor = 'rgba(239, 68, 68, 0.4)';
                        responseCard.style.background = 'rgba(239, 68, 68, 0.08)';
                        showToast("Item not found in database.", "error");
                    }

                    // Highlight input to scan again quickly
                    inputEl.select();
                })
                .catch(err => {
                    showToast("Error checking database: " + err, "error");
                });
        }

        function resetChecker() {
            document.getElementById('checker-form').reset();
            document.getElementById('checker-empty-state').style.display = 'block';
            document.getElementById('checker-result-found').style.display = 'none';
            document.getElementById('checker-result-notfound').style.display = 'none';

            const responseCard = document.getElementById('checker-response-card');
            responseCard.style.borderStyle = 'dashed';
            responseCard.style.borderColor = 'rgba(255,255,255,0.1)';
            responseCard.style.background = 'rgba(0,0,0,0.1)';

            const inputEl = document.getElementById('checker_barcode');
            inputEl.focus();
        }

        // Exit Store Session (handled by async logoutStore below)
    </script>
</body>

</html>