<?php
require_once __DIR__ . '/config.php';
checkAuth();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OWIPI - Screen Resolution Testing Sandbox</title>
    <link rel="stylesheet" href="assets/fonts/fonts.css">
    <style>
        :root {
            --bg-color: #090d12;
            --bar-bg: rgba(22, 27, 34, 0.95);
            --border-color: rgba(240, 246, 252, 0.12);
            --accent-color: #58a6ff;
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
            background: rgba(46, 164, 79, 0.2);
            color: #3fb950;
            border: 1px solid rgba(46, 164, 79, 0.4);
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

        <!-- One-Click Device Presets -->
        <div class="presets-group">
            <button class="preset-btn" onclick="setPreset(375, 812, 'Mobile Portrait', this)">
                📱 Mobile (375x812)
            </button>
            <button class="preset-btn" onclick="setPreset(812, 375, 'Mobile Landscape', this)">
                🔄 Mobile Land. (812x375)
            </button>
            <button class="preset-btn" onclick="setPreset(768, 1024, 'Tablet / iPad', this)">
                📱 Tablet (768x1024)
            </button>
            <button class="preset-btn active" onclick="setPreset(1366, 768, 'Laptop HD (1366x768)', this)">
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
                <input type="number" id="input-w" value="1366" min="320" max="3840" onchange="applyCustomSize()">
                <label>H:</label>
                <input type="number" id="input-h" value="768" min="320" max="2160" onchange="applyCustomSize()">
            </div>

            <div class="scale-control">
                <label for="zoom-slider">Zoom:</label>
                <input type="range" id="zoom-slider" min="0.4" max="1" step="0.05" value="1" oninput="applyZoom(this.value)">
                <span id="zoom-label" style="font-weight: 700; color: white;">100%</span>
            </div>

            <button class="preset-btn" onclick="rotateOrientation()" style="padding: 6px 10px;">
                🔄 Rotate
            </button>

            <span id="dim-display" class="dim-badge">1366 × 768 px</span>

            <a href="scan.php" class="preset-btn" style="background: rgba(46,164,79,0.2); border-color: #2ea44f; color: #3fb950;">
                ⬅️ Exit Sandbox
            </a>
        </div>
    </div>

    <!-- Live Stage Viewport -->
    <div class="sandbox-stage">
        <div id="frame-wrapper" class="frame-wrapper" style="width: 1366px; height: 768px;">
            <div class="frame-header">
                <div class="frame-dots">
                    <span class="dot dot-red"></span>
                    <span class="dot dot-yellow"></span>
                    <span class="dot dot-green"></span>
                </div>
                <div id="frame-title" style="font-weight: 600;">Laptop HD (1366x768)</div>
                <div style="font-family: monospace;">http://localhost/OWIPI/scan.php</div>
            </div>
            <iframe id="preview-iframe" src="scan.php"></iframe>
        </div>
    </div>

    <script>
        let currentW = 1366;
        let currentH = 768;
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

        // Auto-fit initial scale if screen is smaller than default 1366px
        window.addEventListener('load', () => {
            const stage = document.querySelector('.sandbox-stage');
            const availableW = stage.clientWidth - 40;
            if (availableW < 1366) {
                const autoScale = Math.max(0.4, (availableW / 1366).toFixed(2));
                document.getElementById('zoom-slider').value = autoScale;
                applyZoom(autoScale);
            }
        });
    </script>
</body>
</html>
