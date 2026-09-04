# OWIPI — Physical Inventory Management System

**OWIPI (OWI Physical Inventory)** is an enterprise-grade physical inventory counting, barcode scanning, and variance reconciliation platform designed for retail store networks and logistics warehouses.

---

## 📚 Interactive Visual Manuals & Digital Twin Simulators

Explore the full ecosystem through our interactive Digital Twin simulators:

| Ecosystem Component | Interactive HTML Simulator | Technical Markdown Guide | Target Role & Scope |
| :--- | :--- | :--- | :--- |
| **📋 Step-by-Step SOP Guide** | [sop_visual_guide.html](docs/sop_visual_guide.html) | [STANDARD_OPERATING_PROCEDURE.md](docs/STANDARD_OPERATING_PROCEDURE.md) | Official 10-Step Operational Procedure (All Roles) |
| **🖥️ Control Dashboard** | [control_dashboard_visual_manual.html](docs/control_dashboard_visual_manual.html) | [CONTROL_DASHBOARD_MANUAL.md](docs/CONTROL_DASHBOARD_MANUAL.md) | System Admins & Controllers (`index.php`) |
| **💻 Host Console** | [host_view_visual_manual.html](docs/host_view_visual_manual.html) | [HOST_VIEW_MANUAL.md](docs/HOST_VIEW_MANUAL.md) | Store Supervisors & Lead Auditors (`scan.php`) |
| **📱 Casio Industrial Scanner** | [casio_scanner_visual_manual.html](docs/casio_scanner_visual_manual.html) | [CASIO_SCANNER_MANUAL.md](docs/CASIO_SCANNER_MANUAL.md) | Floor Staff & Laser Barcode Operators (`OWI PI Scanner App`) |
| **📲 Mobile Phone Scanner** | [mobile_phone_scanner_visual_manual.html](docs/mobile_phone_scanner_visual_manual.html) | [MOBILE_PHONE_SCANNER_MANUAL.md](docs/MOBILE_PHONE_SCANNER_MANUAL.md) | Mobile Auditors & Floor Counters (`mobile_ce.php`) |

---

## 🚀 Key System Features

- **Multi-Terminal Barcode Ingestion**: Supports simultaneous count submissions from Casio Windows CE laser terminals, mobile phone web browsers, and desktop USB laser scanners.
- **Real-Time Locator Progress**: Live circular completion gauges, Items Not Found (`INF`) anomaly detection, and active handheld monitor.
- **Live Scans Stream**: Instantaneous operator attribution, timestamping, and SKU matching.
- **Dual Variance Reporting**:
  - `Export Excel`: Full store masterfile variance workbook (`.xlsx`) comparing system on-hand quantities against scanned quantities with valuation discrepancies.
  - `Print Summary`: Thermal 80mm receipt printing for consolidated countsheets and detailed discrepancy audit sheets (`Print Summary` vs `Print with Variance`).
- **Audit Trail & Adjustments**: Dedicated `Items in Locator` inspection dialog with manual `+ Add Item` overrides and `Print Edits` accountability receipts.

---

## 🛠️ Technology Stack

- **Backend**: PHP 8.x, PDO, REST API
- **Database**: MySQL 5.7+ / MariaDB (XAMPP environment)
- **Frontend**: HTML5, Vanilla JavaScript, CSS Glassmorphism Design System
- **Mobile Terminal**: Windows CE / Windows Mobile (.NET Compact Framework client)
