# 📘 OWI Physical Inventory — Control Dashboard Administrator Manual

---

## 📑 Table of Contents
1. [Overview & Access Roles](#1-overview--access-roles)
2. [System Administrator Control Center](#2-system-administrator-control-center)
   - [Live Connection & Database Status Pill](#live-connection--database-status-pill)
   - [Modify Configuration](#modify-configuration)
   - [Resolution Sandbox](#resolution-sandbox)
   - [Initialize DB Tables](#initialize-db-tables)
   - [Import database.sql Backup](#import-databasesql-backup)
   - [Fresh Inventory Reset (Master Purge)](#fresh-inventory-reset-master-purge)
3. [Store Inventory Progress Section](#3-store-inventory-progress-section)
   - [Store Progress Cards & Metrics](#store-progress-cards--metrics)
   - [Pull Cloud Session](#pull-cloud-session)
   - [Re-open Store](#re-open-store)
   - [Delete Store Session](#delete-store-session)
   - [Download Store Session from Cloud](#download-store-session-from-cloud)
4. [Pending Cloud Sync Approvals](#4-pending-cloud-sync-approvals)
5. [Sidebar Gateway & Diagnostics Widget](#5-sidebar-gateway--diagnostics-widget)
6. [Best Practices & Maintenance Workflows](#6-best-practices--maintenance-workflows)

---

## 1. Overview & Access Roles

The **Control Dashboard** (`index.php`) serves as the central administrative mission-control console for the **OWI Physical Inventory (OWIPI)** system. It is designed for **System Administrators (`system_admin` / `sys_admin`)** and **Store Administrators (`admin`)**.

| Role | Permissions & Capabilities |
| :--- | :--- |
| **`system_admin` / `sys_admin`** | **Full System Authority**: Database configuration, schema initialization, SQL backup imports, fresh inventory resets, user account management, audit logs, cloud backup logs, store re-opening, cloud sync approvals, and resolution sandbox access. |
| **`admin`** | **Store Management Authority**: View locator progress, approve/re-open stores, manage local user accounts, view audit logs, and approve cloud sync requests. |
| **`user` (Operator)** | Automatically redirected to [`scan.php`](file:///c:/xampp/htdocs/OWIPI/scan.php) for scanning barcode countsheets. |

---

## 2. System Administrator Control Center

Located at the top of the dashboard, this panel provides direct access to core system operations and live gateway status.

```
+---------------------------------------------------------------------------------------------------------+
| ⚙️ System Administrator Control Center  [SYS_ADMIN]       HOST: localhost:3306   DB: owi_physical_inventory |
|    Manage connections, table schemas, backups, and maintenance                       ● Connected       |
|                                                                                                         |
| [⚙️ Modify Config] [🧪 Resolution Sandbox] [⚡ Initialize Tables] [📦 Import Backup]   [🧹 Fresh Reset]  |
+---------------------------------------------------------------------------------------------------------+
```

### Live Connection & Database Status Pill
- **MySQL Host**: Displays the active database host and port (e.g., `localhost:3306` or IP address).
- **Database**: Shows the connected MySQL schema (`owi_physical_inventory`).
- **Connection Indicator**: 
  - 🟢 **`Connected`**: PHP PDO is connected and executing queries properly.
  - 🔴 **`Offline`**: Connection failed; verify MySQL service in XAMPP and configuration settings.

---

### Action Buttons & Functions

#### 1. ⚙️ Modify Configuration
- **Purpose**: Opens the **MySQL Setup** view (`#view-database`).
- **Use Case**: Update MySQL host, username, password, port, secret synchronization tokens, or label printing margins without editing source code files manually.

#### 2. 🧪 Resolution Sandbox
- **Purpose**: Launches the interactive screen resolution simulator (`sandbox.php`).
- **Use Case**: Allows administrators to preview and test the UI across multiple hardware formats in real time:
  - 📟 **Pocket PC CE** (`240 × 320 px`)
  - 📱 **Mobile Portrait** (`375 × 812 px`) & **Landscape** (`812 × 375 px`)
  - 📱 **Tablet / iPad** (`768 × 1024 px`)
  - 💻 **Laptop HD** (`1366 × 768 px`)
  - 🖥️ **Desktop 1080p & 2K/4K Displays**
- **Target Pages**: Switch live preview between `scan.php`, `index.php`, `mobile_ce.php`, and `login.php`.

#### 3. ⚡ Initialize DB Tables
- **Purpose**: Verifies that all mandatory system tables exist and automatically creates any missing tables:
  - `stores`: Registry of physical store sessions.
  - `items`: Master barcode product catalog.
  - `users`: User and administrator authentication credentials.
  - `audit_logs`: Traceability log for administrative and operator actions.
  - `cloud_backups`: Record of local and cloud backup script snapshots.
- **When to use**: After setting up a new server or when creating a new database.

#### 4. 📦 Import database.sql Backup
- **Purpose**: Automatically executes the local `database.sql` script located in the application root directory.
- **Use Case**: Populates the master product catalog (`items`), store codes (`stores_id`), and default users without needing phpMyAdmin.

#### 5. 🧹 Fresh Inventory Reset (Master Purge)
- **Purpose**: High-level administrative cleanup tool.
- **What it does**:
  - Permanently drops all dynamic store tables (e.g., `{store}_locators`, `{store}_scans`, `{store}_items`).
  - Clears active countsheet logs and resets store session statuses.
  - **✅ PRESERVED TABLES (NEVER DELETED)**:
    1. `items` (Master Product Catalog intact)
    2. `stores_id` (Store ID & branch mapping intact)
    3. `users` (All Administrator & Operator accounts intact)
- **Safety**: Protected by a confirmation dialog where the user must type `RESET` to execute.

---

## 3. Store Inventory Progress Section

This section displays live progress cards for every active store session in the database.

```
+----------------------------------------------------------------------------------------------------+
| Store Inventory Progress                                       [☁️ Download Store Session from Cloud] |
|                                                                                                    |
| +-------------------------+ +-------------------------+ +-------------------------+                |
| | RME           [ONGOING] | | SME          [FINISHED] | | TES          [FINISHED] |                |
| | Created by: MARK        | | Created by: BETH        | | Created by: ALLAN       |                |
| | Store Completion: 0%    | | Store Completion: 100%  | | Store Completion: 100%  |                |
| | [===                  ] | | [=====================] | | [=====================] |                |
| | 0 of 3 closed           | | 3 of 3 closed           | | 10 of 10 closed         |                |
| | [☁️ Pull Cloud] [Delete]| | [🔄 Re-open]   [Delete] | | [🔄 Re-open]   [Delete] |                |
| +-------------------------+ +-------------------------+ +-------------------------+                |
+----------------------------------------------------------------------------------------------------+
```

### Store Progress Cards & Metrics
- **Store Code**: 3-letter branch code (e.g., `RME`, `SME`, `TES`).
- **Created By**: Displays the username of the administrator who opened the session.
- **Status Badge**:
  - 🔵 **`ONGOING`**: Countsheets are open; scanning is currently active.
  - 🟢 **`FINISHED`**: 100% of locators in the store are closed and ready for consolidation.
  - 🔴 **`CLOSED`**: The store session has been finalized and archived.
- **Store Completion Bar**: Real-time percentage calculated as:
  $$\text{Completion \%} = \left( \frac{\text{Closed Locators}}{\text{Total Locators}} \right) \times 100$$
- **Locator Counter**: Displays exact count (e.g., `10 of 10 closed`).

### Card Action Buttons

#### 1. ☁️ Pull Cloud Session
- **Visible On**: Local hosts when a store is in `ONGOING` status.
- **Function**: Downloads the latest locator counts, scans, and catalog updates from the central Cloud server directly to the local server.

#### 2. 🔄 Re-open Store
- **Visible On**: Stores that have reached `FINISHED` or `CLOSED` status.
- **Function**: Re-opens all locators so operators can resume scanning, edit quantities, or add additional slots.

#### 3. Delete
- **Function**: Deletes the store session and its dynamic locator/scan tables after confirmation.

#### 4. ☁️ Download Store Session from Cloud (Top Button)
- **Visible On**: Local server installations only (automatically hidden when running on the Cloud server).
- **Function**: Allows entering any store code to pull its complete data structure from the cloud.

---

## 4. Pending Cloud Sync Approvals

```
+--------------------------------------------------------------------------+
| ⚠️ Pending Cloud Sync Approvals                                [Refresh] [✕]|
|                                                                          |
| • Store RME: Operator 'MARK' requested sync overwrite (1,420 records).   |
|   [✅ Approve Overwrite]   [❌ Reject Request]                           |
+--------------------------------------------------------------------------+
```

- **Purpose**: Safety mechanism preventing accidental overwrites of existing Cloud store records.
- **How it works**:
  1. When a local store tries to sync data to the Cloud, and the store already contains records on the Cloud, the Cloud server halts and holds the request in a pending queue.
  2. The System Administrator on the Cloud Dashboard reviews the request and clicks **Approve** or **Reject**.
  3. Once approved, the synchronization commits safely.

---

## 5. Sidebar Gateway & Diagnostics Widget

The left sidebar provides instant access to system-wide navigation and a real-time gateway monitor:

```
┌───────────────────────────────┐
│ 🎛️ Dashboard                  │
│ ⚙️ MySQL Setup                │
│ 📊 Test Scan Checker          │
│ 📦 Items Masterfile           │
│ 👥 User Accounts              │
│ 📜 Audit Logs                 │
│ ☁️ Cloud Backup Logs          │
│ 🧪 Resolution Sandbox         │
│ 📱 Open Phone Scanner ↗       │
│ 🚪 Log Out                   │
├───────────────────────────────┤
│ USER SESSION                  │
│ 👤 sys_admin                  │
│ Active Store: [RME] [Switch]  │
│                               │
│ GATEWAY STATUS                │
│ MySQL DB:    ● Connected      │
│ PHP Driver:  ● PDO MySQL      │
│ IP: 192.168.1.50              │
└───────────────────────────────┘
```

- **Active Store Indicator**: Displays current store context with a quick `[Switch]` link.
- **PHP Driver**: Shows `PDO MySQL` when active.
- **Server IP**: Displays the local network IPv4 address that mobile barcode scanners should connect to.

---

## 6. Best Practices & Maintenance Workflows

### Starting a New Store Count
1. Navigate to **Control Dashboard**.
2. Click **Create / Select Store Session**.
3. Enter the 3-letter Store Code (e.g., `BGC`) and confirm.
4. Go to **Items Masterfile** and ensure the product catalog is loaded.
5. Click **Open Phone Scanner** or distribute the QR code to operators.

### Finalizing an Inventory Count
1. Verify on the dashboard that the store card displays **`100% Finished`** (`All locators closed`).
2. Open **Host Console** ([`scan.php`](file:///c:/xampp/htdocs/OWIPI/scan.php)) and click **Export Excel** to generate variance reports.
3. Click **Print Summary** for the signed audit countsheet report.
4. On local hosts, click **Sync Store to Cloud** to backup records to central headquarters.
