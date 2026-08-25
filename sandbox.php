<?php
require_once __DIR__ . '/config.php';
checkAuth();

$userRole = strtolower(trim($_SESSION['role'] ?? ''));
$userName = strtolower(trim($_SESSION['username'] ?? ''));
$isSysAdmin = in_array($userRole, ['system_admin', 'sys_admin']);
$isAllanUser = ($userName === 'allan');

if (!$isSysAdmin && !$isAllanUser) {
    header('Location: scan.php');
    exit;
}

$targetPage = trim($_GET['page'] ?? 'scan.php');
$allowedPages = [
    'scan.php' => 'Scanner App (scan.php)',
    'index.php' => 'Admin Dashboard (index.php)',
    'mobile_ce.php' => 'Pocket PC CE (mobile_ce.php)',
    'login.php' => 'Login Screen (login.php)'
];

if (!array_key_exists($targetPage, $allowedPages)) {
    $targetPage = 'scan.php';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OWIPI - Screen Resolution Testing Sandbox</title>
    <!-- Custom Application Favicon -->
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="alternate icon" type="image/png" href="assets/favicon.png">
    <link rel="shortcut icon" href="favicon.ico">
    <link rel="stylesheet" href="assets/fonts/fonts.css">
    <style>
        :root {
            --bg-color: #090d12;
            --bar-bg: rgba(22, 27, 34, 0.95);
            --border-color: rgba(240, 246, 252, 0.12);
            --accent-color: #58a6ff;
            --purple-color: #a855f7;
            --success-color: #2ea44f;
            --text-white: #ffffff;
            --text-muted: #8b949e;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }

        body {
            background-color: var(--bg-color);
            color: #c9d1d9;
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /* Top Toolbar */
        .sandbox-topbar {
            background: var(--bar-bg);
            border-bottom: 1px solid var(--border-color);
            padding: 10px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            z-index: 100;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.4);
        }

        .brand-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1rem;
            font-weight: 800;
            color: var(--text-white);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .badge-live {
            background: rgba(168, 85, 247, 0.2);
            color: #c084fc;
            border: 1px solid rgba(168, 85, 247, 0.4);
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        .presets-group {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .preset-btn {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: #c9d1d9;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .preset-btn:hover {
            background: rgba(88, 166, 255, 0.15);
            border-color: var(--accent-color);
            color: var(--text-white);
        }

        .preset-btn.active {
            background: var(--accent-color);
            border-color: var(--accent-color);
            color: #000000;
            font-weight: 700;
        }

        .custom-controls {
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(0, 0, 0, 0.3);
            padding: 4px 10px;
            border-radius: 6px;
            border: 1px solid var(--border-color);
        }

        .custom-controls label {
            font-size: 0.7rem;
            color: var(--text-muted);
            font-weight: 600;
        }

        .custom-controls input[type="number"] {
            width: 60px;
            height: 26px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 4px;
            color: white;
            padding: 0 6px;
            font-size: 0.75rem;
            text-align: center;
        }

        .page-select {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(168, 85, 247, 0.4);
            color: #c084fc;
            font-size: 0.75rem;
            font-weight: 600;
            padding: 6px 10px;
            border-radius: 6px;
            cursor: pointer;
            outline: none;
        }

        .page-select option {
            background: #161b22;
            color: #c9d1d9;
        }

        .scale-control {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.75rem;
        }

        .scale-control input[type="range"] {
            width: 90px;
            cursor: pointer;
        }

        .dim-badge {
            font-family: monospace;
            font-size: 0.8rem;
            color: #58a6ff;
            background: rgba(88, 166, 255, 0.1);
            border: 1px solid rgba(88, 166, 255, 0.25);
            padding: 4px 10px;
            border-radius: 6px;
            font-weight: 700;
        }

        /* Main Workspace Arena */
        .sandbox-stage {
            flex-grow: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            overflow: auto;
            position: relative;
            background-image: 
                radial-gradient(rgba(255, 255, 255, 0.05) 1px, transparent 1px);
            background-size: 20px 20px;
        }

        .frame-wrapper {
            position: relative;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.7);
            border-radius: 12px;
            transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1), height 0.3s cubic-bezier(0.4, 0, 0.2, 1), transform 0.2s ease;
            background: #0d1117;
            border: 1px solid rgba(255, 255, 255, 0.15);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            transform-origin: top center;
        }

        .frame-header {
            background: #161b22;
            padding: 8px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            font-size: 0.75rem;
            color: var(--text-muted);
            gap: 12px;
        }

        .frame-dots {
            display: flex;
            gap: 6px;
        }

        .dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }

        .dot-red { background: #ff5f56; }
        .dot-yellow { background: #ffbd2e; }
        .dot-green { background: #27c93f; }

        iframe {
            width: 100%;
            height: 100%;
            border: none;
            background: #0d1117;
            flex-grow: 1;
        }
    </style>
</head>
<body>

    <div class="sandbox-topbar">
        <div class="brand-title">
            <span>🧪 Resolution Sandbox</span>
            <span class="badge-live">Live Simulator</span>
        </div>

        <!-- Target Page Switcher -->
        <div style="display: flex; align-items: center; gap: 6px;">
            <label for="page-selector" style="font-size: 0.75rem; color: var(--text-muted); font-weight: 600;">Target View:</label>
            <select id="page-selector" class="page-select" onchange="changeSimulatedPage(this.value)">
                <option value="scan.php" <?= $targetPage === 'scan.php' ? 'selected' : '' ?>>📱 Scanner App (scan.php)</option>
                <option value="index.php" <?= $targetPage === 'index.php' ? 'selected' : '' ?>>🎛️ Admin Dashboard (index.php)</option>
                <option value="mobile_ce.php" <?= $targetPage === 'mobile_ce.php' ? 'selected' : '' ?>>📟 Pocket PC CE (mobile_ce.php)</option>
                <option value="login.php" <?= $targetPage === 'login.php' ? 'selected' : '' ?>>🔐 Login Page (login.php)</option>
            </select>
        </div>

        <!-- One-Click Device Presets -->
        <div class="presets-group">
            <button class="preset-btn <?= ($targetPage === 'mobile_ce.php') ? 'active' : '' ?>" onclick="setPreset(240, 320, 'Pocket PC CE (240x320)', this)">
                📟 CE 240x320
            </button>
            <button class="preset-btn" onclick="setPreset(375, 812, 'Mobile Portrait (375x812)', this)">
                📱 Mobile (375x812)
            </button>
            <button class="preset-btn" onclick="setPreset(812, 375, 'Mobile Landscape (812x375)', this)">
                🔄 Mobile Land. (812x375)
            </button>
            <button class="preset-btn" onclick="setPreset(768, 1024, 'Tablet / iPad (768x1024)', this)">
                📱 Tablet (768x1024)
            </button>
            <button class="preset-btn <?= ($targetPage !== 'mobile_ce.php') ? 'active' : '' ?>" onclick="setPreset(1366, 768, 'Laptop HD (1366x768)', this)">
                💻 Laptop 768p
            </button>
            <button class="preset-btn" onclick="setPreset(1920, 1080, 'Desktop Full HD (1920x1080)', this)">
                🖥️ Desktop 1080p
            </button>
            <button class="preset-btn" onclick="setPreset(2560, 1440, '2K / 4K Monitor (2560x1440)', this)">
                🖥️ 2K / 4K Display
            </button>
        </div>

        <!-- Custom Dimensions & Zoom Controls -->
        <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
            <div class="custom-controls">
                <label>W:</label>
                <input type="number" id="input-w" value="<?= ($targetPage === 'mobile_ce.php') ? '240' : '1366' ?>" min="240" max="3840" onchange="applyCustomSize()">
                <label>H:</label>
                <input type="number" id="input-h" value="<?= ($targetPage === 'mobile_ce.php') ? '320' : '768' ?>" min="240" max="2160" onchange="applyCustomSize()">
            </div>

            <div class="scale-control">
                <label for="zoom-slider">Zoom:</label>
                <input type="range" id="zoom-slider" min="0.4" max="1" step="0.05" value="1" oninput="applyZoom(this.value)">
                <span id="zoom-label" style="font-weight: 700; color: white;">100%</span>
            </div>

            <button class="preset-btn" onclick="rotateOrientation()" style="padding: 6px 10px;" title="Rotate Orientation">
                🔄 Rotate
            </button>

            <span id="dim-display" class="dim-badge"><?= ($targetPage === 'mobile_ce.php') ? '240 × 320 px' : '1366 × 768 px' ?></span>

            <a id="exit-btn" href="<?= htmlspecialchars($targetPage) ?>" class="preset-btn" style="background: rgba(46,164,79,0.2); border-color: #2ea44f; color: #3fb950; font-weight: 700;">
                ⬅️ Exit Sandbox
            </a>
        </div>
    </div>

    <!-- Live Stage Viewport -->
    <div class="sandbox-stage">
        <div id="frame-wrapper" class="frame-wrapper" style="width: <?= ($targetPage === 'mobile_ce.php') ? '240px' : '1366px' ?>; height: <?= ($targetPage === 'mobile_ce.php') ? '320px' : '768px' ?>;">
            <div class="frame-header">
                <div class="frame-dots">
                    <span class="dot dot-red"></span>
                    <span class="dot dot-yellow"></span>
                    <span class="dot dot-green"></span>
                </div>
                <div id="frame-title" style="font-weight: 600;"><?= ($targetPage === 'mobile_ce.php') ? 'Pocket PC CE (240x320)' : 'Laptop HD (1366x768)' ?></div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span id="frame-url" style="font-family: monospace; color: #58a6ff; font-size: 0.7rem;"><?= htmlspecialchars($targetPage) ?></span>
                    <button onclick="reloadPreviewFrame()" title="Reload View" style="background: none; border: none; color: #8b949e; cursor: pointer; font-size: 0.8rem;">🔄</button>
                </div>
            </div>
            <iframe id="preview-iframe" src="<?= htmlspecialchars($targetPage) ?>"></iframe>
        </div>
    </div>

    <script>
        let currentW = <?= ($targetPage === 'mobile_ce.php') ? '240' : '1366' ?>;
        let currentH = <?= ($targetPage === 'mobile_ce.php') ? '320' : '768' ?>;
        let currentScale = 1;

        function setPreset(w, h, label, btnElement) {
            currentW = w;
            currentH = h;

            document.getElementById('input-w').value = w;
            document.getElementById('input-h').value = h;

            // Highlight active preset button
            document.querySelectorAll('.preset-btn').forEach(b => b.classList.remove('active'));
            if (btnElement) {
                btnElement.classList.add('active');
            }

            document.getElementById('frame-title').innerText = label;
            updateFrameDimensions();
        }

        function applyCustomSize() {
            const w = parseInt(document.getElementById('input-w').value) || 1366;
            const h = parseInt(document.getElementById('input-h').value) || 768;

            currentW = w;
            currentH = h;
            document.querySelectorAll('.preset-btn').forEach(b => b.classList.remove('active'));
            document.getElementById('frame-title').innerText = `Custom (${w}x${h})`;
            updateFrameDimensions();
        }

        function applyZoom(scaleVal) {
            currentScale = parseFloat(scaleVal);
            document.getElementById('zoom-label').innerText = Math.round(currentScale * 100) + '%';
            updateFrameDimensions();
        }

        function rotateOrientation() {
            const temp = currentW;
            currentW = currentH;
            currentH = temp;

            document.getElementById('input-w').value = currentW;
            document.getElementById('input-h').value = currentH;
            document.getElementById('frame-title').innerText = `Rotated (${currentW}x${currentH})`;
            updateFrameDimensions();
        }

        function updateFrameDimensions() {
            const wrapper = document.getElementById('frame-wrapper');
            wrapper.style.width = currentW + 'px';
            wrapper.style.height = currentH + 'px';
            wrapper.style.transform = `scale(${currentScale})`;

            document.getElementById('dim-display').innerText = `${currentW} × ${currentH} px`;
        }

        function changeSimulatedPage(newPage) {
            const iframe = document.getElementById('preview-iframe');
            const urlDisplay = document.getElementById('frame-url');
            iframe.src = newPage;
            if (urlDisplay) {
                urlDisplay.innerText = newPage;
            }
            const exitBtn = document.getElementById('exit-btn');
            if (exitBtn) {
                exitBtn.href = newPage;
            }

            // Adjust default resolution if CE is chosen
            if (newPage === 'mobile_ce.php') {
                setPreset(240, 320, 'Pocket PC CE (240x320)');
            }
        }

        function reloadPreviewFrame() {
            const iframe = document.getElementById('preview-iframe');
            iframe.src = iframe.src;
        }

        // Auto-fit initial scale if screen is smaller than default 1366px
        window.addEventListener('load', () => {
            const stage = document.querySelector('.sandbox-stage');
            const availableW = stage.clientWidth - 40;
            if (availableW < currentW) {
                const autoScale = Math.max(0.4, (availableW / currentW).toFixed(2));
                document.getElementById('zoom-slider').value = autoScale;
                applyZoom(autoScale);
            }
        });
    </script>
</body>
</html>
