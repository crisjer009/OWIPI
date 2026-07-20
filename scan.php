<?php
require_once __DIR__ . '/config.php';

// Handle dynamic QR Autologin to bypass login page
if (isset($_GET['autologin']) && isset($_GET['store'])) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['user_id'] = (int) $_GET['autologin'];
    $_SESSION['username'] = isset($_GET['user']) ? urldecode($_GET['user']) : 'Mobile Scanner';
    $_SESSION['store_code'] = strtoupper($_GET['store']);
    $_SESSION['is_mobile_scanner'] = true;

    // Redirect to clean scan.php URL to hide credentials in browser history
    header('Location: scan.php');
    exit;
}

checkAuth(); // All authenticated users can scan
$config = loadConfig();
$marginTop = isset($config['print_margin_top']) ? (int) $config['print_margin_top'] : 0;
$marginLeft = isset($config['print_margin_left']) ? (int) $config['print_margin_left'] : 10;
$localIP = getServerLocalIP();
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443;
$protocol = $isHttps ? "https://" : "http://";
$systemHost = $_SERVER['HTTP_HOST'] ?? $localIP;

// Override loopback addresses with active network IP so cellphones can connect
$hostParts = explode(':', $systemHost);
$hostOnly = $hostParts[0];
if ($hostOnly === 'localhost' || $hostOnly === '127.0.0.1' || $hostOnly === '::1') {
    $systemHost = $localIP;
}

$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
$scriptDir = rtrim($scriptDir, '/');
$isMobileScanner = !empty($_SESSION['is_mobile_scanner']);
$scanUrl = $protocol . $systemHost . $scriptDir . "/scan.php?autologin=" . ($_SESSION['user_id'] ?? '') . "&store=" . ($_SESSION['store_code'] ?? '') . "&user=" . urlencode($_SESSION['username'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>OWI Physical Inventory - Mobile Scanner</title>
    <!-- Google Fonts -->
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@600;700;800&display=swap"
        rel="stylesheet">
    <style>
        :root {
            --bg-color: #0d1117;
            --card-bg: rgba(22, 27, 34, 0.95);
            --card-border: rgba(240, 246, 252, 0.1);
            --text-primary: #c9d1d9;
            --text-white: #ffffff;
            --text-muted: #8b949e;
            --accent-color: #58a6ff;
            --success-color: #2ea44f;
            --success-glow: rgba(46, 164, 79, 0.25);
            --danger-color: #f85149;
            --warning-color: #d29922;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            background-color: var(--bg-color);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            padding: 10px;
            overflow-x: hidden;
        }

        header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 15px;
            padding: 5px 0;
        }

        h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.25rem;
            font-weight: 800;
            background: linear-gradient(135deg, #fff, #8b949e);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .connection-status {
            font-size: 0.75rem;
            font-weight: 600;
            background: rgba(46, 164, 79, 0.15);
            color: #3fb950;
            border: 1px solid rgba(46, 164, 79, 0.3);
            padding: 3px 8px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .connection-status::before {
            content: '';
            width: 6px;
            height: 6px;
            background: #3fb950;
            border-radius: 50%;
            display: inline-block;
        }

        .connection-status.offline {
            background: rgba(248, 81, 73, 0.15);
            color: #f85149;
            border: 1px solid rgba(248, 81, 73, 0.3);
        }

        .connection-status.offline::before {
            background: #f85149;
        }

        /* Container Cards */
        .card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .card-title {
            font-size: 0.85rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-white);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        /* Scanner Area */
        .scanner-container {
            position: relative;
            background: #000;
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid var(--card-border);
            aspect-ratio: 4/3;
            margin-bottom: 10px;
        }

        #reader {
            width: 100% !important;
            height: 100% !important;
        }

        #reader video {
            object-fit: cover !important;
        }

        /* Flash Animation Screen */
        .scan-flash {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: var(--success-color);
            opacity: 0;
            z-index: 5;
            pointer-events: none;
            transition: opacity 0.05s ease;
        }

        .scan-flash.active {
            opacity: 0.6;
        }

        .scanner-placeholder {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            color: var(--text-muted);
            padding: 20px;
            z-index: 1;
            background: #090d13;
        }

        .scanner-placeholder svg {
            width: 48px;
            height: 48px;
            fill: var(--text-muted);
            margin-bottom: 10px;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% {
                opacity: 0.4;
            }

            50% {
                opacity: 0.8;
            }

            100% {
                opacity: 0.4;
            }
        }

        /* Forms & Inputs */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-bottom: 8px;
        }

        .form-group {
            margin-bottom: 10px;
        }

        label {
            display: block;
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-bottom: 4px;
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            background: #090d13;
            border: 1px solid var(--card-border);
            color: var(--text-white);
            border-radius: 6px;
            padding: 8px 12px;
            font-size: 0.9rem;
            height: 38px;
            outline: none;
            transition: border-color 0.2s;
        }

        .form-control:focus {
            border-color: var(--accent-color);
        }

        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg fill='gray' height='24' viewBox='0 0 24 24' width='24' xmlns='http://www.w3.org/2000/svg'><path d='M7 10l5 5 5-5z'/></svg>");
            background-repeat: no-repeat;
            background-position: right 8px center;
            background-size: 20px;
            padding-right: 30px;
        }

        /* Buttons styling */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 42px;
            font-size: 0.95rem;
            font-weight: 600;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            transition: opacity 0.2s;
        }

        .btn-primary {
            background: var(--accent-color);
            color: #0d1117;
        }

        .btn-success {
            background: var(--success-color);
            color: white;
            box-shadow: 0 4px 8px var(--success-glow);
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-secondary {
            background: #21262d;
            color: var(--text-primary);
            border: 1px solid var(--card-border);
        }

        .btn:active {
            opacity: 0.8;
        }

        /* Switch toggles */
        .switch-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 6px 0;
        }

        .switch-label {
            font-size: 0.85rem;
            color: var(--text-primary);
        }

        .switch {
            position: relative;
            display: inline-block;
            width: 44px;
            height: 24px;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #21262d;
            transition: .3s;
            border-radius: 24px;
            border: 1px solid var(--card-border);
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .3s;
            border-radius: 50%;
        }

        input:checked+.slider {
            background-color: var(--success-color);
        }

        input:checked+.slider:before {
            transform: translateX(20px);
        }

        /* Manual Input & Logs */
        .scan-log-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid rgba(240, 246, 252, 0.05);
            font-size: 0.8rem;
        }

        .scan-log-item:last-child {
            border-bottom: none;
        }

        .scan-log-barcode {
            font-family: monospace;
            font-weight: 600;
            color: var(--accent-color);
        }

        .scan-log-meta {
            text-align: right;
        }

        /* Info Display Card */
        .status-card {
            background: rgba(88, 166, 255, 0.08);
            border: 1px solid rgba(88, 166, 255, 0.2);
            border-radius: 8px;
            padding: 10px;
            font-size: 0.8rem;
            margin-bottom: 12px;
            display: none;
        }

        .status-card.show {
            display: block;
        }

        /* Modal Overlay for Manual Confirmation */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(0, 0, 0, 0.85);
            z-index: 100;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.25s ease;
        }

        .modal-overlay.active {
            opacity: 1;
            pointer-events: all;
        }

        .modal {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            width: 100%;
            max-width: 400px;
            padding: 20px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5);
            transform: translateY(20px);
            transition: transform 0.25s ease;
        }

        .modal-overlay.active .modal {
            transform: translateY(0);
        }

        .modal-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.15rem;
            color: var(--text-white);
            margin-bottom: 10px;
            text-align: center;
        }

        /* Secure Context Block Box */
        .secure-context-card {
            background: rgba(248, 81, 73, 0.15);
            border: 1px solid rgba(248, 81, 73, 0.3);
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 15px;
            color: #ff7b72;
            display: none;
        }

        .secure-context-card.active {
            display: block;
        }

        .secure-context-card h3 {
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .secure-context-card ol {
            padding-left: 1.2rem;
            font-size: 0.8rem;
            line-height: 1.5;
            margin-top: 8px;
        }

        .secure-context-card ol li {
            margin-bottom: 4px;
        }

        /* Responsive Host Dashboard Grid Classes */
        .host-top-grid {
            display: grid;
            grid-template-columns: 1.3fr 0.7fr;
            gap: 15px;
            align-items: stretch;
            height: 320px;
            min-height: 320px;
        }

        .host-sub-grid {
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 15px;
            align-items: stretch;
            height: 100%;
        }

        .host-bottom-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            align-items: stretch;
            margin-top: 15px;
            flex-grow: 1;
            min-height: 320px;
        }

        @media (max-width: 1024px) {
            #host-dashboard {
                height: auto !important;
            }

            .host-top-grid {
                grid-template-columns: 1fr;
                height: auto;
                min-height: 0;
            }

            .host-sub-grid {
                grid-template-columns: 1fr;
                height: auto;
            }

            .host-bottom-grid {
                grid-template-columns: 1fr;
                height: auto;
                min-height: 0;
            }
        }

        .host-metric-flex {
            display: flex;
            align-items: center;
            justify-content: space-around;
            flex-grow: 1;
            min-height: 0;
            gap: 15px;
        }

        @media (max-width: 480px) {
            .host-metric-flex {
                flex-direction: column;
                text-align: center;
                gap: 10px;
            }
        }
    </style>
</head>

