<?php
require_once __DIR__ . '/config.php';

$error = '';
$config = loadConfig();

// Save connection settings if submitted as part of the login attempt
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['server_settings_active'])) {
    $newConfig = [
        'server' => isset($_POST['db_server']) ? trim($_POST['db_server']) : 'localhost',
        'port' => isset($_POST['db_port']) ? trim($_POST['db_port']) : '3306',
        'username' => isset($_POST['db_username']) ? trim($_POST['db_username']) : 'root',
        'password' => isset($_POST['db_password']) ? $_POST['db_password'] : ''
    ];
    saveConfig($newConfig);
    $config = array_merge($config, $newConfig);
}

// Redirect if already logged in
if (isLoggedIn()) {
    if (isAdmin()) {
        header('Location: index.php');
    } else {
        header('Location: scan.php');
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = isset($_POST['username']) ? strtoupper(trim($_POST['username'])) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter Username and Password.';
    } else {
        try {
            $db = new OWI_DB();
            
            // Connect to MySQL server and provision the master database dynamically
            $db->initializeDatabase();
            
            // Query the master users table
            $sql = "SELECT id, username, password, role FROM users WHERE username = ?";
            $rows = $db->query($sql, [$username]);
            
            if (!empty($rows)) {
                $user = $rows[0];
                if (password_verify($password, $user['password'])) {
                    // Password matches. Establish active session
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'];
                    
                    // Do not auto-select store, let them choose or create it in index.php/scan.php
                    if ($user['role'] === 'system_admin' || $user['role'] === 'admin') {
                        header('Location: index.php');
                    } else {
                        header('Location: scan.php');
                    }
                    exit;
                } else {
                    $error = 'Invalid password.';
                }
            } else {
                $error = 'User not found in system.';
            }
        } catch (Exception $e) {
            $error = 'Failed to connect/initialize server: ' . $e->getMessage() . '. Please expand Server Settings below to adjust parameters.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - OWI Physical Inventory Gateway</title>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #0b0f19;
            --card-bg: rgba(17, 24, 39, 0.75);
            --card-border: rgba(255, 255, 255, 0.08);
            --text-primary: #f3f4f6;
            --text-secondary: #9ca3af;
            --accent-color: #3b82f6;
            --accent-glow: rgba(59, 130, 246, 0.35);
            --success-color: #10b981;
            --danger-color: #ef4444;
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
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-card {
            width: 100%;
            max-width: 440px;
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            padding: 2.5rem;
            backdrop-filter: blur(20px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
        }

        .logo-area {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 2rem;
            text-align: center;
        }

        .logo-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, var(--accent-color), #06b6d4);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 0 20px var(--accent-glow);
            margin-bottom: 0.5rem;
        }

        .logo-icon svg {
            width: 28px;
            height: 28px;
            fill: white;
        }

        .logo-text {
            font-family: 'Outfit', sans-serif;
            font-size: 1.4rem;
            font-weight: 800;
            line-height: 1.1;
            background: linear-gradient(135deg, #fff, #9ca3af);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .logo-subtitle {
            font-size: 0.75rem;
            color: var(--accent-color);
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 1.5px;
        }

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
            border-radius: 10px;
            padding: 0.85rem 1.1rem;
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

        .btn {
            width: 100%;
            background: var(--accent-color);
            color: white;
            border: none;
            border-radius: 10px;
            padding: 0.85rem;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 4px 15px var(--accent-glow);
            margin-top: 1rem;
        }

        .btn:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        .error-alert {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: #fca5a5;
            padding: 0.75rem 1rem;
            border-radius: 10px;
            font-size: 0.85rem;
            margin-bottom: 1.5rem;
            line-height: 1.5;
        }

        /* Collapsible server settings */
        .collapsible-settings {
            margin-top: 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
            padding-top: 1rem;
        }

        .collapsible-header {
            color: var(--text-secondary);
            font-size: 0.8rem;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            user-select: none;
        }

        .collapsible-header:hover {
            color: white;
        }

        .collapsible-body {
            display: none;
            margin-top: 1rem;
            background: rgba(0, 0, 0, 0.2);
            padding: 1rem;
            border-radius: 10px;
            border: 1px solid var(--card-border);
        }

        .collapsible-body.show {
            display: block;
        }
    </style>
</head>
<body>

    <div class="login-card">
        <div class="logo-area">
            <div class="logo-icon">
                <svg viewBox="0 0 24 24">
                    <path d="M4 4h4v4H4V4zm6 0h4v4h-4V4zm6 0h4v4h-4V4zM4 10h4v4H4v-4zm6 0h4v4h-4v-4zm6 0h4v4h-4v-4zM4 16h4v4H4v-4zm6 0h4v4h-4v-4zm6 0h4v4h-4v-4z"/>
                </svg>
            </div>
            <div class="logo-text">OWI PHYSICAL</div>
            <div class="logo-subtitle">INVENTORY GATEWAY</div>
        </div>

        <?php if (!empty($error)): ?>
            <div class="error-alert">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" class="form-control" placeholder="Enter username" value="<?= isset($_POST['username']) ? htmlspecialchars($_POST['username']) : '' ?>" required autocomplete="username" autofocus oninput="this.value = this.value.toUpperCase()">
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" class="form-control" placeholder="••••••••" required autocomplete="current-password">
            </div>

            <!-- Server Connection Settings Hidden inputs -->
            <input type="hidden" name="server_settings_active" id="server_settings_active" value="0">

            <button type="submit" class="btn">Log In</button>

            <?php if (isset($_GET['sysadmin'])): ?>
            <!-- Collapsible Settings Section -->
            <div class="collapsible-settings">
                <div class="collapsible-header" onclick="toggleSettings()">
                    <span>🛠️ MySQL Server Settings</span>
                    <span id="settings-arrow">▼</span>
                </div>
                <div class="collapsible-body" id="settings-body">
                    <div class="form-group" style="display: grid; grid-template-columns: 2fr 1fr; gap: 8px; margin-bottom: 8px;">
                        <div>
                            <label style="font-size: 0.75rem;">MySQL Host</label>
                            <input type="text" name="db_server" class="form-control" style="padding: 0.5rem 0.75rem; font-size: 0.85rem;" value="<?= htmlspecialchars($config['server']) ?>">
                        </div>
                        <div>
                            <label style="font-size: 0.75rem;">Port</label>
                            <input type="text" name="db_port" class="form-control" style="padding: 0.5rem 0.75rem; font-size: 0.85rem;" value="<?= htmlspecialchars($config['port'] ?? '3306') ?>">
                        </div>
                    </div>
                    <div class="form-group" style="margin-bottom: 8px;">
                        <label style="font-size: 0.75rem;">MySQL User</label>
                        <input type="text" name="db_username" class="form-control" style="padding: 0.5rem 0.75rem; font-size: 0.85rem;" value="<?= htmlspecialchars($config['username']) ?>">
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label style="font-size: 0.75rem;">MySQL Password</label>
                        <input type="password" name="db_password" class="form-control" style="padding: 0.5rem 0.75rem; font-size: 0.85rem;" value="<?= htmlspecialchars($config['password']) ?>" placeholder="Empty if none">
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </form>
    </div>

    <script>
        function toggleSettings() {
            const body = document.getElementById('settings-body');
            const arrow = document.getElementById('settings-arrow');
            const activeInput = document.getElementById('server_settings_active');
            
            if (body.classList.contains('show')) {
                body.classList.remove('show');
                arrow.innerText = '▼';
                activeInput.value = '0';
            } else {
                body.classList.add('show');
                arrow.innerText = '▲';
                activeInput.value = '1';
            }
        }
    </script>
</body>
</html>
