# 📘 OWI Physical Inventory — Control Dashboard Visual Manual

> 💡 **Interactive Visual Presentation**: You can also open the interactive presentation deck directly in your browser:  
> 👉 [**Open Interactive HTML Presentation Manual**](file:///c:/xampp/htdocs/OWIPI/docs/control_dashboard_visual_manual.html)

---

## 🖥️ Visual Layout & Architecture Map

```mermaid
graph TD
    Dashboard["🎛️ Control Dashboard (index.php)"]
    
    subgraph SysAdminCenter ["⚙️ System Administrator Control Center (SYS_ADMIN)"]
        StatusPill["Live Status: MySQL Host | DB Name | 🟢 Connected"]
        BtnConfig["⚙️ Modify Configuration"]
        BtnSandbox["🧪 Resolution Sandbox"]
        BtnInit["⚡ Initialize DB Tables"]
        BtnImport["📦 Import database.sql Backup"]
        BtnReset["🧹 Fresh Inventory Reset (Master Purge)"]
    end
    
    subgraph StoreProgress ["📊 Store Inventory Progress"]
        BtnDownloadCloud["☁️ Download Store Session from Cloud (Local Only)"]
        CardOngoing["🔵 ONGOING Store Card<br>(0-99% Scanned | ☁️ Pull Cloud | Delete)"]
        CardFinished["🟢 FINISHED Store Card<br>(100% Closed | 🔄 Re-open Store | Delete)"]
    end

    subgraph Approvals ["⚠️ Pending Cloud Sync Approvals"]
        SyncQueue["Safety Overwrite Queue<br>[✅ Approve Overwrite] [❌ Reject Request]"]
    end

    Dashboard --> SysAdminCenter
    Dashboard --> StoreProgress
    Dashboard --> Approvals
```

---

## 📑 Visual Section-by-Section Reference

```
+-------------------------------------------------------------------------------------------------------------------+
| ⚙️ System Administrator Control Center  [SYS_ADMIN]                 MYSQL HOST       DATABASE       ● Connected   |
|    Manage database connections, table schemas, backups, and maintenance  localhost:3306   owi_inventory               |
|                                                                                                                   |
| [⚙️ Modify Config]  [🧪 Resolution Sandbox]  [⚡ Initialize DB Tables]  [📦 Import database.sql Backup]  [🧹 Fresh Reset] |
+-------------------------------------------------------------------------------------------------------------------+
```

### 1. System Administrator Control Center

| Control | Visual Style | Purpose & Operational Function |
| :--- | :--- | :--- |
| **Status Pill** | `Host: 3306` \| `DB` \| 🟢 `Connected` | Real-time database heartbeat monitor. Confirms active PDO connection. |
| **⚙️ Modify Configuration** | Dark Ghost Button | Opens the database & sync configuration form to adjust ports, passwords, or secret tokens. |
| **🧪 Resolution Sandbox** | Purple Glowing Pill | Launches the live multi-device resolution testing simulator (`sandbox.php`). |
| **⚡ Initialize DB Tables** | Emerald Green Button | Builds/validates mandatory system tables (`stores`, `items`, `users`, `audit_logs`, `cloud_backups`). |
| **📦 Import database.sql** | Blue Accent Button | Imports default catalog, store IDs, and master schema without using phpMyAdmin. |
| **🧹 Fresh Inventory Reset** | Crimson Danger Button | **Master Purge Tool**: Drops all dynamic store tables while preserving `items`, `stores_id`, and `users`. |

---

### 2. Store Inventory Progress & Session Cards

```
+-------------------------------------------------------------------------------------------------------------------+
| Store Inventory Progress                                                        [☁️ Download Store Session from Cloud] |
|                                                                                                                   |
| +--------------------------------+  +--------------------------------+  +--------------------------------+        |
| | RME                  [ONGOING] |  | SME                 [FINISHED] |  | TES                 [FINISHED] |        |
| | Created by: MARK               |  | Created by: BETH               |  | Created by: ALLAN              |        |
| | Store Completion            0% |  | Store Completion          100% |  | Store Completion          100% |        |
| | [                            ] |  | [============================] |  | [============================] |        |
| | 0 of 3 closed                  |  | 3 of 3 closed                  |  | 10 of 10 closed                |        |
| | [☁️ Pull Cloud Session] [Delete]|  | [🔄 Re-open Store]    [Delete] |  | [🔄 Re-open Store]    [Delete] |        |
| +--------------------------------+  +--------------------------------+  +--------------------------------+        |
+-------------------------------------------------------------------------------------------------------------------+
```

#### Lifecycle State Matrix:

| Status Badge | Progress | Active Controls | Operational Meaning |
| :---: | :---: | :---: | :--- |
| <span style="color:#60a5fa;font-weight:700;">🔵 ONGOING</span> | `0% - 99%` | `☁️ Pull Cloud Session`<br>`Delete` | Store count is active. Scanners are scanning barcodes into open locators. |
| <span style="color:#34d399;font-weight:700;">🟢 FINISHED</span> | `100%` | `🔄 Re-open Store`<br>`Delete` | All locators are closed. Ready for variance export and consolidation. |
| <span style="color:#f87171;font-weight:700;">🔴 CLOSED</span> | `100%` | `🔄 Re-open Store`<br>`Delete` | Count session is archived and read-only. |

---

### 3. Cloud Sync Safety Overwrite Protocol

```mermaid
sequenceDiagram
    autonumber
    actor Operator as 🏪 Local Store Host
    participant Cloud as ☁️ Central Cloud Server
    actor SysAdmin as 👤 Cloud System Admin

    Operator->>Cloud: Submits Store Sync Request (e.g. RME)
    alt First Time Store Upload
        Cloud-->>Operator: Sync Committed Successfully ✅
    else Store Already Exists on Cloud with Scans
        Cloud->>Cloud: Holds request in Pending Approvals Queue ⚠️
        Cloud-->>SysAdmin: Displays "Pending Cloud Sync Approval" Alert
        SysAdmin->>Cloud: Clicks [Approve Overwrite]
        Cloud->>Cloud: Overwrites cloud store with verified local counts
        Cloud-->>Operator: Sync Completed Successfully ✅
    end
```

---

### 4. Resolution Sandbox Simulator

```
+-------------------------------------------------------------------------------------------------------------------+
| 🧪 Resolution Sandbox  [Live Simulator]   Target: [📱 Scanner App (scan.php) ▼]                                   |
| [📟 CE 240x320] [📱 Mobile 375p] [🔄 Land. 812p] [📱 Tablet 768p] [💻 Laptop 768p] [🖥️ 1080p] [🖥️ 2K/4K] [⬅️ Exit]  |
+-------------------------------------------------------------------------------------------------------------------+
|  +-------------------------------------------------------------------------------------------------------------+  |
|  | ● ● ●  Laptop HD (1366x768)                                                               scan.php  🔄      |  |
|  | +---------------------------------------------------------------------------------------------------------+ |  |
|  | |                                                                                                         | |  |
|  | |                                    [ Live Interactive App Viewport ]                                    | |  |
|  | |                                                                                                         | |  |
|  | +---------------------------------------------------------------------------------------------------------+ |  |
|  +-------------------------------------------------------------------------------------------------------------+  |
---

### 5. MySQL Connection Setup & Configuration

```
+-------------------------------------------------------------------------------------------------------------------+
| 🗄️ MySQL Connection Setup                                                                                         |
|    Define credentials to connect to your local or remote MySQL database                                           |
|                                                                                                                   |
|  +-------------------------------------------------------------------------------------------------------------+  |
|  | 🗄️ MySQL Database Connection                                                                  [Show Fields] |  |
|  | ● MySQL Server Connected Successfully.                             [💾 Set Current DB as Default]           |  |
|  | Host: localhost:3306 | Database: owi_physical_inventory                                                         |  |
|  +-------------------------------------------------------------------------------------------------------------+  |
|                                                                                                                   |
|  +-------------------------------------------------------------------------------------------------------------+  |
|  | 🔒 Security & Synchronization Token                                                                           |  |
|  | Secret Sync Token (Cloud & Local Authorization)                                                                |  |
|  | [ e.g. my_secure_token_123                                                                                    ] |  |
|  | Define a custom secret token here. This exact same token must be configured on local hosts to allow sync.      |  |
|  | [Save Token Only]                                                                                              |  |
|  +-------------------------------------------------------------------------------------------------------------+  |
|                                                                                                                   |
|  +-------------------------------------------------------------------------------------------------------------+  |
|  | 🖨️ Print Spacing Settings                                                                                     |  |
|  | Top Margin / Spacing (mm)                     Left Margin / Spacing (mm)                                       |  |
|  | [ 0                                       ]   [ 10                                      ]                      |  |
|  | [Save Spacing Settings]                                                                                        |  |
|  +-------------------------------------------------------------------------------------------------------------+  |
+-------------------------------------------------------------------------------------------------------------------+
```

#### Detailed Breakdown of Setup Cards:

| Configuration Card | Available Fields / Controls | Operational Purpose & Best Practice |
| :--- | :--- | :--- |
| **🗄️ MySQL Database Connection** | • `Host` (e.g. `localhost` or IP)<br>• `Port` (`3306`)<br>• `User` & `Password`<br>• `Database Name`<br>• `[💾 Set DB as Default]` | Configures PDO database connectivity. Click **Test Connection** before saving. **Set as Default** locks the database as the primary schema upon application startup. |
| **🔒 Security & Sync Token** | • `Secret Sync Token`<br>• `[Save Token Only]` | Cryptographic handshake key. All local store servers must have the **exact same secret token** as the cloud server to authorize data synchronization and avoid unauthorized pushes. |
| **🖨️ Print Spacing Settings** | • `Top Margin / Spacing (mm)`<br>• `Left Margin / Spacing (mm)`<br>• `[Save Spacing Settings]` | Calibrates physical margin alignment on thermal barcode label printers and countsheet reports in millimeters to prevent edge clipping. |

---

## 6. Quick Reference & Keyboard Navigation

- Open [`docs/control_dashboard_visual_manual.html`](file:///c:/xampp/htdocs/OWIPI/docs/control_dashboard_visual_manual.html) in your browser.
- Use **Left/Right Arrow Keys** (`◀` / `▶`) on your keyboard to navigate between interactive presentation slides.
- Click any **Red Hotspot Pin** (① through ⑫) on Slide 1 or Slide 6 for animated feature walkthrough modals.
