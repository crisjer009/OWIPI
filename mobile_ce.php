<?php
session_start();
require_once __DIR__ . '/config.php';

// Check GET variables for scanner configuration
$store = isset($_GET['store_code']) ? strtoupper(trim($_GET['store_code'])) : '';
$operator = isset($_GET['operator']) ? strtoupper(trim($_GET['operator'])) : '';
$locator = isset($_GET['locator']) ? strtoupper(trim($_GET['locator'])) : '';
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'setup';

// If parameters are provided but mode is not scan, default to scan
if (!empty($store) && !empty($operator) && !empty($locator) && $mode === 'setup') {
    $mode = 'scan';
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>OWI Handheld Scanner</title>
    <style type="text/css">
        body {
            background-color: #0f141c;
            color: #c9d1d9;
            margin: 0;
            padding: 8px;
            font-size: 13px;
        }
        .container {
            width: 100%;
            max-width: 280px;
            margin: 0 auto;
        }
        .header {
            background-color: #161b22;
            padding: 6px;
            border-bottom: 1px solid #30363d;
            text-align: center;
            font-weight: bold;
            color: #58a6ff;
            margin-bottom: 8px;
        }
        .card {
            background-color: #161b22;
            border: 1px solid #30363d;
            padding: 10px;
            margin-bottom: 8px;
        }
        .form-group {
            margin-bottom: 8px;
        }
        .form-group label {
            display: block;
            font-weight: bold;
            font-size: 11px;
            margin-bottom: 3px;
            color: #8b949e;
        }
        .form-control {
            width: 100%;
            padding: 5px;
            background-color: #0d1117;
            border: 1px solid #30363d;
            color: #ffffff;
            font-size: 13px;
            box-sizing: border-box;
        }
        .btn {
            width: 100%;
            padding: 8px;
            background-color: #1f6feb;
            border: 1px solid #388bfd;
            color: #ffffff;
            font-weight: bold;
            cursor: pointer;
            font-size: 13px;
            box-sizing: border-box;
            text-align: center;
        }
        .btn-danger {
            background-color: #da3637;
            border-color: #f85149;
        }
        .status-box {
            padding: 10px;
            text-align: center;
            font-weight: bold;
            font-size: 14px;
            margin-bottom: 8px;
            color: #ffffff;
            background-color: #21262d;
            border: 1px solid #30363d;
        }
        .status-success {
            background-color: #1b4332;
            border-color: #2ea44f;
            color: #56f089;
        }
        .status-error {
            background-color: #4c1d1d;
            border-color: #f85149;
            color: #ff7b72;
        }
        .status-loading {
            background-color: #1c2d42;
            border-color: #388bfd;
            color: #79c0ff;
        }
        .info-label {
            font-size: 11px;
            color: #8b949e;
            margin-bottom: 4px;
            text-align: center;
        }
        table.log-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            margin-top: 5px;
        }
        table.log-table th, table.log-table td {
            border: 1px solid #30363d;
            padding: 4px;
            text-align: left;
        }
        table.log-table th {
            background-color: #21262d;
            color: #8b949e;
        }
    </style>
    <script type="text/javascript">
        // Embed configurations directly as PHP/JS variables to bypass localStorage
        var config_store = "<?php echo htmlspecialchars($store); ?>";
        var config_op = "<?php echo htmlspecialchars($operator); ?>";
        var config_loc = "<?php echo htmlspecialchars($locator); ?>";
        var config_mode = "<?php echo htmlspecialchars($mode); ?>";

        function keepFocus() {
            var barcodeEl = document.getElementById("barcode");
            if (barcodeEl) {
                barcodeEl.focus();
            }
        }

        window.onload = function() {
            if (config_mode === "scan") {
                // Focus scan target loop
                setInterval(keepFocus, 1000);
                loadRecentScans();
                keepFocus();
            }
        };

        function barcodeKeyDown(event) {
            // Enter key is keycode 13
            if (event.keyCode === 13) {
                submitBarcode();
                return false;
            }
        }

        // Custom string-based JSON parser to work on Windows CE
        function getJSONParam(json, key) {
            var keyStr = '"' + key + '":';
            var idx = json.indexOf(keyStr);
            if (idx === -1) return "";
            var start = json.indexOf('"', idx + keyStr.length);
            if (start === -1) return "";
            start += 1;
            var end = json.indexOf('"', start);
            if (end === -1) return "";
            return json.substring(start, end);
        }

        function submitBarcode() {
            var barcodeEl = document.getElementById("barcode");
            var barcode = barcodeEl.value.trim();
            if (barcode === "") return;

            updateStatusBox("Sending...", "status-loading");

            // URL-encoded post parameters (compatible with every browser)
            var xhr = new XMLHttpRequest();
            xhr.open("POST", "api.php?action=submit_scan", true);
            xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");

            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    if (xhr.status === 200) {
                        var responseText = xhr.responseText;
                        var status = getJSONParam(responseText, "status");
                        var message = getJSONParam(responseText, "message");
                        var descr = getJSONParam(responseText, "product_name");

                        if (status === "success") {
                            var successMsg = (descr !== "" ? descr : "Count saved successfully.") + " (" + message + ")";
                            updateStatusBox(successMsg, "status-success");
                            barcodeEl.value = "";
                            loadRecentScans();
                        } else {
                            var errMsg = message !== "" ? message : "Barcode rejected.";
                            updateStatusBox("Error: " + errMsg, "status-error");
                        }
                    } else {
                        updateStatusBox("Network error (HTTP " + xhr.status + ")", "status-error");
                    }
                    keepFocus();
                }
            };

            var postData = "barcode=" + encodeURIComponent(barcode) +
                           "&quantity=1" +
                           "&location=" + encodeURIComponent(config_loc) +
                           "&scanned_by=" + encodeURIComponent(config_op) +
                           "&store_code=" + encodeURIComponent(config_store);

            xhr.send(postData);
        }

        function updateStatusBox(text, className) {
            var box = document.getElementById("status-box");
            if (box) {
                box.innerText = text;
                box.className = "status-box " + className;
            }
        }

        function loadRecentScans() {
            // Call custom endpoint returning clean pre-rendered HTML rows
            var xhr = new XMLHttpRequest();
            xhr.open("GET", "api.php?action=get_scans_html&store_code=" + encodeURIComponent(config_store) + "&location=" + encodeURIComponent(config_loc), true);
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4 && xhr.status === 200) {
                    var tbody = document.getElementById("log-tbody");
                    if (tbody) {
                        tbody.innerHTML = xhr.responseText;
                    }
                }
            };
            xhr.send();
        }

        function goBackToSetup() {
            // Load setup using standard GET link redirection
            window.location.href = "mobile_ce.php?mode=setup" + 
                                  "&store_code=" + encodeURIComponent(config_store) + 
                                  "&operator=" + encodeURIComponent(config_op) + 
                                  "&locator=" + encodeURIComponent(config_loc);
        }
    </script>