<body>

    <?php if (!isset($_SESSION['store_code'])): ?>
        <style>
            .store-selector-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100vw;
                height: 100vh;
                background: rgba(11, 15, 25, 0.96);
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

            .form-group {
                margin-bottom: 1.25rem;
            }

            .form-group label {
                display: block;
                font-size: 0.85rem;
                color: #9ca3af;
                margin-bottom: 0.5rem;
                font-weight: 500;
            }

            .form-control {
                width: 100%;
                background: rgba(255, 255, 255, 0.03);
                border: 1px solid rgba(255, 255, 255, 0.08);
                border-radius: 10px;
                padding: 0.85rem 1.1rem;
                color: white;
                font-size: 0.95rem;
                transition: all 0.2s ease;
            }

            .form-control:focus {
                outline: none;
                border-color: #3b82f6;
                box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.35);
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
            }

            select.form-control option {
                background: #111827;
                color: white;
            }

            .btn-select-store {
                width: 100%;
                background: #3b82f6;
                color: white;
                border: none;
                border-radius: 10px;
                padding: 0.85rem;
                font-size: 1rem;
                font-weight: 600;
                cursor: pointer;
                box-shadow: 0 4px 15px rgba(59, 130, 246, 0.35);
            }
        </style>
        <div class="store-selector-overlay" id="store-select-overlay">
            <div class="store-selector-card">
                <div style="text-align:center; margin-bottom: 2rem;">
                    <div
                        style="width:50px; height:50px; background:linear-gradient(135deg, #3b82f6, #06b6d4); border-radius:14px; display:inline-flex; align-items:center; justify-content:center; box-shadow:0 0 20px rgba(59,130,246,0.35); margin-bottom:1rem;">
                        <svg viewBox="0 0 24 24" style="width:26px;height:26px;fill:white;">
                            <path
                                d="M12 2C6.48 2 2 4.02 2 6.5v11c0 2.48 4.48 4.5 10 4.5s10-2.02 10-4.5v-11C22 4.02 17.52 2 12 2zm0 18c-4.41 0-8-1.79-8-4v-2.15c1.99.96 4.79 1.65 8 1.65s6.01-.69 8-1.65V16c0 2.21-3.59 4-8 4z" />
                        </svg>
                    </div>
                    <h2
                        style="font-family:'Outfit',sans-serif; font-size:1.4rem; font-weight:700; margin-bottom:0.25rem; color:#fff;">
                        Select Store Session</h2>
                    <p style="color:#9ca3af; font-size:0.85rem;">Select which store prefix database you are scanning
                        barcodes into.</p>
                </div>

                <form onsubmit="handleSelectStore(event)" id="store-select-form">
                    <div class="form-group" id="existing-stores-group" style="display:none;">
                        <label for="active_store_select">Choose Open Store</label>
                        <select id="active_store_select" class="form-control" style="margin-bottom: 1rem;">
                            <!-- Dynamically populated -->
                        </select>
                    </div>

                    <div class="form-group" id="new-store-group">
                        <div style="margin-bottom: 1rem;">
                            <label for="active_store_input" id="store-input-label">Create / Connect New Store Code</label>
                            <input type="text" id="active_store_input" class="form-control" placeholder="STORE CODE"
                                style="text-transform: uppercase;" autocomplete="off">
                        </div>
                        <div>
                            <label for="active_store_locators">Number of Locators Needed</label>
                            <input type="number" id="active_store_locators" class="form-control" min="1" max="1000"
                                placeholder="e.g. 10" value="10">
                        </div>
                    </div>

                    <button type="submit" class="btn-select-store" style="margin-top: 1rem;">Activate Store Session</button>

                    <div style="text-align:center; margin-top: 1rem;" id="toggle-store-mode-container">
                        <a href="javascript:void(0)" onclick="toggleStoreInputMode()"
                            style="font-size:0.8rem; color:#3b82f6; text-decoration:none; font-weight:600;"
                            id="toggle-store-mode-btn">Choose from existing stores</a>
                    </div>

                    <div
                        style="text-align:center; margin-top: 1.5rem; display: flex; flex-direction: column; gap: 0.75rem; border-top: 1px solid rgba(255, 255, 255, 0.08); padding-top: 1.25rem;">
                        <?php if (isset($_SESSION['role']) && ($_SESSION['role'] === 'system_admin' || $_SESSION['role'] === 'admin')): ?>
                            <a href="index.php"
                                style="font-size:0.85rem; color:#10b981; text-decoration:none; font-weight:600; display: inline-flex; align-items: center; justify-content: center; gap: 4px;">
                                <svg viewBox="0 0 24 24" style="width:16px;height:16px;fill:currentColor;">
                                    <path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z" />
                                </svg>
                                Exit to Dashboard
                            </a>
                        <?php endif; ?>
                        <a href="logout.php"
                            style="font-size:0.85rem; color:#ef4444; text-decoration:none; font-weight:600; display: inline-flex; align-items: center; justify-content: center; gap: 4px;">
                            <svg viewBox="0 0 24 24" style="width:16px;height:16px;fill:currentColor;">
                                <path
                                    d="M10.09 15.59L11.5 17l5-5-5-5-1.41 1.41L12.67 11H3v2h9.67l-2.58 2.59zM19 3H5c-1.11 0-2 .9-2 2v4h2V5h14v14H5v-4H3v4c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z" />
                            </svg>
                            Log Out
                        </a>
                    </div>
                </form>
            </div>
        </div>
        <script>
            let storeInputMode = 'create';

            window.addEventListener('DOMContentLoaded', () => {
                fetchExistingStores();
            });

            function fetchExistingStores() {
                fetch('api.php?action=get_stores')
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'success' && data.stores && data.stores.length > 0) {
                            const select = document.getElementById('active_store_select');
                            select.innerHTML = '';
                            data.stores.forEach(store => {
                                const opt = document.createElement('option');
                                opt.value = store.store_code;
                                opt.innerText = `Store: ${store.store_code}`;
                                select.appendChild(opt);
                            });
                            setStoreMode('select');
                        } else {
                            setStoreMode('create');
                            document.getElementById('toggle-store-mode-container').style.display = 'none';
                        }
                    })
                    .catch(err => console.error("Error loading stores:", err));
            }

            function setStoreMode(mode) {
                storeInputMode = mode;
                const selectGroup = document.getElementById('existing-stores-group');
                const inputGroupContainer = document.getElementById('new-store-group');
                const inputField = document.getElementById('active_store_input');
                const btn = document.getElementById('toggle-store-mode-btn');

                if (mode === 'select') {
                    selectGroup.style.display = 'block';
                    inputGroupContainer.style.display = 'none';
                    inputField.required = false;
                    btn.innerText = "Or Create New Store";
                } else {
                    selectGroup.style.display = 'none';
                    inputGroupContainer.style.display = 'block';
                    inputField.required = true;
                    btn.innerText = "Choose from existing stores";
                }
            }

            function toggleStoreInputMode() {
                setStoreMode(storeInputMode === 'select' ? 'create' : 'select');
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
    <?php endif; ?>

    <header style="display: flex; justify-content: space-between; align-items: center; padding: 10px 15px;">
        <div>
            <h1
                style="font-size: 1.1rem; line-height: 1.2; margin: 0; display:flex; align-items:center; gap:6px; text-transform: uppercase;">
                OWI PHYSICAL INVENTORY STORE CODE : <?= htmlspecialchars($_SESSION['store_code'] ?? '') ?>
            </h1>
            <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 2px;">User:
                <?= htmlspecialchars($_SESSION['username'] ?? 'Operator') ?>
            </div>
        </div>
        <div style="display: flex; align-items: center; gap: 8px;">
            <button id="switch-view-btn" class="btn" onclick="toggleHostMobileView()"
                style="padding: 4px 10px; font-size:0.75rem; width:auto; border-radius:6px; box-shadow:none; cursor:pointer; display: flex; align-items: center; gap: 4px; background: rgba(139, 148, 158, 0.15); border: 1px solid rgba(255,255,255,0.2); color: #c9d1d9; font-weight:600;">
                🖥️ Host Console
            </button>
            <button id="btn-upload-masterfile" class="btn btn-secondary" onclick="openHostMasterfileModal()"
                style="padding: 4px 10px; font-size:0.75rem; width:auto; border-radius:6px; box-shadow:none; cursor:pointer; display: flex; align-items: center; gap: 4px; background: rgba(59, 130, 246, 0.15); border: 1px solid #3b82f6; color: #60a5fa; font-weight:600;">
                📁 Upload Masterfile
            </button>
            <button id="btn-sync-cloud" class="btn" onclick="openCloudSyncModal()"
                style="padding: 4px 8px; font-size:0.75rem; width:auto; border-radius:6px; box-shadow:none; cursor:pointer; display: flex; align-items: center; gap: 4px; background:#1f6feb; border-color:#388bfd; color:white; font-weight:600;">
                ☁️ Sync to Cloud
            </button>
            <button id="btn-close-store" class="btn btn-danger" onclick="closeStoreSession()"
                style="padding: 4px 8px; font-size:0.75rem; width:auto; border-radius:6px; box-shadow:none; cursor:pointer; background:#da3633; border-color:#da3633; color:white; font-weight:600;">
                🔒 Close Store
            </button>
            <div id="connection-status" class="connection-status" style="margin-left: 0; position: static;">Online</div>
            <a href="logout.php" id="logout-btn"
                style="color: #fca5a5; font-size: 0.8rem; font-weight: 600; text-decoration: none; padding: 4px 8px; border: 1px solid rgba(239,68,68,0.3); border-radius: 6px; background: rgba(239,68,68,0.1);">Log
                Out</a>
        </div>
    </header>

    <!-- Secure Context Warning -->
    <div id="secure-warning" class="secure-context-card">
        <h3>
            <svg viewBox="0 0 24 24" style="width:20px;height:20px;fill:currentColor;">
                <path
                    d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z" />
            </svg>
            Camera Access Blocked (HTTP Origin)
        </h3>
        <p style="font-size: 0.85rem; margin-bottom: 12px;">
            Mobile browsers block camera access on insecure HTTP addresses. To enable standard camera prompts and just
            tap <strong>Allow</strong> directly:
        </p>
        <div style="margin-bottom: 15px; text-align:left;">
            <a href="" id="secure-switch-link"
                style="display: inline-block; padding: 10px 15px; background: #2ea44f; color: white; text-decoration: none; border-radius: 6px; font-weight: 700; font-size: 0.85rem; box-shadow: 0 4px 12px rgba(46,164,79,0.25);">
                ⚡ Switch to Secure HTTPS
            </a>
        </div>
        <p
            style="font-size: 0.8rem; color: var(--text-muted); margin-top: 15px; border-top: 1px solid rgba(255,255,255,0.08); padding-top: 10px;">
            Alternatively, use the Chrome flag workaround:
        </p>
        <ol style="margin-top:8px;">
            <li>
                <strong>Easy Chrome Flag:</strong>
                <br>Open Chrome on this phone, go to:
                <br><code
                    style="background:rgba(0,0,0,0.3);padding:2px 4px;border-radius:3px;">chrome://flags/#unsafely-treat-insecure-origin-as-secure</code>
                <br>Select <strong>Enabled</strong>, write <code id="insecure-url-disp" style="color:#58a6ff;"></code>
                in the input box, and relaunch Chrome.
            </li>
            <li>
                <strong>HTTPS / SSL Tunnel:</strong> Run <code>ngrok http 80</code> on your host PC and open the secure
                HTTPS URL on this phone.
            </li>
        </ol>
    </div>



    <div id="mobile-scanner-view" style="<?= $isMobileScanner ? '' : 'display: none;' ?>">
        <!-- Hidden inputs for scanner submission -->
        <input type="hidden" id="scanned_by" value="">
        <input type="hidden" id="location" value="">

        <!-- Active Session Card -->
        <div class="card" id="active-session-card"
            style="padding: 1rem; margin-bottom: 15px; display: none; justify-content: space-between; align-items: center; background: rgba(88,166,255,0.05); border: 1px solid rgba(88,166,255,0.15);">
            <div>
                <span
                    style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; font-weight:600; display:block;">Active
                    Session</span>
                <span style="font-weight:600; color:#58a6ff;">Locator <span id="display-locator">-</span></span>
                <span style="color:var(--text-muted); margin: 0 4px;">•</span>
                <span id="display-operator"
                    style="font-weight:700; color:var(--text-white); text-transform: uppercase;">-</span>
            </div>
            <div style="display:flex; gap: 8px;">
                <button class="btn btn-secondary btn-sm" onclick="showConnectModal()"
                    style="width:auto; height:32px; padding: 0 10px; font-size: 0.75rem; margin: 0; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.15); border-radius: 6px; cursor:pointer; color:var(--text-white);">
                    Change
                </button>
                <button class="btn btn-success btn-sm" onclick="closeActiveLocator()"
                    style="width:auto; height:32px; padding: 0 10px; font-size: 0.75rem; margin: 0; background: #2ea44f; border:none; border-radius: 6px; cursor:pointer; color:white; font-weight:600; box-shadow: 0 2px 8px rgba(46, 164, 79, 0.25);">
                    Finish
                </button>
            </div>
        </div>

        <!-- Scanner Section -->
        <div class="card" id="scanner-section">
            <div class="card-title">
                <span>Camera Scanner</span>
                <div id="scanner-active-badge"
                    style="width: 8px; height: 8px; background: var(--text-muted); border-radius: 50%;"></div>
            </div>

            <div class="scanner-container">
                <div class="scan-flash" id="scan-flash"></div>
                <div id="reader"></div>
                <div class="scanner-placeholder" id="scanner-placeholder">
                    <svg viewBox="0 0 24 24">
                        <path
                            d="M4 4h7V2H4c-1.1 0-2 .9-2 2v7h2V4zm6 9l-4 4h3v5h2v-5h3l-4-4zm10-9v7h2V4c0-1.1-.9-2-2-2h-7v2h7zM14 17h3v5h2v-5h3l-4-4-4 4z" />
                    </svg>
                    <p>Scanner is offline</p>
                    <p style="font-size: 0.75rem; margin-top: 5px;">Tap Start to begin scanning</p>
                </div>
            </div>

            <div class="form-group" style="display: none;">
                <label for="camera-select">Select Camera Device</label>
                <select id="camera-select" class="form-control">
                    <option value="">Searching for cameras...</option>
                </select>
            </div>

            <div class="form-row">
                <button id="btn-start" onclick="startScanner()" class="btn btn-primary">Start Scanner</button>
                <button id="btn-stop" onclick="stopScanner()" class="btn btn-secondary" disabled>Stop</button>
            </div>
        </div>

        <!-- Last Scan Info Alert -->
        <div id="status-display" class="status-card">
            <strong id="status-title" style="display:block;margin-bottom:2px;color:var(--text-white);">Logged
                Scan</strong>
            <span id="status-text">No scans submitted yet.</span>
        </div>

        <!-- Manual input fallback -->
        <div class="card">
            <div class="card-title">Manual Barcode Input</div>
            <form onsubmit="handleManualSubmit(event)" style="display:flex; gap:8px;">
                <input type="text" id="manual-barcode" class="form-control" placeholder="Type Barcode..." required
                    style="flex-grow:1;" inputmode="numeric" pattern="[0-9]*"
                    oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                <button type="submit" class="btn btn-secondary" style="width:70px; height:38px;">Send</button>
            </form>
        </div>

        <!-- Mobile scan log history -->
        <div class="card">
            <div class="card-title">Scan Log (Current Session)</div>
            <div id="session-log" style="max-height: 200px; overflow-y: auto;">
                <div class="empty-state"
                    style="text-align:center; padding: 15px; font-size: 0.8rem; color: var(--text-muted);">
                    Scan logs from this terminal will display here.
                </div>
            </div>
        </div>

        <!-- Camera Settings Card (Moved to bottom) -->
        <div class="card" id="mobile-config-card">
            <div class="card-title">Camera Settings</div>

            <div class="switch-row">
                <span class="switch-label">Continuous Scan Mode</span>
                <label class="switch">
                    <input type="checkbox" id="continuous-scan">
                    <span class="slider"></span>
                </label>
            </div>

            <div class="switch-row">
                <span class="switch-label">Audio Beep Feedback</span>
                <label class="switch">
                    <input type="checkbox" id="beep-enabled" checked>
                    <span class="slider"></span>
                </label>
            </div>

            <div class="switch-row">
                <span class="switch-label">Vibration Haptics</span>
                <label class="switch">
                    <input type="checkbox" id="vibrate-enabled" checked>
                    <span class="slider"></span>
                </label>
            </div>
        </div>
    </div> <!-- Close mobile-scanner-view --> <!-- Host Dashboard View (Desktop Host Mode Only) -->
    <div id="host-dashboard"
        style="display: <?= $isMobileScanner ? 'none' : 'flex' ?>; width: 100%; max-width: 100%; margin: 0 auto; padding: 0 15px 15px 15px; box-sizing: border-box; height: calc(100vh - 90px); flex-direction: column; overflow-y: auto;">

        <!-- Top Section Grid: equal height log and connect/spacing cards -->
        <div class="host-top-grid">

            <!-- Left Column: Live Logs Table Card -->
            <div class="card"
                style="margin-bottom: 0; padding: 1.25rem; display: flex; flex-direction: column; justify-content: space-between; box-sizing: border-box; height: 100%; min-height: 0;">
                <div class="card-title"
                    style="display:flex; justify-content:space-between; align-items:center; border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 0.75rem;">
                    <span>Live Incoming Scans Log</span>
                </div>
                <div style="margin-top: 10px; margin-bottom: 5px;">
                    <input type="text" id="host-scans-search" placeholder="🔍 Search live incoming scans..."
                        style="width: 100%; height: 34px; padding: 0 10px; font-size: 0.8rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.08); background: rgba(0,0,0,0.15); color: white; box-sizing: border-box; outline:none;"
                        oninput="filterHostScans()">
                </div>
                <div
                    style="overflow-x: auto; overflow-y: auto; margin-top: 10px; padding-right: 5px; flex-grow: 1; min-height: 0;">
                    <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.85rem;"
                        id="host-scans-table">
                        <thead>
                            <tr
                                style="border-bottom: 2px solid rgba(255,255,255,0.08); color: var(--text-white); font-weight: 600; position: sticky; top: 0; background: #161b22; z-index: 1;">
                                <th style="padding: 12px 10px;">Barcode</th>
                                <th style="padding: 12px 10px;">Description</th>
                                <th style="padding: 12px 10px; text-align: center;">Qty</th>
                                <th style="padding: 12px 10px;">Scanned By</th>
                                <th style="padding: 12px 10px; text-align: center;">Locator</th>
                                <th style="padding: 12px 10px; text-align: right;">Time</th>
                            </tr>
                        </thead>
                        <tbody id="host-scans-tbody">
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 30px; color: var(--text-muted);">
                                    Waiting for scanner connections...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Right Column: Connect Scanner and Print Spacing Settings Side-by-Side -->
            <div class="host-sub-grid">
                <!-- Connect Cellphone Card -->
                <div class="card" id="host-connect-card"
                    style="margin-bottom: 0; padding: 1.25rem; text-align: center; height: 100%; display: flex; flex-direction: column; justify-content: space-between; box-sizing: border-box;">
                    <div>
                        <div class="card-title"
                            style="font-size: 0.95rem; border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 0.5rem; margin-bottom: 0.75rem; text-align: left; display: flex; align-items: center; gap: 6px;">
                            <svg viewBox="0 0 24 24" style="width:16px;height:16px;fill:currentColor;">
                                <path
                                    d="M9.5 6.5v3h-3v-3h3M11 5H5v6h6V5zm-1.5 9.5v3h-3v-3h3M11 13H5v6h6v-6zm9.5-6.5v3h-3v-3h3M20 5h-6v6h6V5zm-2 11-2 2h2v-2zm2-2h-2v2h2v-2zm-2 4h-2v2h2v-2zm2 2h-2v-2h-2v2h-2v-2h3v-2h-3v-2h5v4zM11.5 9h-1V8h1v1zm0-3h-1V5h1v1zm-6 3h-1V8h1v1zm0-3h-1V5h1v1zm0 9h-1v-1h1v1zm0 3h-1v-1h1v1zm9-9h-1V8h1v1zm0-3h-1V5h1v1zm-6 9h-1v-1h1v1zm1.5-1.5h-1v-1h1v1zm1.5 1.5h-1v-1h1v1z" />
                            </svg>
                            <span>Connect Scanner</span>
                        </div>

                        <div id="host-https-tip"
                            style="background:rgba(210,153,34,0.1); border:1px solid rgba(210,153,34,0.3); border-radius:6px; padding:8px; margin-bottom:12px; font-size:0.75rem; text-align:left; color:#d29922; display:none;">
                            ⚠️ Host console is loaded via <strong>HTTP</strong>. Scanner QR code will open
                            insecurely on
                            phones.
                            <br><a id="host-https-link" href=""
                                style="color:#58a6ff; font-weight:600; text-decoration:underline;">Switch Host to
                                HTTPS</a>
                            to enable direct "Allow" camera prompts on phones!
                        </div>
                    </div>
                    <div style="display: flex; flex-direction: column; align-items: center; gap: 8px; width: 100%;">
                        <div id="qrcode"
                            style="background: white; padding: 6px; border-radius: 6px; min-width: 150px; min-height: 150px; display: inline-flex; align-items: center; justify-content: center;">
                        </div>
                        <p
                            style="font-size: 0.7rem; color: var(--text-muted); line-height: 1.3; margin: 5px 0 0 0; text-align: center;">
                            Scan QR code with phone to connect. Ensure same Wi-Fi.
                        </p>

                        <?php
                        $detectedLocalIPs = getServerLocalIPs();
                        if (count($detectedLocalIPs) > 1):
                            ?>
                            <div style="margin-top: 8px; width: 100%; max-width: 210px; text-align: left;">
                                <label for="qr-ip-select"
                                    style="font-size: 0.65rem; color: var(--text-muted); display: block; margin-bottom: 2px; text-align: center;">Host
                                    Network IP (Local or Wi-Fi):</label>
                                <select id="qr-ip-select"
                                    style="font-size: 0.75rem; padding: 4px 6px; border-radius: 4px; background: rgba(0,0,0,0.3); color: white; border: 1px solid rgba(255,255,255,0.15); width: 100%; outline: none; cursor: pointer; text-align: center;"
                                    onchange="updateQRCodeIP(this.value)">
                                    <?php foreach ($detectedLocalIPs as $ipItem): 
                                        $adapterName = $ipItem['adapter'];
                                        $lower = strtolower($adapterName);
                                        $typeLabel = 'LOCAL';
                                        if (strpos($lower, 'wireless') !== false || strpos($lower, 'wi-fi') !== false || strpos($lower, 'wlan') !== false) {
                                            $typeLabel = 'WIFI';
                                        }
                                        $displayName = $adapterName;
                                        $displayName = str_ireplace('Wireless LAN adapter ', '', $displayName);
                                        $displayName = str_ireplace('Ethernet adapter ', '', $displayName);
                                        $displayName = str_ireplace('adapter ', '', $displayName);
                                    ?>
                                        <option value="<?= htmlspecialchars($ipItem['ip']) ?>" <?= ($ipItem['ip'] === $localIP) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($ipItem['ip']) ?> (<?= htmlspecialchars($typeLabel) ?>: <?= htmlspecialchars($displayName) ?>)<?= $ipItem['has_gateway'] ? ' 🌐' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Print Spacing Settings Card -->
                <div class="card"
                    style="margin-bottom: 0; padding: 1.25rem; height: 100%; display: flex; flex-direction: column; justify-content: space-between; box-sizing: border-box;">
                    <div class="card-title"
                        style="font-size: 0.95rem; border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 0.5rem; margin-bottom: 0.75rem; text-align: left; display: flex; align-items: center; gap: 6px;">
                        <svg viewBox="0 0 24 24" style="width:16px;height:16px;fill:currentColor;">
                            <path
                                d="M19 8H5c-1.66 0-3 1.34-3 3v6h4v4h12v-4h4v-6c0-1.66-1.34-3-3-3zm-3 11H8v-5h8v5zm3-7c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm-1-9H6v4h12V3z" />
                        </svg>
                        <span>Print Spacing Settings</span>
                    </div>
                    <form id="host-print-config-form" onsubmit="saveHostPrintConfig(event)"
                        style="display: flex; flex-direction: column; flex-grow: 1; justify-content: space-between; margin: 0; height: 100%;">
                        <div style="display: grid; grid-template-columns: 1fr; gap: 8px; margin-bottom: 12px;">
                            <div>
                                <label
                                    style="display:block; font-size:0.7rem; color:var(--text-muted); margin-bottom:2px; font-weight:600; text-align: left;">TOP
                                    MARGIN (MM)</label>
                                <input type="number" id="host_print_margin_top" class="form-control"
                                    value="<?= htmlspecialchars($marginTop) ?>" min="0" max="200" required
                                    style="height:32px; padding:0 8px; font-size:0.8rem; box-sizing:border-box;">
                            </div>
                            <div>
                                <label
                                    style="display:block; font-size:0.7rem; color:var(--text-muted); margin-bottom:2px; font-weight:600; text-align: left;">LEFT
                                    MARGIN (MM)</label>
                                <input type="number" id="host_print_margin_left" class="form-control"
                                    value="<?= htmlspecialchars($marginLeft) ?>" min="0" max="200" required
                                    style="height:32px; padding:0 8px; font-size:0.8rem; box-sizing:border-box;">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary"
                            style="width:100%; height:32px; font-size:0.8rem; padding:0; font-weight:600; cursor:pointer;">
                            Save Spacing
                        </button>
                    </form>
                </div>
            </div>

        </div>

        <!-- Bottom Section Grid: 2 columns (50% / 50%) -->
        <div class="host-bottom-grid">
            <!-- Left Side: Locator Progress Dashboard Card -->
            <div class="card" id="host-widget-card"
                style="margin-bottom: 0; padding: 1.25rem; display: flex; flex-direction: column; box-sizing: border-box; height: 100%; min-height: 0;">
                <div class="card-title"
                    style="display:flex; justify-content:space-between; align-items:center; border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 0.75rem; margin-bottom: 1rem;">
                    <span>Locator Completion Progress</span>
                    <div
                        style="display: flex; gap: 12px; font-size: 0.75rem; font-weight: 600; background: rgba(255,255,255,0.02); padding: 4px 10px; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);">
                        <span id="metric-total-qty" style="display: none;">0</span>
                        <span style="color: var(--text-muted);">INF: <span id="metric-unique-barcodes"
                                style="color: #2ea44f;">0</span></span>
                        <span style="color: var(--text-muted);">Scanners: <span id="metric-active-scanners"
                                style="color: #d29922;">0</span></span>
                    </div>
                </div>

                <div class="host-metric-flex">
                    <!-- SVG Progress Ring -->
                    <div style="position: relative; width: 120px; height: 120px; flex-shrink: 0;">
                        <svg width="120" height="120" viewBox="0 0 120 120" style="transform: rotate(-90deg);">
                            <!-- Background Circle -->
                            <circle r="48" cx="60" cy="60" fill="transparent" stroke="rgba(255,255,255,0.04)"
                                stroke-width="8"></circle>
                            <!-- Progress Circle -->
                            <circle id="widget-progress-circle" r="48" cx="60" cy="60" fill="transparent"
                                stroke="#2ea44f" stroke-width="8" stroke-linecap="round" stroke-dasharray="301.6"
                                stroke-dashoffset="301.6"
                                style="transition: stroke-dashoffset 0.6s cubic-bezier(0.4, 0, 0.2, 1);"></circle>
                        </svg>
                        <div id="widget-progress-text"
                            style="position: absolute; top: 0; left: 0; width: 120px; height: 120px; display: flex; flex-direction: column; justify-content: center; align-items: center; font-family: 'Outfit', sans-serif;">
                            <span style="font-size: 1.45rem; font-weight: 800; color: white; line-height: 1;">0%</span>
                            <span
                                style="font-size: 0.6rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase; margin-top: 2px; letter-spacing: 0.5px;">Closed</span>
                        </div>
                    </div>

                    <!-- Metrics Details Panel -->
                    <div style="display: flex; flex-direction: column; gap: 10px; flex-grow: 1; max-width: 180px;">
                        <div
                            style="background: rgba(46,164,79,0.06); border: 1px solid rgba(46,164,79,0.15); border-radius: 8px; padding: 6px 12px; display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-size: 0.75rem; color: #8b949e; font-weight: 500;">Closed (Done)</span>
                            <span id="widget-closed-count"
                                style="font-family: 'Outfit', sans-serif; font-size: 1rem; font-weight: 700; color: #2ea44f;">0</span>
                        </div>
                        <div
                            style="background: rgba(210,153,34,0.06); border: 1px solid rgba(210,153,34,0.15); border-radius: 8px; padding: 6px 12px; display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-size: 0.75rem; color: #8b949e; font-weight: 500;">Active / Open</span>
                            <span id="widget-open-count"
                                style="font-family: 'Outfit', sans-serif; font-size: 1rem; font-weight: 700; color: #d29922;">0</span>
                        </div>
                        <div
                            style="background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.05); border-radius: 8px; padding: 6px 12px; display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-size: 0.75rem; color: #8b949e; font-weight: 500;">Total Locators</span>
                            <span id="widget-total-count"
                                style="font-family: 'Outfit', sans-serif; font-size: 1rem; font-weight: 700; color: white;">0</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Side: Locator Manager & Count Sheet (takes 50% width) -->
            <div class="card"
                style="margin-bottom: 0; padding: 1.25rem; display: flex; flex-direction: column; box-sizing: border-box; height: 100%; min-height: 0;">
                <div class="card-title"
                    style="display:flex; justify-content:space-between; align-items:center; border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 0.75rem; margin-bottom:1rem;">
                    <span>Count Sheet & Locators</span>
                    <div style="display: flex; gap: 8px;">
                        <button id="btn-print-summary" class="btn btn-success" onclick="printStoreSummary()"
                            style="padding: 4px 8px; font-size:0.75rem; width:auto; border-radius:6px; box-shadow:none; cursor:pointer; background:#2ea44f; border-color:#2ea44f;">Print
                            Summary</button>
                        <button class="btn btn-primary" onclick="autoAddNextLocator()"
                            style="padding: 4px 8px; font-size:0.75rem; width:auto; border-radius:6px; box-shadow:none; cursor:pointer;">+
                            Add Locator</button>
                    </div>
                </div>

                <!-- Search Input -->
                <input type="text" id="host-locator-search" placeholder="🔍 Search locators..."
                    style="width: 100%; height: 34px; padding: 0 10px; font-size: 0.8rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.08); background: rgba(0,0,0,0.15); color: white; margin-bottom: 12px; box-sizing: border-box; outline:none;"
                    oninput="filterHostLocators()">

                <!-- Scrollable Table Container -->
                <div style="overflow-x: auto; overflow-y: auto; padding-right: 5px; flex-grow: 1; min-height: 0;">
                    <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.85rem;">
                        <thead>
                            <tr
                                style="border-bottom: 2px solid rgba(255,255,255,0.08); color: var(--text-white); font-weight: 600; position: sticky; top: 0; background: #161b22; z-index: 1;">
                                <th style="padding: 8px 6px;">Locator</th>
                                <th style="padding: 8px 6px;">Status</th>
                                <th style="padding: 8px 6px;">Operator</th>
                                <th style="padding: 8px 6px; text-align: center;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="host-locators-tbody">
                            <tr>
                                <td colspan="4" style="text-align: center; padding: 20px; color: var(--text-muted);">
                                    Loading locators...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <!-- Modal for viewing/editing scans in a specific locator -->
    <div class="modal-overlay" id="host-view-locator-scans-modal-overlay">
        <div class="modal" style="max-width: 600px; width: 95%; padding: 20px;">
            <h3 class="modal-title" id="view-scans-locator-title"
                style="border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 10px; margin-bottom: 15px;">
                Items in Locator</h3>

            <div
                style="overflow-x: auto; max-height: 350px; overflow-y: auto; margin-bottom: 20px; padding-right: 5px;">
                <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.85rem;">
                    <thead>
                        <tr
                            style="border-bottom: 2px solid rgba(255,255,255,0.08); color: var(--text-white); font-weight: 600; position: sticky; top: 0; background: #161b22; z-index: 1;">
                            <th style="padding: 10px 8px; text-align: center; width: 40px;">#</th>
                            <th style="padding: 10px 8px;">Barcode</th>
                            <th style="padding: 10px 8px;">Description</th>
                            <th style="padding: 10px 8px; text-align: center;">Qty</th>
                            <th style="padding: 10px 8px; text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="view-scans-tbody">
                        <!-- Loaded dynamically -->
                    </tbody>
                </table>
            </div>

            <div class="form-row" style="margin-top: 20px; display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" class="btn btn-primary"
                    onclick="printEditedCountSheet(window.currentEditingLocatorName)"
                    style="width: auto; height: 38px; padding: 0 20px; margin: 0; background:#3b82f6; border-color:#3b82f6; font-weight:600; cursor:pointer;">Print
                    Edits</button>
                <button type="button" class="btn btn-success" onclick="printLocatorScans()"
                    style="width: auto; height: 38px; padding: 0 20px; margin: 0; background:#2ea44f; border-color:#2ea44f; font-weight:600; cursor:pointer;">Print
                    Sheet</button>
                <button type="button" class="btn btn-secondary" onclick="closeLocatorScansModal()"
                    style="width: auto; height: 38px; padding: 0 20px; margin: 0; cursor:pointer;">Close</button>
            </div>
        </div>
    </div>

    <!-- Modal for Host Edit Scan (called from locator inspector modal) -->
    <div class="modal-overlay" id="host-edit-scan-modal-overlay">
        <div class="modal" style="max-width: 400px; padding: 20px;">
            <h3 class="modal-title"
                style="border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 10px; margin-bottom: 15px;">Edit
                Scan Record</h3>

            <input type="hidden" id="edit-scan-id">

            <div class="form-group" style="margin-bottom: 12px;">
                <label
                    style="display: block; font-size: 0.8rem; color: var(--text-muted); margin-bottom: 6px; font-weight: 600; text-transform: uppercase;">Barcode
                    (UPC/SKU)</label>
                <input type="text" id="edit-scan-barcode" class="form-control" placeholder="Enter barcode..." required
                    style="width: 100%; height: 36px; box-sizing: border-box;"
                    oninput="updateEditScanProductInfo(this.value.trim())">
            </div>

            <div id="edit-scan-product-info"
                style="background:rgba(255,255,255,0.03); border-radius:6px; padding:10px; margin-bottom:12px; font-size:0.8rem; border:1px dashed var(--card-border);">
                <strong style="color:var(--text-white); display:block; margin-bottom:2px;"
                    id="edit-scan-prod-name">Checking Catalog...</strong>
                <span id="edit-scan-prod-desc" style="color:var(--text-muted);">Retrieving details...</span>
            </div>

            <div class="form-group" style="margin-bottom: 20px;">
                <label
                    style="display: block; font-size: 0.8rem; color: var(--text-muted); margin-bottom: 6px; font-weight: 600; text-transform: uppercase;">Quantity</label>
                <input type="number" id="edit-scan-qty" class="form-control" placeholder="Enter quantity..." min="0"
                    required style="width: 100%; height: 36px; box-sizing: border-box;">
            </div>

            <div class="form-row" style="margin-top: 20px; display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" class="btn btn-danger" onclick="closeEditScanModal()"
                    style="width: auto; height: 38px; padding: 0 15px; margin: 0;">Cancel</button>
                <button type="button" class="btn btn-success" onclick="submitEditScan()"
                    style="width: auto; height: 38px; padding: 0 15px; margin: 0;">Save Changes</button>
            </div>
        </div>
    </div>
    <!-- Custom Dialog Modal (Alert/Confirm) -->
    <div class="modal-overlay" id="custom-dialog-overlay" style="z-index: 999999;">
        <div class="modal"
            style="max-width: 400px; width: 90%; padding: 25px; border: 1px solid rgba(255,255,255,0.08); text-align: center; border-radius: 8px;">
            <h3 class="modal-title" id="custom-dialog-title"
                style="margin-bottom: 12px; font-weight:700; color: var(--text-white);">Confirm Action</h3>
            <p id="custom-dialog-message"
                style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 25px; line-height: 1.5;"></p>
            <div style="display: flex; gap: 10px; justify-content: center;" id="custom-dialog-actions">
                <button type="button" class="btn btn-primary" id="custom-dialog-btn-primary"
                    style="width: auto; height: 38px; padding: 0 20px; margin: 0; font-weight:600; cursor:pointer;">Yes,
                    Proceed</button>
                <button type="button" class="btn btn-secondary" id="custom-dialog-btn-secondary"
                    style="width: auto; height: 38px; padding: 0 20px; margin: 0; font-weight:600; cursor:pointer;">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Cloud Synchronization Modal -->
    <div class="modal-overlay" id="cloud-sync-modal-overlay">
        <div class="modal" style="max-width: 500px; width: 95%; padding: 25px;">
            <h3 class="modal-title"
                style="border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 10px; margin-bottom: 15px;">
                ☁️ Cloud Synchronization
            </h3>

            <form id="cloud-sync-form" onsubmit="saveSyncConfig(event)">
                <div class="form-group" style="margin-bottom: 15px;">
                    <label for="sync_cloud_url"
                        style="color:var(--text-white); font-weight:600; font-size:0.85rem; display:block; margin-bottom:6px;">Cloud
                        Server API URL</label>
                    <input type="url" id="sync_cloud_url" class="form-control" placeholder="https://pginv.officewarehouse.com.ph/OWIPI/"
                        style="width:100%; box-sizing:border-box;" required>
                    <span style="font-size:0.7rem; color:var(--text-muted); display:block; margin-top:4px;">The full URL
                        of your cloud server instance, e.g. <code>https://pginv.officewarehouse.com.ph/OWIPI/</code></span>
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label for="sync_secret_token"
                        style="color:var(--text-white); font-weight:600; font-size:0.85rem; display:block; margin-bottom:6px;">Secret
                        Sync Token</label>
                    <input type="password" id="sync_secret_token" class="form-control"
                        placeholder="Enter secure sync token" style="width:100%; box-sizing:border-box;">
                    <span style="font-size:0.7rem; color:var(--text-muted); display:block; margin-top:4px;">Security
                        token defined in the server's db_config.json to authorize uploads.</span>
                </div>

                <div id="sync-status-msg"
                    style="padding: 10px; border-radius: 6px; font-size: 0.8rem; line-height: 1.4; display: none; margin-bottom: 20px;">
                </div>

                <div class="form-row" style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 15px;">
                    <button type="submit" class="btn btn-secondary"
                        style="width: auto; height: 38px; padding: 0 15px; margin: 0; cursor:pointer;">Save
                        Config</button>
                    <button type="button" id="btn-run-sync" onclick="runCloudSync()" class="btn btn-primary"
                        style="width: auto; height: 38px; padding: 0 15px; margin: 0; background:#388bfd; border-color:#388bfd; font-weight:600; cursor:pointer; display: flex; align-items: center; gap: 4px;">
                        <span>Start Sync</span>
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="closeCloudSyncModal()"
                        style="width: auto; height: 38px; padding: 0 15px; margin: 0; cursor:pointer;">Close</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal for non-continuous scan confirmation -->
    <div class="modal-overlay" id="confirm-modal-overlay">
        <div class="modal">
            <h3 class="modal-title">Confirm Scanned Item</h3>
            <div class="form-group">
                <label>Barcode</label>
                <input type="text" id="modal-barcode" class="form-control" readonly style="opacity:0.7;">
            </div>

            <div id="modal-product-info"
                style="background:rgba(255,255,255,0.03); border-radius:6px; padding:10px; margin-bottom:12px; font-size:0.8rem; border:1px dashed var(--card-border);">
                <strong style="color:var(--text-white); display:block; margin-bottom:2px;" id="modal-prod-name">Checking
                    Catalog...</strong>
                <span id="modal-prod-desc" style="color:var(--text-muted);">Retrieving details from MySQL...</span>
            </div>

            <div class="form-group">
                <label for="modal-qty">Quantity to Count</label>
                <input type="number" id="modal-qty" class="form-control" value="1" min="1" required>
            </div>
            <div class="form-row" style="margin-top:20px;">
                <button type="button" onclick="submitModal(true)" class="btn btn-success">Save Count</button>
                <button type="button" onclick="submitModal(false)" class="btn btn-danger">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Scan beep synthesizer & Html5Qrcode & QRCode Generator -->
    <script src="js/html5-qrcode.min.js"></script>
    <script src="js/qrcode.min.js"></script>
    <script>
        // Custom Styled Dialog overlay logic
        function customAlert(message, title = "Notification", callback = null) {
            const overlay = document.getElementById('custom-dialog-overlay');
            if (!overlay) {
                // Fallback to native if DOM not ready
                alert(message);
                if (typeof callback === 'function') callback();
                return;
            }
            const titleEl = document.getElementById('custom-dialog-title');
            const messageEl = document.getElementById('custom-dialog-message');
            const btnPrimary = document.getElementById('custom-dialog-btn-primary');
            const btnSecondary = document.getElementById('custom-dialog-btn-secondary');

            titleEl.innerText = title;
            messageEl.innerText = message;
            btnSecondary.style.display = 'none';

            btnPrimary.innerText = "OK";
            btnPrimary.onclick = function () {
                overlay.classList.remove('active');
                if (typeof callback === 'function') callback();
            };

            overlay.classList.add('active');
        }

        function customConfirm(message, onConfirm, title = "Confirm Action", onCancel = null) {
            const overlay = document.getElementById('custom-dialog-overlay');
            if (!overlay) {
                // Fallback to native if DOM not ready
                if (confirm(message)) {
                    if (typeof onConfirm === 'function') onConfirm();
                } else {
                    if (typeof onCancel === 'function') onCancel();
                }
                return;
            }
            const titleEl = document.getElementById('custom-dialog-title');
            const messageEl = document.getElementById('custom-dialog-message');
            const btnPrimary = document.getElementById('custom-dialog-btn-primary');
            const btnSecondary = document.getElementById('custom-dialog-btn-secondary');

            titleEl.innerText = title;
            messageEl.innerText = message;
            btnSecondary.style.display = 'block';

            btnPrimary.innerText = "Yes, Proceed";
            btnPrimary.onclick = function () {
                overlay.classList.remove('active');
                if (typeof onConfirm === 'function') onConfirm();
            };

            btnSecondary.onclick = function () {
                overlay.classList.remove('active');
                if (typeof onCancel === 'function') onCancel();
            };

            overlay.classList.add('active');
        }

        // Overwrite window.alert globally
        window.alert = function (msg) {
            customAlert(msg);
        };

        const storeCode = <?= json_encode($_SESSION['store_code'] ?? 'TES') ?>;
        let printMarginTop = <?= json_encode($marginTop) ?>;
        let printMarginLeft = <?= json_encode($marginLeft) ?>;
        let html5QrCode = null;
        let isScannerRunning = false;
        let currentScanSession = [];
        let scanningLock = false; // Prevent multiple triggers during network upload
        let qrCodeInstance = null;

        window.addEventListener('DOMContentLoaded', () => {
            try {
                // Fill instructions flag text
                const currentOrigin = window.location.origin;
                const insecureDisp = document.getElementById('insecure-url-disp');
                if (insecureDisp) insecureDisp.innerText = currentOrigin;

                // Detect Secure Context & Camera capabilities
                const isSecure = window.isSecureContext || window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';

                // Detect Mobile session (QR autologin) vs PC Host
                const isMobileSession = <?= json_encode(!empty($_SESSION['is_mobile_scanner'])) ?>;
                const urlParams = new URLSearchParams(window.location.search);
                const forcedView = urlParams.get('view');
                const isMobile = (forcedView === 'mobile') || (isMobileSession && forcedView !== 'host');

                if (!isMobile) {
                    // On Desktop/PC (Host Mode)
                    document.body.style.overflow = 'auto';

                    const hostConnectCard = document.getElementById('host-connect-card');
                    if (hostConnectCard) hostConnectCard.style.display = 'block';

                    const hostDashboard = document.getElementById('host-dashboard');
                    if (hostDashboard) hostDashboard.style.display = 'flex';

                    const mobileScannerView = document.getElementById('mobile-scanner-view');
                    if (mobileScannerView) mobileScannerView.style.display = 'none';

                    const secureWarning = document.getElementById('secure-warning');
                    if (secureWarning) secureWarning.style.display = 'none';

                    // Render Connection QR Code
                    const qrContainer = document.getElementById("qrcode");

                    // Configure Host HTTPS tip if loaded via HTTP
                    if (window.location.protocol !== 'https:' && window.location.hostname !== 'localhost' && window.location.hostname !== '127.0.0.1') {
                        const hostLink = document.getElementById('host-https-link');
                        if (hostLink) {
                            hostLink.href = window.location.href.replace('http://', 'https://');
                        }
                        const hostHttpsTip = document.getElementById('host-https-tip');
                        if (hostHttpsTip) hostHttpsTip.style.display = 'block';
                    }

                    // Render Connection QR Code after layout paint
                    setTimeout(() => {
                        renderHostQRCode("<?= $scanUrl ?>");
                    }, 150);

                // Continuous scans polling for Host Console
                loadHostScans();
                setInterval(loadHostScans, 3000);

                // Load host locators panel
                loadHostLocators();
                setInterval(loadHostLocators, 3000);
            } else {
                // On Mobile (Scanner Mode)
                document.body.style.overflow = 'auto';
                document.getElementById('host-connect-card').style.display = 'none';
                document.getElementById('scanner-section').style.display = 'block';

                if (!isSecure && !navigator.mediaDevices) {
                    document.getElementById('secure-warning').classList.add('active');
                    const switchLink = document.getElementById('secure-switch-link');
                    if (switchLink) {
                        switchLink.href = window.location.href.replace('http://', 'https://');
                    }
                }

                // Fetch available cameras
                Html5Qrcode.getCameras().then(devices => {
                    const select = document.getElementById("camera-select");
                    if (devices && devices.length > 0) {
                        select.innerHTML = '';
                        devices.forEach((device, index) => {
                            const opt = document.createElement("option");
                            opt.value = device.id;
                            // Label back camera automatically
                            const label = device.label || `Camera ${index + 1}`;
                            opt.text = label;
                            // Select back camera as default if label indicates it
                            if (label.toLowerCase().indexOf("back") > -1 || label.toLowerCase().indexOf("rear") > -1 || label.toLowerCase().indexOf("environment") > -1) {
                                opt.selected = true;
                            }
                            select.appendChild(opt);
                        });
                    } else {
                        select.innerHTML = '<option value="">No cameras detected</option>';
                    }
                }).catch(err => {
                    console.error("Camera scan failed: ", err);
                    const select = document.getElementById("camera-select");
                    select.innerHTML = '<option value="">Permission denied / Camera issue</option>';
                    if (!isSecure) {
                        document.getElementById('secure-warning').classList.add('active');
                    }
                });


                // Hide logout, switch, and host-only admin buttons on mobile
                const logoutBtn = document.getElementById('logout-btn');
                if (logoutBtn) logoutBtn.style.display = 'none';
                const switchBtn = document.getElementById('switch-btn');
                if (switchBtn) switchBtn.style.display = 'none';
                const syncBtn = document.getElementById('btn-sync-cloud');
                if (syncBtn) syncBtn.style.display = 'none';
                const closeBtn = document.getElementById('btn-close-store');
                if (closeBtn) closeBtn.style.display = 'none';

                // Load mobile locators searchable list
                loadMobileLocators();

                // Poll active session status to detect host close/disconnect events
                setInterval(checkActiveSessionStatus, 5000);

                // Check active session status to determine if we show the Connect Modal
                const savedName = localStorage.getItem('operator_name');
                const savedLoc = localStorage.getItem('active_locator');

                if (savedName && savedLoc) {
                    // Populate hidden main fields
                    document.getElementById('scanned_by').value = savedName;
                    document.getElementById('location').value = savedLoc;

                    // Strip Slot for display
                    let displayLoc = savedLoc;
                    if (displayLoc.toLowerCase().startsWith('slot ')) {
                        displayLoc = displayLoc.substring(5);
                    } else if (displayLoc.toLowerCase().startsWith('slot')) {
                        displayLoc = displayLoc.substring(4);
                    }

                    document.getElementById('display-operator').innerText = savedName;
                    document.getElementById('display-locator').innerText = displayLoc;
                    document.getElementById('active-session-card').style.display = 'flex';
                    loadMobileScanLogFromServer(savedLoc);
                } else {
                    showConnectModal();
                }
                }
            } catch (e) {
                console.error("Initialization error:", e);
            }

            // Listen for window online/offline
            window.addEventListener('online', updateNetworkStatus);
            window.addEventListener('offline', updateNetworkStatus);
            updateNetworkStatus();
        });

        function updateNetworkStatus() {
            const status = document.getElementById('connection-status');
            if (navigator.onLine) {
                status.innerText = "Online";
                status.className = "connection-status";
            } else {
                status.innerText = "Offline (Local Only)";
                status.className = "connection-status offline";
            }
        }

        // Web Audio Synthesized Scanner Beep (Offline safe)
        function playBeep() {
            if (!document.getElementById('beep-enabled').checked) return;
            try {
                const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                const oscillator = audioCtx.createOscillator();
                const gainNode = audioCtx.createGain();

                oscillator.connect(gainNode);
                gainNode.connect(audioCtx.destination);

                oscillator.type = 'sine';
                oscillator.frequency.value = 1200; // Crisp beep

                gainNode.gain.setValueAtTime(0, audioCtx.currentTime);
                gainNode.gain.linearRampToValueAtTime(0.2, audioCtx.currentTime + 0.02);
                gainNode.gain.linearRampToValueAtTime(0, audioCtx.currentTime + 0.15);

                oscillator.start(audioCtx.currentTime);
                oscillator.stop(audioCtx.currentTime + 0.15);
            } catch (e) {
                console.log("AudioContext failed:", e);
            }
        }

        // Perform vibration haptics
        function vibrate() {
            if (!document.getElementById('vibrate-enabled').checked) return;
            if (navigator.vibrate) {
                navigator.vibrate(80); // 80ms haptic tap
            }
        }

        // Flash green screen
        function triggerScanFlash() {
            const flash = document.getElementById('scan-flash');
            flash.classList.add('active');
            setTimeout(() => {
                flash.classList.remove('active');
            }, 60);
        }

        // Start Scanner
        function startScanner() {
            const cameraId = document.getElementById('camera-select').value;
            if (!cameraId) {
                alert("Please select a camera device.");
                return;
            }

            document.getElementById('scanner-placeholder').style.display = 'none';
            document.getElementById('scanner-active-badge').style.background = 'var(--success-color)';

            html5QrCode = new Html5Qrcode("reader");

            const config = {
                fps: 10,
                qrbox: function (width, height) {
                    // Mobile scanner frame sizing
                    const minEdge = Math.min(width, height);
                    const size = Math.floor(minEdge * 0.75);
                    return {
                        width: size,
                        height: Math.floor(size * 0.5) // Rectangular aspect ratio for barcode scanning
                    };
                },
                aspectRatio: 1.333333 // 4:3 default for mobile cameras
            };

            html5QrCode.start(
                cameraId,
                config,
                onScanSuccess,
                onScanFailure
            )
                .then(() => {
                    isScannerRunning = true;
                    document.getElementById('btn-start').disabled = true;
                    document.getElementById('btn-stop').disabled = false;
                })
                .catch(err => {
                    alert("Failed to start camera: " + err);
                    document.getElementById('scanner-placeholder').style.display = 'flex';
                    document.getElementById('scanner-active-badge').style.background = 'var(--text-muted)';
                });
        }

        // Stop Scanner
        function stopScanner() {
            if (!html5QrCode) return;
            html5QrCode.stop().then(() => {
                isScannerRunning = false;
                document.getElementById('btn-start').disabled = false;
                document.getElementById('btn-stop').disabled = true;
                document.getElementById('scanner-placeholder').style.display = 'flex';
                document.getElementById('scanner-active-badge').style.background = 'var(--text-muted)';
            }).catch(err => {
                console.error("Failed to stop scanner", err);
            });
        }

        // Scan callbacks
        function onScanSuccess(decodedText, decodedResult) {
            if (scanningLock) return; // Locked while processing

            const operatorNameInput = document.getElementById('scanned_by');
            const operatorName = operatorNameInput.value.trim();
            if (operatorName === '') {
                alert("Operator Name is required! Please enter your name before scanning.");
                operatorNameInput.focus();
                operatorNameInput.style.borderColor = 'var(--danger-color)';
                return;
            } else {
                operatorNameInput.style.borderColor = 'rgba(255,255,255,0.08)';
            }

            const locatorSelect = document.getElementById('location');
            const locatorName = locatorSelect.value;
            if (locatorName === '') {
                alert("Locator is required! Please select an open locator first.");
                locatorSelect.focus();
                return;
            }

            playBeep();
            vibrate();
            triggerScanFlash();

            const isContinuous = document.getElementById('continuous-scan').checked;

            if (isContinuous) {
                // Submit instantly with qty 1
                scanningLock = true;
                submitScanToServer(decodedText, 1);
                // Pause code parsing momentarily for next item (prevent duplicate triggers)
                setTimeout(() => {
                    scanningLock = false;
                }, 1800);
            } else {
                // Non-continuous mode: prompt for confirmation & details
                scanningLock = true;
                showManualModal(decodedText);
            }
        }

        function onScanFailure(error) {
            // Quiet fail - barcode not detected in frame
        }

        // Submit Scan Log to Server via Fetch API
        function submitScanToServer(barcode, quantity) {
            const scanned_by = document.getElementById('scanned_by').value;
            const location = document.getElementById('location').value;
            const statusCard = document.getElementById('status-display');
            const statusText = document.getElementById('status-text');
            const statusTitle = document.getElementById('status-title');

            const payload = { barcode, quantity, location, scanned_by };

            fetch('api.php?action=submit_scan', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
                .then(res => res.json())
                .then(data => {
                    statusCard.classList.add('show');
                    if (data.status === 'success') {
                        const info = data.data;
                        statusTitle.innerText = "✓ Scanned Successfully";
                        statusCard.style.borderColor = 'rgba(46, 164, 79, 0.4)';
                        statusCard.style.background = 'rgba(46, 164, 79, 0.08)';

                        let varianceHtml = '';
                        if (info.master_qty !== undefined) {
                            let varianceColor = info.variance < 0 ? '#ff7b72' : (info.variance > 0 ? '#58a6ff' : '#3fb950');
                            let sign = info.variance > 0 ? '+' : '';
                            varianceHtml = `<br>Masterfile Qty: <strong>${info.master_qty}</strong> | Total Scanned: <strong>${info.total_scanned}</strong><br>Variance: <strong style="color:${varianceColor};">${sign}${info.variance}</strong>`;
                        }

                        statusText.innerHTML = `
                        Barcode: <strong style="font-family:monospace;color:white;">${info.barcode}</strong><br>
                        Product: <strong>${info.product_name}</strong> Qty: <strong>${info.quantity}</strong><br>
                        Location: <strong>${info.location}</strong>${varianceHtml}
                    `;

                        // Refresh session log from server database
                        loadMobileScanLogFromServer(location);
                    } else {
                        statusTitle.innerText = "✗ Scan Submission Failed";
                        statusCard.style.borderColor = 'rgba(248, 81, 73, 0.4)';
                        statusCard.style.background = 'rgba(248, 81, 73, 0.08)';
                        statusText.innerText = data.message;
                    }
                })
                .catch(err => {
                    statusCard.classList.add('show');
                    statusTitle.innerText = "✗ Connection Error";
                    statusCard.style.borderColor = 'rgba(248, 81, 73, 0.4)';
                    statusCard.style.background = 'rgba(248, 81, 73, 0.08)';
                    statusText.innerText = "Cannot reach gateway server: " + err;
                });
        }

        // Resolve scanned/typed code to catalog barcode if it matches a SKU
        function resolveBarcodeOrSku(inputVal, callback) {
            fetch('api.php?action=get_products')
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success' && data.products) {
                        const matched = data.products.find(p =>
                            p.barcode === inputVal ||
                            (p.sku && p.sku.toLowerCase() === inputVal.toLowerCase())
                        );
                        if (matched) {
                            callback(matched.barcode, matched);
                            return;
                        }
                    }
                    callback(inputVal, null);
                })
                .catch(err => {
                    console.error("Resolve barcode/sku error:", err);
                    callback(inputVal, null);
                });
        }

        // Display manual confirm modal
        function showManualModal(barcode, preResolvedProduct = null) {
            document.getElementById('modal-barcode').value = barcode;
            document.getElementById('modal-qty').value = "1";

            if (preResolvedProduct) {
                document.getElementById('modal-prod-name').innerText = preResolvedProduct.product_name;
                const mQty = preResolvedProduct.master_qty !== undefined && preResolvedProduct.master_qty !== null ? preResolvedProduct.master_qty : '0';
                document.getElementById('modal-prod-desc').innerText = `SKU: ${preResolvedProduct.sku || 'N/A'} | Master Qty: ${mQty}`;
                document.getElementById('confirm-modal-overlay').classList.add('active');
            } else {
                document.getElementById('modal-prod-name').innerText = "Checking Catalog...";
                document.getElementById('modal-prod-desc').innerText = "Querying MySQL database owi_physical_inventory...";
                document.getElementById('confirm-modal-overlay').classList.add('active');

                // Pre-check product details from database
                fetch('api.php?action=get_products')
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'success' && data.products) {
                            const matched = data.products.find(p => p.barcode === barcode || (p.sku && p.sku.toLowerCase() === barcode.toLowerCase()));
                            if (matched) {
                                document.getElementById('modal-barcode').value = matched.barcode;
                                document.getElementById('modal-prod-name').innerText = matched.product_name;
                                const mQty = matched.master_qty !== undefined && matched.master_qty !== null ? matched.master_qty : '0';
                                document.getElementById('modal-prod-desc').innerText = `SKU: ${matched.sku || 'N/A'} | Master Qty: ${mQty}`;
                            } else {
                                document.getElementById('modal-prod-name').innerText = "Item Not Found";
                                document.getElementById('modal-prod-desc').innerText = "Item not found in catalog.";
                            }
                        }
                    })
                    .catch(err => {
                        document.getElementById('modal-prod-name').innerText = "Catalog Check Failed";
                        document.getElementById('modal-prod-desc').innerText = "MySQL connection timed out.";
                    });
            }
        }

        // Handle modal confirm
        function submitModal(confirmSave) {
            if (confirmSave) {
                const operatorNameInput = document.getElementById('scanned_by');
                const operatorName = operatorNameInput.value.trim();
                if (operatorName === '') {
                    alert("Operator Name is required!");
                    operatorNameInput.focus();
                    operatorNameInput.style.borderColor = 'var(--danger-color)';
                    return;
                }

                const qtyInput = document.getElementById('modal-qty');
                const qty = parseInt(qtyInput.value);
                if (isNaN(qty) || qty <= 0) {
                    alert("Quantity must be a valid positive number greater than 0!");
                    qtyInput.focus();
                    return;
                }

                const barcode = document.getElementById('modal-barcode').value;
                submitScanToServer(barcode, qty);
            }

            // Release lock and hide modal only if cancelled or validation passed
            document.getElementById('confirm-modal-overlay').classList.remove('active');
            setTimeout(() => {
                scanningLock = false;
            }, 1000);
        }

        // Handle manual typed submit
        function handleManualSubmit(event) {
            event.preventDefault();

            const operatorNameInput = document.getElementById('scanned_by');
            const operatorName = operatorNameInput.value.trim();
            if (operatorName === '') {
                alert("Operator Name is required! Please enter your name before submitting.");
                operatorNameInput.focus();
                operatorNameInput.style.borderColor = 'var(--danger-color)';
                return;
            } else {
                operatorNameInput.style.borderColor = 'rgba(255,255,255,0.08)';
            }

            const locatorSelect = document.getElementById('location');
            const locatorName = locatorSelect.value;
            if (locatorName === '') {
                alert("Locator is required! Please select an open locator first.");
                locatorSelect.focus();
                return;
            }

            const input = document.getElementById('manual-barcode');
            const typedVal = input.value.trim();
            if (typedVal === '') return;

            playBeep();
            vibrate();

            const isContinuous = document.getElementById('continuous-scan').checked;

            resolveBarcodeOrSku(typedVal, function (resolvedBarcode, product) {
                if (isContinuous) {
                    submitScanToServer(resolvedBarcode, 1);
                } else {
                    showManualModal(resolvedBarcode, product);
                }
            });
            input.value = '';
        }

        // Fetch and display active mobile scanner locator logs from MySQL server database
        function loadMobileScanLogFromServer(locatorName) {
            fetch('api.php?action=get_scans')
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success' && data.scans) {
                        const scans = data.scans.filter(scan => scan.location.toLowerCase() === locatorName.toLowerCase());
                        const container = document.getElementById('session-log');
                        container.innerHTML = '';

                        if (scans.length > 0) {
                            let html = '';
                            scans.forEach(scan => {
                                const timeStr = scan.scanned_at ? scan.scanned_at.split(' ')[1] || scan.scanned_at : 'N/A';
                                let displayLoc = scan.location || '';
                                if (displayLoc.toLowerCase().startsWith('slot ')) {
                                    displayLoc = displayLoc.substring(5);
                                } else if (displayLoc.toLowerCase().startsWith('slot')) {
                                    displayLoc = displayLoc.substring(4);
                                }

                                html += `
                                    <div class="scan-log-item">
                                        <div style="flex-grow: 1; padding-right: 10px;">
                                            <span class="scan-log-barcode">${scan.barcode}</span>
                                            <br><span style="font-size:0.8rem; color:var(--text-white); font-weight:500;">${scan.product_name || '<span style="color:var(--text-muted);">Item Not in Catalog</span>'}</span>
                                        </div>
                                        <div class="scan-log-meta" style="display:flex; flex-direction:column; align-items:flex-end; gap:4px; flex-shrink:0;">
                                            <span style="font-weight:600; color:var(--text-white);">Qty: ${parseFloat(scan.quantity).toFixed(0)}</span>
                                            <span style="font-size:0.7rem; color:var(--text-muted);">${timeStr} (${displayLoc})</span>
                                            <button class="btn btn-secondary btn-sm" onclick="openEditScanModal(${scan.id}, '${scan.barcode}', ${scan.quantity})" style="padding: 2px 6px; font-size:0.65rem; width:auto; height:auto; margin:4px 0 0 0; border-radius:4px; cursor:pointer; font-weight:600;">Edit</button>
                                        </div>
                                    </div>
                                `;
                            });
                            container.innerHTML = html;
                        } else {
                            container.innerHTML = `
                                <div class="empty-state" style="text-align:center; padding: 15px; font-size: 0.8rem; color: var(--text-muted);">
                                    Scan logs from this terminal will display here.
                                </div>
                            `;
                        }
                    }
                })
                .catch(err => {
                    console.error("Error loading mobile session scans log:", err);
                });
        };

        // Poll active session status to detect host close/disconnect events
        function checkActiveSessionStatus() {
            const savedName = localStorage.getItem('operator_name');
            const savedLoc = localStorage.getItem('active_locator');

            if (!savedName || !savedLoc) return; // not connected

            fetch('api.php?action=get_locators')
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success' && data.locators) {
                        const matched = data.locators.find(loc => loc.locator_name.toLowerCase() === savedLoc.toLowerCase());

                        // If locator is not found, or is not in_use, or is claimed by another operator
                        if (!matched || matched.status !== 'in_use' || !matched.assigned_operator || matched.assigned_operator.toLowerCase() !== savedName.toLowerCase()) {
                            // Disconnect mobile!
                            localStorage.removeItem('operator_name');
                            localStorage.removeItem('active_locator');

                            document.getElementById('scanned_by').value = '';
                            document.getElementById('location').value = '';

                            document.getElementById('active-session-card').style.display = 'none';
                            showConnectModal();

                            // Stop scanner camera
                            stopScanner();

                            alert(`Session Disconnected: Locator "${savedLoc}" was closed or re-assigned by the host.`);
                        }
                    }
                })
                .catch(err => {
                    console.warn("Session check failed (possible network issue): ", err);
                });
        }

        // Change Active Store
        function logoutStore() {
            customConfirm("Are you sure you want to change store session?", () => {
                fetch('api.php?action=logout_store')
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'success') {
                            window.location.reload();
                        }
                    });
            }, "Change Store");
        }

        // Fetch all store scan records and display in host terminal table
        function loadHostScans() {
            fetch('api.php?action=get_scans')
                .then(res => res.json())
                .then(data => {
                    const tbody = document.getElementById('host-scans-tbody');
                    const totalQtyEl = document.getElementById('metric-total-qty');
                    const uniqueBarcodesEl = document.getElementById('metric-unique-barcodes');
                    const activeScannersEl = document.getElementById('metric-active-scanners');

                    if (data.status === 'success' && data.scans) {
                        const scans = data.scans;

                        // Compute Metrics
                        let totalQty = 0;
                        const uniqueBarcodes = new Set();

                        scans.forEach(scan => {
                            totalQty += parseFloat(scan.quantity || 0);
                            uniqueBarcodes.add(scan.barcode);
                        });

                        // Update metrics UI
                        totalQtyEl.innerText = totalQty.toFixed(0);
                        uniqueBarcodesEl.innerText = uniqueBarcodes.size;

                        // Update table body UI
                        if (scans.length > 0) {
                            let html = '';
                            scans.forEach(scan => {
                                const timeStr = scan.scanned_at ? scan.scanned_at.split(' ')[1] || scan.scanned_at : 'N/A';

                                // Strip "Slot " to show just the number
                                let displayLoc = scan.location || '';
                                if (displayLoc.toLowerCase().startsWith('slot ')) {
                                    displayLoc = displayLoc.substring(5);
                                } else if (displayLoc.toLowerCase().startsWith('slot')) {
                                    displayLoc = displayLoc.substring(4);
                                }

                                html += `
                                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                                        <td style="padding: 12px 10px; font-family:monospace; color:#58a6ff; font-weight:600;">${scan.barcode}</td>
                                        <td style="padding: 12px 10px; color:var(--text-white); font-weight:500;">${scan.product_name || '<span style="color:var(--text-muted);">Item Not in Catalog</span>'}</td>
                                        <td style="padding: 12px 10px; text-align: center; font-weight:700; color:var(--text-white); font-size:1rem;">${parseFloat(scan.quantity).toFixed(0)}</td>
                                        <td style="padding: 12px 10px; color:#c9d1d9;">${scan.scanned_by || 'Unknown'}</td>
                                        <td style="padding: 12px 10px; text-align: center; color:var(--text-white); font-weight:600;"><span class="badge" style="background:rgba(210,153,34,0.15); color:#d29922; font-size:0.75rem; padding: 2px 6px; border-radius: 4px;">${displayLoc}</span></td>
                                        <td style="padding: 12px 10px; text-align: right; color:var(--text-muted); font-size:0.8rem;">${timeStr}</td>
                                    </tr>
                                `;
                            });
                            tbody.innerHTML = html;
                            filterHostScans();
                        } else {
                            tbody.innerHTML = `
                                <tr>
                                    <td colspan="6" style="text-align: center; padding: 30px; color: var(--text-muted);">
                                        No scans logged yet. Connect a mobile device to start scanning!
                                    </td>
                                </tr>
                            `;
                        }
                    }
                })
                .catch(err => console.error("Error loading host scans:", err));
        }

        // Live incoming scans search filter function
        function filterHostScans() {
            const tbody = document.getElementById('host-scans-tbody');
            const searchInput = document.getElementById('host-scans-search');
            if (tbody && searchInput) {
                const q = searchInput.value.toLowerCase().trim();
                const rows = tbody.querySelectorAll('tr');
                rows.forEach(row => {
                    if (row.cells.length === 1) return; // skip feedback rows
                    const text = row.innerText.toLowerCase();
                    if (q === '' || text.indexOf(q) > -1) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            }
        }

        // Load mobile locators searchable list for modal datalist
        function loadMobileLocators() {
            fetch('api.php?action=get_locators')
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success' && data.locators) {
                        window.availableLocators = data.locators; // Store globally

                        const datalist = document.getElementById('modal-locator-list');
                        datalist.innerHTML = '';

                        data.locators.forEach(loc => {
                            if (loc.status !== 'closed') {
                                const opt = document.createElement('option');
                                opt.value = loc.locator_name;

                                let displayName = loc.locator_name || '';
                                if (displayName.toLowerCase().startsWith('slot ')) {
                                    displayName = displayName.substring(5);
                                } else if (displayName.toLowerCase().startsWith('slot')) {
                                    displayName = displayName.substring(4);
                                }

                                if (loc.status === 'in_use' && loc.assigned_operator) {
                                    opt.text = `${displayName} (In Use by ${loc.assigned_operator})`;
                                } else {
                                    opt.text = displayName;
                                }
                                datalist.appendChild(opt);
                            }
                        });
                    }
                })
                .catch(err => console.error("Error loading locators:", err));
        }

        // Finish and Close Active Locator on Mobile
        function closeActiveLocator() {
            const select = document.getElementById('location');
            const val = select.value;
            if (val === '') {
                customAlert("No active locator selected to close.");
                return;
            }

            customConfirm(`Are you sure you want to finish and CLOSE locator "${val}"?\nOnce closed, you cannot scan into this locator again until the Host approves.`, () => {
                fetch('api.php?action=close_locator', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ locator_name: val })
                })
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'success') {
                            customAlert(`Locator "${val}" has been successfully closed!`, "Success", () => {
                                select.value = ''; // Reset
                                localStorage.removeItem('active_locator'); // Clear persistence

                                // Hide session card & reopen setup modal
                                document.getElementById('active-session-card').style.display = 'none';
                                showConnectModal();
                            });
                        } else {
                            customAlert(data.message);
                        }
                    })
                    .catch(err => customAlert("Error closing locator: " + err));
            }, "Close Locator");
        }

        // Host Panel Actions - Auto Increment Add
        function autoAddNextLocator() {
            let maxNum = 0;
            const list = window.hostLocators || [];

            list.forEach(loc => {
                const matches = loc.locator_name.match(/\d+/g);
                if (matches) {
                    matches.forEach(m => {
                        const val = parseInt(m, 10);
                        if (val > maxNum) {
                            maxNum = val;
                        }
                    });
                }
            });

            const nextNum = maxNum + 1;
            const nextName = "Slot " + nextNum;

            fetch('api.php?action=add_locator', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ locator_name: nextName })
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        loadHostLocators();
                    } else {
                        alert(data.message);
                    }
                })
                .catch(err => alert("Error adding locator: " + err));
        }
        function handleDeleteLocator(id) {
            customConfirm("Are you sure you want to delete this locator? This will delete all scan records associated with it!", () => {
                fetch('api.php?action=delete_locator', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id })
                })
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'success') {
                            loadHostLocators();
                        } else {
                            customAlert(data.message);
                        }
                    })
                    .catch(err => customAlert("Error deleting locator: " + err));
            }, "Delete Locator");
        }
        function handleCloseLocatorName(name, isForce = true) {
            const msg = isForce
                ? `Are you sure you want to force close locator "${name}"?`
                : `Are you sure you want to close locator "${name}"?`;
            customConfirm(msg, () => {
                fetch('api.php?action=close_locator', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ locator_name: name })
                })
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'success') {
                            loadHostLocators();
                        } else {
                            customAlert(data.message);
                        }
                    })
                    .catch(err => customAlert("Error closing locator: " + err));
            }, "Close Locator");
        }
        function handleApproveLocator(id, name) {
            customConfirm(`Are you sure you want to reopen locator "${name}"?`, () => {
                fetch('api.php?action=approve_locator', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id })
                })
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'success') {
                            loadHostLocators();
                        } else {
                            customAlert(data.message);
                        }
                    })
                    .catch(err => customAlert("Error reopening locator: " + err));
            }, "Reopen Locator");
        }

        // Load host countsheet & locators list
        function loadHostLocators() {
            fetch('api.php?action=get_locators')
                .then(res => res.json())
                .then(data => {
                    const tbody = document.getElementById('host-locators-tbody');
                    if (data.status === 'success' && data.locators) {
                        window.hostLocators = data.locators; // Keep global copy for increment calculation
                        const searchInput = document.getElementById('host-locator-search');
                        const q = searchInput ? searchInput.value.toLowerCase().trim() : '';

                        let html = '';
                        let renderedCount = 0;
                        let activeScannersCount = 0;

                        if (data.locators.length > 0) {
                            data.locators.forEach(loc => {
                                if (loc.status === 'in_use') {
                                    activeScannersCount++;
                                }
                                // Extract display name
                                let displayName = loc.locator_name;
                                if (displayName.toLowerCase().startsWith('slot ')) {
                                    displayName = displayName.substring(5);
                                } else if (displayName.toLowerCase().startsWith('slot')) {
                                    displayName = displayName.substring(4);
                                }

                                const operator = (loc.assigned_operator || '').toLowerCase();
                                const status = (loc.status || '').toLowerCase();
                                const searchName = displayName.toLowerCase();

                                // Search filter check
                                if (q !== '' && !searchName.includes(q) && !operator.includes(q) && !status.includes(q)) {
                                    return; // skip rendering
                                }

                                renderedCount++;

                                let statusBadge = '';
                                let actionBtn = '';

                                if (loc.status === 'open') {
                                    statusBadge = `<span class="badge" style="background:rgba(46,164,79,0.15); color:#2ea44f; font-size:0.7rem; padding: 2px 6px; border-radius: 4px;">Open</span>`;
                                    actionBtn = `
                                        <button class="btn btn-sm" onclick="handleCloseLocatorName('${loc.locator_name}', false)" style="padding:4px 8px; font-size:0.7rem; background:rgba(210,153,34,0.1); color:#d29922; border:1px solid rgba(210,153,34,0.2); border-radius:4px; margin:0; cursor:pointer; font-weight:600;">Close</button>
                                        <button class="btn btn-sm" onclick="handleDeleteLocator(${loc.id})" style="padding:4px 8px; font-size:0.7rem; background:rgba(248,81,73,0.1); color:#f85149; border:1px solid rgba(248,81,73,0.2); border-radius:4px; margin:0; cursor:pointer; font-weight:600;">Delete</button>
                                    `;
                                } else if (loc.status === 'in_use') {
                                    statusBadge = `<span class="badge" style="background:rgba(210,153,34,0.15); color:#d29922; font-size:0.7rem; padding: 2px 6px; border-radius: 4px;">In Use</span>`;
                                    actionBtn = `<button class="btn btn-sm" onclick="handleCloseLocatorName('${loc.locator_name}', true)" style="padding:4px 8px; font-size:0.7rem; background:rgba(210,153,34,0.1); color:#d29922; border:1px solid rgba(210,153,34,0.2); border-radius:4px; margin:0; cursor:pointer; font-weight:600;">Force Close</button>`;
                                } else if (loc.status === 'closed') {
                                    statusBadge = `<span class="badge" style="background:rgba(248,81,73,0.15); color:#f85149; font-size:0.7rem; padding: 2px 6px; border-radius: 4px;">Closed</span>`;
                                    actionBtn = `<button class="btn btn-sm" onclick="handleApproveLocator(${loc.id}, '${loc.locator_name}')" style="padding:4px 8px; font-size:0.7rem; background:rgba(46,164,79,0.1); color:#2ea44f; border:1px solid rgba(46,164,79,0.2); border-radius:4px; margin:0; cursor:pointer; font-weight:600; box-shadow:none;">Open</button>`;
                                }

                                let viewBtn = `<button class="btn btn-sm" onclick="viewLocatorScans('${loc.locator_name}')" style="padding:4px 8px; font-size:0.7rem; background:rgba(88,166,255,0.1); color:#58a6ff; border:1px solid rgba(88,166,255,0.2); border-radius:4px; margin:0; cursor:pointer; font-weight:600;">View</button>`;

                                html += `
                                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                                        <td style="padding: 6px; font-weight:600; color:var(--text-white); font-size:0.8rem;">
                                            ${displayName} <span style="font-weight:normal; color:var(--text-muted); font-size:0.75rem; margin-left: 20px;">( ${parseInt(loc.total_scans || 0)} - items scanned )</span>
                                        </td>
                                        <td style="padding: 6px;">${statusBadge}</td>
                                        <td style="padding: 6px; color:#c9d1d9; font-size:0.8rem;">${loc.assigned_operator || '-'}</td>
                                        <td style="padding: 6px; text-align: center; display:flex; gap:4px; justify-content:center;">${viewBtn} ${actionBtn}</td>
                                    </tr>
                                `;
                            });

                            if (renderedCount === 0) {
                                tbody.innerHTML = `<tr><td colspan="4" style="text-align:center; padding:20px; color:var(--text-muted);">No matching locators found.</td></tr>`;
                            } else {
                                tbody.innerHTML = html;
                            }
                        } else {
                            tbody.innerHTML = `<tr><td colspan="4" style="text-align:center; padding:20px; color:var(--text-muted);">No locators registered.</td></tr>`;
                        }

                        // Update active scanners counter badge
                        const activeScannersEl = document.getElementById('metric-active-scanners');
                        if (activeScannersEl) {
                            activeScannersEl.innerText = activeScannersCount;
                        }

                        // Update Progress Dashboard Widget
                        const totalLocs = data.locators.length;
                        const closedLocs = data.locators.filter(loc => loc.status === 'closed').length;
                        const openLocs = totalLocs - closedLocs;
                        const percent = totalLocs > 0 ? Math.round((closedLocs / totalLocs) * 100) : 0;

                        // Circular ring math (r=48, circumference is 301.6)
                        const circ = 301.6;
                        const offset = circ - (percent / 100) * circ;

                        const progressCircle = document.getElementById('widget-progress-circle');
                        const progressText = document.getElementById('widget-progress-text');
                        const closedCountEl = document.getElementById('widget-closed-count');
                        const openCountEl = document.getElementById('widget-open-count');
                        const totalCountEl = document.getElementById('widget-total-count');

                        if (progressCircle) {
                            progressCircle.style.strokeDashoffset = offset;
                        }
                        if (progressText) {
                            progressText.querySelector('span').innerText = percent + '%';
                        }
                        if (closedCountEl) closedCountEl.innerText = closedLocs;
                        if (openCountEl) openCountEl.innerText = openLocs;
                        if (totalCountEl) totalCountEl.innerText = totalLocs;

                        // Enable/disable the print summary button based on completion percent
                        const printSummaryBtn = document.getElementById('btn-print-summary');
                        if (printSummaryBtn) {
                            if (percent < 100) {
                                printSummaryBtn.disabled = true;
                                printSummaryBtn.style.opacity = '0.4';
                                printSummaryBtn.style.cursor = 'not-allowed';
                                printSummaryBtn.title = `Completion progress is ${percent}%. All locators must be closed to print summary.`;
                            } else {
                                printSummaryBtn.disabled = false;
                                printSummaryBtn.style.opacity = '1';
                                printSummaryBtn.style.cursor = 'pointer';
                                printSummaryBtn.title = "Print 100% completion summary sheet";
                            }
                        }
                    }
                })
                .catch(err => console.error("Error loading host locators:", err));
        }

        function filterHostLocators() {
            const tbody = document.getElementById('host-locators-tbody');
            if (tbody) {
                const q = document.getElementById('host-locator-search').value.toLowerCase().trim();
                const rows = tbody.querySelectorAll('tr');
                let matchCount = 0;
                rows.forEach(row => {
                    if (row.cells.length === 1) return; // skip feedback rows
                    const text = row.innerText.toLowerCase();
                    if (text.indexOf(q) > -1) {
                        row.style.display = '';
                        matchCount++;
                    } else {
                        row.style.display = 'none';
                    }
                });
            }
        }

        // Setup Connect Modal helpers
        function showConnectModal() {
            // Stop scanner if running
            if (isScannerRunning) {
                stopScanner();
            }

            // Populate Operator Name from LocalStorage
            const savedName = localStorage.getItem('operator_name');
            if (savedName) {
                document.getElementById('modal-scanned_by').value = savedName;
            }

            // Clear location input
            document.getElementById('modal-location').value = '';

            // Render setup overlay
            document.getElementById('mobile-connect-modal').style.display = 'flex';

            // Refresh list
            loadMobileLocators();

            // Auto focus
            setTimeout(() => {
                if (savedName) {
                    document.getElementById('modal-location').focus();
                } else {
                    document.getElementById('modal-scanned_by').focus();
                }
            }, 100);
        }

        function handleMobileConnect(e) {
            e.preventDefault();

            const operatorName = document.getElementById('modal-scanned_by').value.trim();
            const locatorVal = document.getElementById('modal-location').value.trim();

            if (operatorName === '') {
                alert("Operator Name is required.");
                document.getElementById('modal-scanned_by').focus();
                return;
            }
            if (locatorVal === '') {
                alert("Locator selection is required.");
                document.getElementById('modal-location').focus();
                return;
            }

            // Normalize matched locator (numeric or exact match)
            let matched = (window.availableLocators || []).find(loc =>
                loc.locator_name.toLowerCase() === locatorVal.toLowerCase() ||
                loc.locator_name.toLowerCase().replace('slot ', '').trim() === locatorVal.toLowerCase()
            );

            if (!matched) {
                alert(`Locator "${locatorVal}" does not exist in the count sheet list. Please select a valid open locator.`);
                document.getElementById('modal-location').focus();
                return;
            }

            // Check if occupied by another operator
            if (matched.status === 'in_use' && matched.assigned_operator) {
                if (matched.assigned_operator.toLowerCase() !== operatorName.toLowerCase()) {
                    alert(`This locator is already claimed by operator: ${matched.assigned_operator}`);
                    document.getElementById('modal-location').focus();
                    return;
                }
            }

            // Submit claim to api
            fetch('api.php?action=claim_locator', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ locator_name: matched.locator_name, scanned_by: operatorName })
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        // Update main page hidden inputs
                        document.getElementById('scanned_by').value = operatorName;
                        document.getElementById('location').value = matched.locator_name;

                        // Format output
                        let displayLoc = matched.locator_name;
                        if (displayLoc.toLowerCase().startsWith('slot ')) {
                            displayLoc = displayLoc.substring(5);
                        } else if (displayLoc.toLowerCase().startsWith('slot')) {
                            displayLoc = displayLoc.substring(4);
                        }

                        // Update screen header details
                        document.getElementById('display-operator').innerText = operatorName;
                        document.getElementById('display-locator').innerText = displayLoc;

                        // Save to local storage
                        localStorage.setItem('operator_name', operatorName);
                        localStorage.setItem('active_locator', matched.locator_name);

                        // Hide Modal & Show active card
                        document.getElementById('mobile-connect-modal').style.display = 'none';
                        document.getElementById('active-session-card').style.display = 'flex';

                        alert(`Connected successfully as ${operatorName} in Locator ${displayLoc}!`);
                        loadMobileScanLogFromServer(matched.locator_name);

                        // Trigger camera start
                        startScanner();
                    } else {
                        alert(data.message);
                    }
                })
                .catch(err => {
                    alert("Failed to connect: " + err);
                });
        }

        // Host Locator Scan Inspector Actions
        function viewLocatorScans(locatorName) {
            window.currentEditingLocatorName = locatorName;
            document.getElementById('view-scans-locator-title').innerText = `Items in Locator: ${locatorName}`;

            const tbody = document.getElementById('view-scans-tbody');
            tbody.innerHTML = `<tr><td colspan="5" style="text-align:center; padding:20px; color:var(--text-muted);">Loading scans...</td></tr>`;
            document.getElementById('host-view-locator-scans-modal-overlay').classList.add('active');

            loadLocatorScansTable(locatorName);
        }

        function closeLocatorScansModal() {
            document.getElementById('host-view-locator-scans-modal-overlay').classList.remove('active');
        }

        // Print locator count sheet matching HHTGW Print.txt format
        function printLocatorScans() {
            const locatorName = window.currentEditingLocatorName || 'Unknown';
            let displayLoc = locatorName;
            if (displayLoc.toLowerCase().startsWith('slot ')) {
                displayLoc = displayLoc.substring(5);
            } else if (displayLoc.toLowerCase().startsWith('slot')) {
                displayLoc = displayLoc.substring(4);
            }

            fetch('api.php?action=get_scans')
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success' && data.scans) {
                        const scans = data.scans.filter(scan => scan.location.toLowerCase() === locatorName.toLowerCase());

                        if (scans.length === 0) {
                            alert("No scans available in this locator to print.");
                            return;
                        }

                        // Order scans by RecNo (chronological order)
                        scans.reverse(); // API returns DESC, we reverse it to ASC for printing

                        // Helper padding functions for alignment
                        const padRight = (str, len) => {
                            str = String(str || '');
                            return str + ' '.repeat(Math.max(0, len - str.length));
                        };
                        const centerText = (str, len = 84) => {
                            str = String(str || '').trim();
                            if (str.length >= len) return str;
                            const padLeft = Math.floor((len - str.length) / 2);
                            return ' '.repeat(padLeft) + str;
                        };

                        const now = new Date();
                        const countDateStr = now.toLocaleDateString('en-US', { day: '2-digit', month: '2-digit', year: 'numeric' });

                        let text = '';
                        text += centerText(`OFFICE WAREHOUSE INC - ${storeCode}`) + '\r\n';
                        text += centerText('Annual Inventory Count') + '\r\n\r\n';
                        text += centerText('*****    Initial Count Sheet   *****') + '\r\n\r\n';
                        text += `Locator No. : ${displayLoc}\r\n`;
                        text += `Count Date. : ${countDateStr}\r\n\r\n`;

                        // Header columns row
                        text += padRight('Rec No', 6) + padRight('UPC', 15) + padRight('SKU', 6) + padRight('Description', 41) + padRight('Count', 9) + 'Remarks\r\n\r\n';

                        let grandTotal = 0;
                        let infCount = 0;

                        scans.forEach((scan, index) => {
                            const recNo = index + 1;
                            const barcode = scan.barcode || '';
                            const sku = scan.sku || '';
                            const descr = scan.product_name || 'Item Not Found';
                            const qtyVal = parseFloat(scan.quantity || 0);
                            const qtyStr = qtyVal.toFixed(0);

                            grandTotal += qtyVal;
                            if (scan.product_name === 'Item Not Found' || scan.product_name === 'Unknown Product') {
                                infCount++;
                            }

                            // Generate formatted row with precise spacing
                            text += padRight(recNo, 6) +
                                padRight(barcode, 15) +
                                padRight(sku, 6) +
                                padRight(descr, 41) +
                                padRight(qtyStr, 9) +
                                '_______\r\n';
                        });

                        // Get unique operators who scanned in this locator
                        const operators = [...new Set(scans.map(scan => scan.scanned_by).filter(Boolean))];
                        const scannedByNames = operators.join(', ').toUpperCase();

                        const padCenter = (str, len) => {
                            str = String(str || '').trim();
                            if (str.length >= len) return str.substring(0, len);
                            const padLeft = Math.floor((len - str.length) / 2);
                            return ' '.repeat(padLeft) + str + ' '.repeat(len - str.length - padLeft);
                        };

                        text += '\r\n';
                        text += padRight(`Number of Records Scanned: ${scans.length}`, 52) + `GRAND TOTAL : ${grandTotal.toFixed(0)}\r\n`;
                        text += `No. of INF Found : ${infCount}\r\n\r\n`;

                        text += '       <span style="position:relative; top:12px; font-weight:600;">' + padCenter(scannedByNames, 12) + '</span>                          \r\n';
                        text += '       ____________            ____________               ____________\r\n';
                        text += '        Scanned  By             Counted By                 Checked By\r\n\r\n\r\n\r\n';
                        text += '              ____________                    ____________\r\n';
                        text += '               Team Leader                     Posted By\r\n';

                        // Open print frame window
                        const printWin = window.open('', '', 'width=800,height=600');
                        printWin.document.open();
                        printWin.document.write(`
                            <html>
                            <head>
                                <title>Print Locator Scans - ${locatorName}</title>
                                <style>
                                    @page {
                                        margin: 0;
                                    }
                                    @media print {
                                        body { 
                                            margin: 0; 
                                            padding-top: ${printMarginTop}mm; 
                                            padding-left: ${printMarginLeft}mm; 
                                            background: white; 
                                            color: black; 
                                        }
                                    }
                                    body {
                                        font-family: monospace;
                                        white-space: pre;
                                        font-size: 13px;
                                        line-height: 1.1;
                                        background: white;
                                        color: black;
                                        padding-top: ${printMarginTop}mm;
                                        padding-left: ${printMarginLeft}mm;
                                        margin: 0;
                                    }
                                    pre {
                                        margin: 0;
                                        padding: 0;
                                        font-family: monospace;
                                        line-height: 1.1;
                                    }
                                </style>
                            </head>
                            <body>
                                <pre>${text}</pre>
                                <script>
                                    window.onload = function() {
                                        window.print();
                                        window.close();
                                    }
                                <\/script>
                            </body>
                            </html>
                        `);
                        printWin.document.close();
                    }
                });
        }

        // Print store count summary matching HHTGW summary format
        function printStoreSummary() {
            const progressText = document.getElementById('widget-progress-text');
            if (progressText) {
                const percent = parseInt(progressText.querySelector('span').innerText) || 0;
                if (percent < 100) {
                    alert(`Cannot print summary. Completion progress is only ${percent}%. All locators must be closed to print summary.`);
                    return;
                }
            }

            fetch('api.php?action=get_scans')
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success' && data.scans) {
                        const scans = data.scans;

                        if (scans.length === 0) {
                            alert("No scans available in the system to print.");
                            return;
                        }

                        // Group scans by Barcode/UPC
                        // Group scans by Barcode/UPC
                        const summaryMap = {};
                        scans.forEach(scan => {
                            const barcode = scan.barcode;
                            if (!summaryMap[barcode]) {
                                summaryMap[barcode] = {
                                    barcode: barcode,
                                    sku: scan.sku || 'N/A',
                                    description: scan.product_name || 'Item Not Found',
                                    masterQty: parseFloat(scan.master_qty || 0),
                                    totalQty: 0
                                };
                            }
                            summaryMap[barcode].totalQty += parseFloat(scan.quantity || 0);
                        });

                        // Convert to array and sort by description alphabetically
                        const items = Object.values(summaryMap);
                        items.sort((a, b) => a.description.localeCompare(b.description));

                        let infCount = 0;
                        items.forEach(item => {
                            if (item.description === 'Item Not Found' || item.description === 'Unknown Product') {
                                infCount++;
                            }
                        });

                        const padQtyCenter = (str, len = 10) => {
                            str = String(str || '').trim();
                            if (str.length >= len) return str;
                            const padLeft = Math.floor((len - str.length) / 2);
                            return ' '.repeat(padLeft) + str + ' '.repeat(len - str.length - padLeft);
                        };

                        const now = new Date();
                        const countDateStr = now.toLocaleDateString('en-US', { day: '2-digit', month: '2-digit', year: 'numeric' });

                        const padRight = (str, len) => {
                            str = String(str || '');
                            return str + ' '.repeat(Math.max(0, len - str.length));
                        };
                        const centerText = (str, len = 97) => {
                            str = String(str || '').trim();
                            if (str.length >= len) return str;
                            const padLeft = Math.floor((len - str.length) / 2);
                            return ' '.repeat(padLeft) + str;
                        };

                        let text = '';
                        text += centerText(`OFFICE WAREHOUSE INC - ${storeCode}`) + '\r\n';
                        text += centerText('Annual Inventory Count') + '\r\n\r\n';
                        text += centerText('*****   Inventory Count Summary (100% Completion)   *****') + '\r\n\r\n';
                        text += `Count Date. : ${countDateStr}\r\n\r\n`;

                        // Header columns row
                        text += padRight('Rec No', 8) + padRight('UPC', 16) + padRight('SKU', 8) + padRight('Description', 35) + padQtyCenter('Mst Qty', 10) + padQtyCenter('Total Qty', 10) + padQtyCenter('Variance', 10) + '\r\n';
                        text += '<span style="display: block; border-bottom: 1.5px solid #333; margin: 4px 0;"></span>';

                        let grandTotal = 0;

                        items.forEach((item, index) => {
                            const recNo = index + 1;
                            const barcode = item.barcode || '';
                            const sku = item.sku || '';
                            const descr = item.description || 'Item Not Found';
                            const mstQtyVal = item.masterQty;
                            const qtyVal = item.totalQty;
                            const varianceVal = qtyVal - mstQtyVal;
                            
                            const mstQtyStr = mstQtyVal.toFixed(0);
                            const qtyStr = qtyVal.toFixed(0);
                            const varianceStr = (varianceVal >= 0 ? '+' : '') + varianceVal.toFixed(0);

                            grandTotal += qtyVal;

                            text += padRight(recNo, 8) +
                                padRight(barcode, 16) +
                                padRight(sku, 8) +
                                padRight(descr, 35) +
                                padQtyCenter(mstQtyStr, 10) +
                                padQtyCenter(qtyStr, 10) +
                                padQtyCenter(varianceStr, 10) + '\r\n';
                            text += '<span style="display: block; border-bottom: 1px dashed #ddd; margin: 3px 0;"></span>';
                        });

                        text += '\r\n';
                        const totalInfStr = `Total INF items: ${infCount}`;
                        const grandTotalLabel = "GRAND TOTAL : ";
                        const spacesNeeded = 85 - totalInfStr.length - grandTotalLabel.length;
                        const spacing = ' '.repeat(Math.max(0, spacesNeeded));

                        text += totalInfStr + spacing + grandTotalLabel + padQtyCenter(grandTotal.toFixed(0), 10) + '\r\n';

                        // Open print frame window
                        const printWin = window.open('', '', 'width=800,height=600');
                        printWin.document.open();
                        printWin.document.write(`
                            <html>
                            <head>
                                <title>Inventory Count Summary - ${storeCode}</title>
                                <style>
                                    @page {
                                        margin: 0;
                                    }
                                    @media print {
                                        body { 
                                            margin: 0; 
                                            padding-top: ${printMarginTop}mm; 
                                            padding-left: ${printMarginLeft}mm; 
                                            background: white; 
                                            color: black; 
                                        }
                                    }
                                    body {
                                        font-family: monospace;
                                        white-space: pre;
                                        font-size: 13px;
                                        line-height: 1.1;
                                        background: white;
                                        color: black;
                                        padding-top: ${printMarginTop}mm;
                                        padding-left: ${printMarginLeft}mm;
                                        margin: 0;
                                    }
                                    pre {
                                        margin: 0;
                                        padding: 0;
                                        font-family: monospace;
                                        line-height: 1.1;
                                    }
                                </style>
                            </head>
                            <body>
                                <pre>${text}</pre>
                                <script>
                                    window.onload = function() {
                                        window.print();
                                        window.close();
                                    }
                                <\/script>
                            </body>
                            </html>
                        `);
                        printWin.document.close();
                        sessionStorage.setItem('summary_printed_' + storeCode, 'true');
                    }
                })
                .catch(err => {
                    console.error("Print summary error:", err);
                    alert("Failed to load scans for summary print: " + err);
                });
        }

        // Close the entire store session after validations
        function closeStoreSession() {
            // 1. Get locator completion percent
            const progressText = document.getElementById('widget-progress-text');
            let percent = 0;
            if (progressText) {
                percent = parseInt(progressText.querySelector('span').innerText) || 0;
            }

            if (percent < 100) {
                customAlert(`Cannot close the store. Locator completion progress must be 100% (currently ${percent}%). All locators must be closed first.`, "Error");
                return;
            }

            // 2. Check if Print Summary was printed in this browser session
            const wasPrinted = sessionStorage.getItem('summary_printed_' + storeCode) === 'true';
            if (!wasPrinted) {
                customAlert("Cannot close the store. You must click 'Print Summary' to print the completion sheet first.", "Print Required");
                return;
            }

            // 3. Confirm closure using customConfirm
            customConfirm(
                `Are you sure you want to CLOSE the store session "${storeCode}"?\n\nThis will lock all count sheets, set the store status to closed, and return you to the Store Selector.\nThis action cannot be undone.`,
                () => {
                    fetch('api.php?action=close_store', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ store_code: storeCode })
                    })
                        .then(res => res.json())
                        .then(data => {
                            if (data.status === 'success') {
                                customAlert(data.message, "Success", () => {
                                    window.location.href = 'index.php';
                                });
                            } else {
                                customAlert("Error: " + data.message, "Failure");
                            }
                        })
                        .catch(err => customAlert("Request failed to close store: " + err, "Error"));
                },
                "Close Store Session"
            );
        }

        // Print edited count sheet for dynamic locator
        function printEditedCountSheet(locatorName) {
            let displayLoc = locatorName;
            if (displayLoc.toLowerCase().startsWith('slot ')) {
                displayLoc = displayLoc.substring(5);
            } else if (displayLoc.toLowerCase().startsWith('slot')) {
                displayLoc = displayLoc.substring(4);
            }

            fetch('api.php?action=get_scans')
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success' && data.scans) {
                        const allScans = data.scans.filter(scan => scan.location.toLowerCase() === locatorName.toLowerCase());

                        // Filter for edited or added records
                        const editedScans = allScans.filter(scan => parseInt(scan.edited) === 1 || parseInt(scan.added) === 1);

                        if (editedScans.length === 0) {
                            return; // Nothing to print
                        }

                        // Order scans by RecNo (chronological order)
                        editedScans.reverse(); // API returns DESC, we reverse it to ASC for printing

                        // Helper padding functions for alignment
                        const padRight = (str, len) => {
                            str = String(str || '');
                            return str + ' '.repeat(Math.max(0, len - str.length));
                        };
                        const padLeft = (str, len) => {
                            str = String(str || '');
                            return ' '.repeat(Math.max(0, len - str.length)) + str;
                        };
                        const padCenter = (str, len) => {
                            str = String(str || '').trim();
                            if (str.length >= len) return str.substring(0, len);
                            const padLeftVal = Math.floor((len - str.length) / 2);
                            return ' '.repeat(padLeftVal) + str + ' '.repeat(len - str.length - padLeftVal);
                        };
                        const centerText = (str, len = 84) => {
                            str = String(str || '').trim();
                            if (str.length >= len) return str;
                            const padLeftVal = Math.floor((len - str.length) / 2);
                            return ' '.repeat(padLeftVal) + str;
                        };

                        const now = new Date();
                        const countDateStr = now.toLocaleDateString('en-US', { day: '2-digit', month: '2-digit', year: 'numeric' });

                        let text = '';
                        text += centerText(`OFFICE WAREHOUSE INC - ${storeCode}`) + '\r\n';
                        text += centerText('Annual Inventory Count') + '\r\n\r\n';
                        text += centerText('*****    Edited Count Sheet   *****') + '\r\n\r\n';
                        text += `Locator No. : ${displayLoc}\r\n`;
                        text += `Count Date. : ${countDateStr}\r\n\r\n`;

                        // Header columns row
                        text += padRight('Rec No', 8) + padRight('UPC', 16) + padRight('SKU', 7) + padRight('Description', 32) + padLeft('Old', 6) + padLeft('Edited', 9) + '\r\n';
                        text += padRight('', 8) + padRight('', 16) + padRight('', 7) + padRight('', 32) + padLeft('Qty', 6) + padLeft('Qty', 9) + '\r\n\r\n';

                        let numEdited = 0;
                        let numAdded = 0;
                        let grandTotal = 0;
                        let infCount = 0;

                        // Calculate grand total and INF over ALL scans in this locator
                        allScans.forEach(scan => {
                            const qtyVal = parseFloat(scan.quantity || 0);
                            grandTotal += qtyVal;
                            if (scan.product_name === 'Item Not Found' || scan.product_name === 'Unknown Product') {
                                infCount++;
                            }
                        });

                        editedScans.forEach((scan) => {
                            const recNo = scan.id; // RecNo from database
                            const barcode = scan.barcode || '';
                            const sku = scan.sku || '';
                            const descr = scan.product_name || 'Item Not Found';

                            const oldQtyVal = parseFloat(scan.original_qty || 0);
                            const oldQtyStr = oldQtyVal.toFixed(0);

                            const editedQtyVal = parseFloat(scan.edited_qty || 0);
                            const editedQtyStr = editedQtyVal.toFixed(0);

                            if (parseInt(scan.edited) === 1) numEdited++;
                            if (parseInt(scan.added) === 1) numAdded++;

                            // Generate formatted row with precise spacing
                            text += padRight(recNo, 8) +
                                padRight(barcode, 16) +
                                padRight(sku, 7) +
                                padRight(descr, 32) +
                                padLeft(oldQtyStr, 6) +
                                padLeft(editedQtyStr, 9) + '\r\n';
                        });

                        // Get unique operators who scanned in this locator
                        const operators = [...new Set(allScans.map(scan => scan.scanned_by).filter(Boolean))];
                        const scannedByNames = operators.join(', ').toUpperCase();

                        text += '\r\n';
                        text += `Number of Records Edited : ${numEdited}\r\n`;
                        text += `Number of Records Added : ${numAdded}\r\n\r\n`;
                        text += padRight(`Number of Records Scanned: ${allScans.length}`, 52) + `GRAND TOTAL : ${grandTotal.toFixed(0)}\r\n`;
                        text += `No. of INF Found : ${infCount}\r\n\r\n\r\n`;

                        text += '       <span style="position:relative; top:12px; font-weight:600;">' + padCenter(scannedByNames, 12) + '</span>                          \r\n';
                        text += '       ____________            ____________               ____________\r\n';
                        text += '        Scanned  By             Counted By                 Checked By\r\n\r\n\r\n\r\n';
                        text += '              ____________                    ____________\r\n';
                        text += '               Team Leader                     Posted By\r\n';

                        // Open print frame window
                        const printWin = window.open('', '', 'width=800,height=600');
                        printWin.document.open();
                        printWin.document.write(`
                            <html>
                            <head>
                                <title>Print Edited Count Sheet - ${locatorName}</title>
                                <style>
                                    @page {
                                        margin: 0;
                                    }
                                    @media print {
                                        body { 
                                            margin: 0; 
                                            padding-top: ${printMarginTop}mm; 
                                            padding-left: ${printMarginLeft}mm; 
                                            background: white; 
                                            color: black; 
                                        }
                                    }
                                    body {
                                        font-family: monospace;
                                        white-space: pre;
                                        font-size: 13px;
                                        line-height: 1.1;
                                        background: white;
                                        color: black;
                                        padding-top: ${printMarginTop}mm;
                                        padding-left: ${printMarginLeft}mm;
                                        margin: 0;
                                    }
                                    pre {
                                        margin: 0;
                                        padding: 0;
                                        font-family: monospace;
                                        line-height: 1.1;
                                    }
                                </style>
                            </head>
                            <body>
                                <pre>${text}</pre>
                                <script>
                                    window.onload = function() {
                                        window.print();
                                        window.close();
                                    }
                                <\/script>
                            </body>
                            </html>
                        `);
                        printWin.document.close();
                    }
                })
                .catch(err => {
                    console.error("Failed to print edited count sheet: " + err);
                });
        }

        function loadLocatorScansTable(locatorName) {
            fetch('api.php?action=get_scans')
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success' && data.scans) {
                        const scans = data.scans.filter(scan => scan.location.toLowerCase() === locatorName.toLowerCase());
                        const tbody = document.getElementById('view-scans-tbody');

                        if (scans.length > 0) {
                            let html = '';
                            scans.forEach((scan, index) => {
                                html += `
                                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                                        <td style="padding: 10px 8px; color:var(--text-muted); text-align: center;">${index + 1}</td>
                                        <td style="padding: 10px 8px; font-family:monospace; color:#58a6ff; font-weight:600;">${scan.barcode}</td>
                                        <td style="padding: 10px 8px; color:var(--text-white);">${scan.product_name || '<span style="color:var(--text-muted);">Item Not in Catalog</span>'}</td>
                                        <td style="padding: 10px 8px; text-align: center; color:var(--text-white); font-weight:700;">${parseFloat(scan.quantity).toFixed(0)}</td>
                                        <td style="padding: 10px 8px; text-align: right;">
                                            <button class="btn btn-secondary btn-sm" onclick="openEditScanModal(${scan.id}, '${scan.barcode}', ${scan.quantity})" style="padding: 4px 8px; font-size:0.7rem; width:auto; height:auto; margin:0; border-radius:4px; cursor:pointer; font-weight:600;">Edit</button>
                                        </td>
                                    </tr>
                                `;
                            });
                            tbody.innerHTML = html;
                        } else {
                            tbody.innerHTML = `<tr><td colspan="5" style="text-align:center; padding:20px; color:var(--text-muted);">No items scanned in this locator yet.</td></tr>`;
                        }
                    }
                })
                .catch(err => {
                    console.error("Error loading locator scans:", err);
                });
        }

        // Sub-modal for Edit Scan
        function openEditScanModal(id, barcode, qty) {
            document.getElementById('edit-scan-id').value = id;
            document.getElementById('edit-scan-barcode').value = barcode;
            document.getElementById('edit-scan-qty').value = qty;

            document.getElementById('edit-scan-prod-name').innerText = "Checking Catalog...";
            document.getElementById('edit-scan-prod-desc').innerText = "Querying MySQL...";
            document.getElementById('host-edit-scan-modal-overlay').classList.add('active');

            updateEditScanProductInfo(barcode);
        }

        // Fetch description live when typing barcode inside Edit Scan modal
        function updateEditScanProductInfo(barcode) {
            if (barcode === '') {
                document.getElementById('edit-scan-prod-name').innerText = "Item Not Found";
                document.getElementById('edit-scan-prod-desc').innerText = "No barcode entered.";
                return;
            }
            fetch('api.php?action=get_products')
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success' && data.products) {
                        const matched = data.products.find(p => p.barcode === barcode || (p.sku && p.sku.toLowerCase() === barcode.toLowerCase()));
                        if (matched) {
                            document.getElementById('edit-scan-prod-name').innerText = matched.product_name;
                            document.getElementById('edit-scan-prod-desc').innerText = `SKU: ${matched.sku || 'N/A'}`;
                        } else {
                            document.getElementById('edit-scan-prod-name').innerText = "Item Not Found";
                            document.getElementById('edit-scan-prod-desc').innerText = "Item not found in catalog.";
                        }
                    }
                })
                .catch(err => {
                    document.getElementById('edit-scan-prod-name').innerText = "Catalog Check Failed";
                    document.getElementById('edit-scan-prod-desc').innerText = "MySQL connection timed out.";
                });
        }

        // Close Edit Scan Modal
        function closeEditScanModal() {
            document.getElementById('host-edit-scan-modal-overlay').classList.remove('active');
        }

        // Submit Edit Scan to Server API
        function submitEditScan() {
            const id = document.getElementById('edit-scan-id').value;
            const barcode = document.getElementById('edit-scan-barcode').value.trim();
            const qty = parseFloat(document.getElementById('edit-scan-qty').value);

            if (barcode === '') {
                alert("Barcode is required!");
                return;
            }
            if (isNaN(qty) || qty < 0) {
                alert("Quantity must be a valid non-negative number!");
                return;
            }

            fetch('api.php?action=edit_scan', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id, barcode, quantity: qty })
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        closeEditScanModal();

                        const activeLoc = localStorage.getItem('active_locator');
                        if (activeLoc) {
                            loadMobileScanLogFromServer(activeLoc);
                        }
                        if (window.currentEditingLocatorName) {
                            loadLocatorScansTable(window.currentEditingLocatorName);
                        }
                        if (typeof loadHostScans === 'function') {
                            loadHostScans();
                        }
                    } else {
                        alert(data.message);
                    }
                })
                .catch(err => {
                    alert("Failed to edit scan: " + err);
                });
        }

        // Save Spacing configurations dynamically
        function saveHostPrintConfig(event) {
            event.preventDefault();

            const topMargin = parseInt(document.getElementById('host_print_margin_top').value) || 0;
            const leftMargin = parseInt(document.getElementById('host_print_margin_left').value) || 0;

            const payload = {
                print_margin_top: topMargin,
                print_margin_left: leftMargin
            };

            fetch('api.php?action=save_print_spacing', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        // Update local variables in real-time
                        printMarginTop = topMargin;
                        printMarginLeft = leftMargin;
                        alert("Print spacing configuration saved successfully!");
                    } else {
                        alert(data.message);
                    }
                })
                .catch(err => {
                    alert("Failed to save spacing: " + err);
                });
        }

        // Cloud Sync Modal functions
        function openCloudSyncModal() {
            document.getElementById('cloud-sync-modal-overlay').classList.add('active');

            // Fetch existing sync settings
            fetch('api.php?action=get_sync_config')
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        document.getElementById('sync_cloud_url').value = data.cloud_sync_url || 'https://pginv.officewarehouse.com.ph/OWIPI/';
                        document.getElementById('sync_secret_token').value = data.sync_secret_token || '';
                    }
                })
                .catch(err => console.error("Failed to load sync settings:", err));
        }

        function closeCloudSyncModal() {
            document.getElementById('cloud-sync-modal-overlay').classList.remove('active');
            const statusMsg = document.getElementById('sync-status-msg');
            statusMsg.style.display = 'none';
            statusMsg.innerText = '';
        }

        function saveSyncConfig(event) {
            event.preventDefault();
            const cloudUrl = document.getElementById('sync_cloud_url').value.trim();
            const secretToken = document.getElementById('sync_secret_token').value.trim();

            const statusMsg = document.getElementById('sync-status-msg');
            statusMsg.style.display = 'block';
            statusMsg.style.background = 'rgba(255,255,255,0.05)';
            statusMsg.style.color = '#8b949e';
            statusMsg.innerText = 'Saving configuration...';

            fetch('api.php?action=save_sync_config', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ cloud_sync_url: cloudUrl, sync_secret_token: secretToken })
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        statusMsg.style.background = 'rgba(46,164,79,0.15)';
                        statusMsg.style.color = '#2ea44f';
                        statusMsg.innerText = data.message;
                    } else {
                        statusMsg.style.background = 'rgba(248,81,73,0.15)';
                        statusMsg.style.color = '#f85149';
                        statusMsg.innerText = data.message;
                    }
                })
                .catch(err => {
                    statusMsg.style.background = 'rgba(248,81,73,0.15)';
                    statusMsg.style.color = '#f85149';
                    statusMsg.innerText = 'Failed to save config: ' + err;
                });
        }

        function runCloudSync() {
            const cloudUrl = document.getElementById('sync_cloud_url').value.trim();
            const secretToken = document.getElementById('sync_secret_token').value.trim();

            const statusMsg = document.getElementById('sync-status-msg');
            statusMsg.style.display = 'block';
            statusMsg.style.background = 'rgba(255,255,255,0.05)';
            statusMsg.style.color = '#8b949e';

            if (!cloudUrl) {
                statusMsg.style.background = 'rgba(248,81,73,0.15)';
                statusMsg.style.color = '#f85149';
                statusMsg.innerText = 'Please enter a valid Cloud Server API URL.';
                return;
            }

            const btn = document.getElementById('btn-run-sync');
            const originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span>Syncing...</span>';
            statusMsg.innerText = 'Saving configuration and starting synchronization...';

            // Automatically save settings first
            fetch('api.php?action=save_sync_config', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ cloud_sync_url: cloudUrl, sync_secret_token: secretToken })
            })
            .then(res => res.json())
            .then(saveData => {
                if (saveData.status !== 'success') {
                    throw new Error(saveData.message || 'Failed to save configuration.');
                }
                // Trigger sync execution
                return fetch('api.php?action=trigger_cloud_sync').then(res => res.json());
            })
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = originalHtml;

                if (data.status === 'success') {
                    statusMsg.style.background = 'rgba(46,164,79,0.15)';
                    statusMsg.style.color = '#2ea44f';
                    statusMsg.innerText = data.message;

                    // Reload dashboard/locators to reflect status
                    loadHostLocators();
                } else {
                    statusMsg.style.background = 'rgba(248,81,73,0.15)';
                    statusMsg.style.color = '#f85149';
                    statusMsg.innerText = data.message;
                }
            })
            .catch(err => {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
                statusMsg.style.background = 'rgba(248,81,73,0.15)';
                statusMsg.style.color = '#f85149';
                statusMsg.innerText = 'Sync failed: ' + err.message;
            });
        }

        function toggleHostMobileView() {
            const urlParams = new URLSearchParams(window.location.search);
            const currentView = urlParams.get('view');
            if (currentView === 'mobile') {
                window.location.href = 'scan.php?view=host';
            } else if (currentView === 'host') {
                window.location.href = 'scan.php?view=mobile';
            } else {
                const hostDash = document.getElementById('host-dashboard');
                if (hostDash && hostDash.style.display !== 'none') {
                    window.location.href = 'scan.php?view=mobile';
                } else {
                    window.location.href = 'scan.php?view=host';
                }
            }
        }

        function openHostMasterfileModal() {
            document.getElementById('host-masterfile-modal').style.display = 'flex';
        }

        function closeHostMasterfileModal() {
            document.getElementById('host-masterfile-modal').style.display = 'none';
        }

        function uploadHostMasterfile(event) {
            event.preventDefault();
            const fileInput = document.getElementById('host_masterfile_input');
            if (fileInput.files.length === 0) {
                alert("Please select a file to import.");
                return;
            }

            const file = fileInput.files[0];
            const formData = new FormData();
            formData.append('file', file);
            formData.append('store_code', '<?= htmlspecialchars($_SESSION['store_code'] ?? '') ?>');

            const btn = document.getElementById('host-upload-btn');
            const originalText = btn.innerText;
            btn.disabled = true;
            btn.innerText = "Importing...";

            fetch('api.php?action=import_masterfile', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    btn.disabled = false;
                    btn.innerText = originalText;
                    if (data.status === 'success') {
                        alert(data.message);
                        closeHostMasterfileModal();
                        document.getElementById('host-masterfile-form').reset();
                        if (typeof loadHostLocators === 'function') loadHostLocators();
                    } else {
                        alert("Error: " + data.message);
                    }
                })
                .catch(err => {
                    btn.disabled = false;
                    btn.innerText = originalText;
                    alert("Import failed: " + err);
                });
        }

        function renderHostQRCode(url) {
            const qrContainer = document.getElementById("qrcode");
            if (!qrContainer) return;
            qrContainer.innerHTML = '';

            let success = false;
            if (typeof QRCode !== 'undefined') {
                try {
                    qrCodeInstance = new QRCode(qrContainer, {
                        text: url,
                        width: 150,
                        height: 150,
                        colorDark: "#000000",
                        colorLight: "#ffffff",
                        correctLevel: QRCode.CorrectLevel.L
                    });
                    success = true;
                } catch (e) {
                    console.error("Local QRCode error:", e);
                }
            }

            // Fallback check: If container is empty or canvas/image rendered 0 height/data, fallback to image API
            setTimeout(() => {
                const img = qrContainer.querySelector('img');
                const canvas = qrContainer.querySelector('canvas');
                const isValidImg = (img && img.src && img.src.length > 100 && img.offsetHeight > 10) || (canvas && canvas.offsetHeight > 10);
                
                if (!isValidImg) {
                    qrContainer.innerHTML = '';
                    const fallbackImg = document.createElement('img');
                    fallbackImg.src = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' + encodeURIComponent(url);
                    fallbackImg.width = 150;
                    fallbackImg.height = 150;
                    fallbackImg.style.borderRadius = '4px';
                    fallbackImg.style.display = 'block';
                    qrContainer.appendChild(fallbackImg);
                }
            }, 100);
        }

        function updateQRCodeIP(newIp) {
            const currentUrl = "<?= $scanUrl ?>";
            const oldIp = "<?= $localIP ?>";
            const newUrl = currentUrl.replace(oldIp, newIp);
            renderHostQRCode(newUrl);
        }
    </script>

    <!-- Host Upload Store Masterfile Modal -->
    <div id="host-masterfile-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 99999; justify-content: center; align-items: center; padding: 20px; box-sizing: border-box;">
        <div class="card" style="width: 100%; max-width: 480px; padding: 1.5rem; margin: 0; background: #161b22; border: 1px solid rgba(255,255,255,0.15); border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.5);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 0.75rem;">
                <h3 style="margin: 0; font-size: 1.1rem; color: white; display: flex; align-items: center; gap: 8px;">
                    📁 Upload Store Masterfile
                </h3>
                <button onclick="closeHostMasterfileModal()" style="background: none; border: none; color: #8b949e; font-size: 1.4rem; cursor: pointer;">&times;</button>
            </div>
            <p style="font-size: 0.85rem; color: #8b949e; margin-bottom: 1.25rem; line-height: 1.5;">
                Import item masterfile for active store <strong><?= htmlspecialchars($_SESSION['store_code'] ?? '') ?></strong> (table: <code><?= strtolower(htmlspecialchars($_SESSION['store_code'] ?? '')) ?>_items</code>). Supports <strong>.txt</strong> / <strong>.csv</strong> format.
            </p>
            <form id="host-masterfile-form" onsubmit="uploadHostMasterfile(event)">
                <div class="form-group" style="margin-bottom: 1.25rem;">
                    <label for="host_masterfile_input" style="font-size: 0.85rem; color: #8b949e; display: block; margin-bottom: 0.5rem; font-weight: 500;">Select Masterfile (.txt / .csv / .tsv)</label>
                    <input type="file" id="host_masterfile_input" class="form-control" accept=".txt,.csv,.tsv" required style="height: 42px; padding: 0.4rem 0.75rem; font-size: 0.85rem; background: rgba(0,0,0,0.3); color: white; border: 1px solid rgba(255,255,255,0.15); border-radius: 6px; width: 100%; box-sizing: border-box;">
                </div>
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" onclick="closeHostMasterfileModal()" class="btn btn-secondary" style="width: auto; padding: 6px 14px; font-size: 0.85rem; border-radius: 6px;">Cancel</button>
                    <button type="submit" id="host-upload-btn" class="btn btn-primary" style="width: auto; padding: 6px 18px; font-size: 0.85rem; background: #2563eb; font-weight: 600; border-radius: 6px;">Upload & Import</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Mobile Connect Setup Modal -->
    <div id="mobile-connect-modal"
        style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: #0b0f19; z-index: 99999; justify-content: center; align-items: center; padding: 20px; box-sizing: border-box;">
        <div class="card"
            style="width: 100%; max-width: 400px; padding: 2rem; margin: 0; box-shadow: 0 10px 30px rgba(0,0,0,0.5); border: 1px solid rgba(255,255,255,0.08);">
            <div class="card-title"
                style="font-size: 1.3rem; font-weight: 700; margin-bottom: 1.5rem; text-align: center; color: var(--text-white); border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 0.75rem;">
                Scanner Setup
            </div>
            <form id="mobile-connect-form" onsubmit="handleMobileConnect(event)">
                <div class="form-group" style="margin-bottom: 1.25rem;">
                    <label for="modal-scanned_by"
                        style="display: block; font-size: 0.85rem; color: var(--text-muted); font-weight: 600; margin-bottom: 0.5rem; text-transform: uppercase;">SCANNED
                        BY</label>
                    <input type="text" id="modal-scanned_by" class="form-control" placeholder="Enter name..." required
                        style="height: 48px; padding: 0 1.1rem; border-radius: 8px; border: 1px solid rgba(255,255,255,0.08); background: rgba(11, 15, 25, 0.5); color: var(--text-white); width: 100%; box-sizing: border-box; text-transform: uppercase;"
                        oninput="this.value = this.value.toUpperCase()">
                </div>
                <div class="form-group" style="margin-bottom: 1.5rem;">
                    <label for="modal-location"
                        style="display: block; font-size: 0.85rem; color: var(--text-muted); font-weight: 600; margin-bottom: 0.5rem; text-transform: uppercase;">Select/Type
                        Locator</label>
                    <input type="text" id="modal-location" list="modal-locator-list" class="form-control"
                        placeholder="Type/Select Locator (e.g. 1)..." required autocomplete="off"
                        style="height: 48px; padding: 0 1.1rem; border-radius: 8px; border: 1px solid rgba(255,255,255,0.08); background: rgba(11, 15, 25, 0.5); color: var(--text-white); width: 100%; box-sizing: border-box;">
                    <datalist id="modal-locator-list"></datalist>
                </div>
                <button type="submit" class="btn btn-primary"
                    style="width: 100%; height: 48px; font-size: 1rem; font-weight: 600; cursor: pointer; border-radius: 8px; box-shadow: 0 4px 15px rgba(88,166,255,0.25);">
                    Connect & Start Scanning
                </button>
            </form>
        </div>
    </div>
</body>

</html>