</head>
<body>
    <div class="container">
        <!-- SETUP SCREEN -->
        <?php if ($mode !== 'scan'): ?>
        <div id="setup-screen">
            <div class="header">OWI Scanner Setup</div>
            <div class="card">
                <form method="GET" action="mobile_ce.php">
                    <input type="hidden" name="mode" value="scan">
                    
                    <div class="form-group">
                        <label>Store Code:</label>
                        <input type="text" name="store_code" class="form-control" 
                               value="<?php echo htmlspecialchars($store); ?>" placeholder="e.g. TEST" required>
                    </div>
                    <div class="form-group">
                        <label>Operator Name:</label>
                        <input type="text" name="operator" class="form-control" 
                               value="<?php echo htmlspecialchars($operator); ?>" placeholder="e.g. JOHN" required>
                    </div>
                    <div class="form-group">
                        <label>Locator / Slot:</label>
                        <input type="text" name="locator" class="form-control" 
                               value="<?php echo htmlspecialchars($locator); ?>" placeholder="e.g. SLOT 1" required>
                    </div>
                    
                    <div style="margin-top: 15px;">
                        <button type="submit" class="btn">Start Scanning</button>
                    </div>
                </form>
            </div>
            <div style="text-align: center; color: #8b949e; font-size: 11px;">
                Optimized for CASIO Windows CE Terminals
            </div>
        </div>
        <?php endif; ?>

        <!-- SCANNING SCREEN -->
        <?php if ($mode === 'scan'): ?>
        <div id="scan-screen">
            <div class="header">OWI Scanner App</div>
            
            <div class="info-label">
                Store: <span style="color: #ffffff; font-weight: bold;"><?php echo htmlspecialchars($store); ?></span> | 
                Loc: <span style="color: #ffffff; font-weight: bold;"><?php echo htmlspecialchars($locator); ?></span>
                <br>
                Op: <span style="color: #ffffff;"><?php echo htmlspecialchars($operator); ?></span>
            </div>

            <div id="status-box" class="status-box">READY TO SCAN</div>

            <div class="card">
                <div class="form-group" style="margin-bottom: 12px;">
                    <label>Barcode Scan Target:</label>
                    <input type="text" id="barcode" class="form-control" 
                           style="height: 32px; font-size: 15px; font-weight: bold; text-align: center;" 
                           onkeydown="return barcodeKeyDown(event);"
                           autocomplete="off">
                </div>
                
                <table style="width: 100%;">
                    <tr>
                        <td style="width: 50%; padding-right: 3px;">
                            <button type="button" onclick="submitBarcode();" class="btn">Send</button>
                        </td>
                        <td style="width: 50%; padding-left: 3px;">
                            <button type="button" onclick="goBackToSetup();" class="btn btn-danger">Setup</button>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Recent Scans Log -->
            <div class="card" style="padding: 6px;">
                <div style="font-weight: bold; font-size: 11px; color: #8b949e; margin-bottom: 4px;">Recent Scans:</div>
                <table class="log-table">
                    <thead>
                        <tr>
                            <th>Barcode</th>
                            <th>Description</th>
                            <th style="width:30px; text-align:center;">Qty</th>
                        </tr>
                    </thead>
                    <tbody id="log-tbody">
                        <tr>
                            <td colspan="3" style="text-align:center; color:#8b949e;">Loading history...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
