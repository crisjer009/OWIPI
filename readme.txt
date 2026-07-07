========================================================================
       OWI PHYSICAL INVENTORY - MOBILE BARCODE GATEWAY SYSTEM
========================================================================

A premium, web-based inventory checking system that allows using cellphones 
with autofocus cameras (acting as handheld terminals) to scan barcodes over 
Wi-Fi and log counts in real-time into a MySQL database.

------------------------------------------------------------------------
1. DIRECTORY STRUCTURE
------------------------------------------------------------------------
The system has been set up inside the XAMPP htdocs folder:
- [index.php]       -> Desktop administrative control panel & diagnostic console
- [scan.php]        -> Mobile-optimized barcode scanning terminal page
- [api.php]         -> API routing for logs, database queries, and catalogs
- [config.php]      -> Core settings, local IP parser, and MySQL connection
- [js/...]          -> Local copies of html5-qrcode.min.js and qrcode.min.js (100% offline)

------------------------------------------------------------------------
2. DATABASE STRUCTURE (MYSQL)
------------------------------------------------------------------------
The system connects to MySQL (database name: owi_physical_inventory). 
When you click "Initialize DB Tables" in the dashboard, the system 
automatically checks/creates the following schema:

A. [inventory_scans] (Log of scanned items):
   - id          (INT AUTO_INCREMENT, Primary Key)
   - barcode     (VARCHAR(100), Scanned barcode number)
   - quantity    (INT, Default 1, Number of items counted)
   - location    (VARCHAR(100), Inventory bin / warehouse aisle location)
   - scanned_by  (VARCHAR(100), Operator name / device ID)
   - scanned_at  (DATETIME, Timestamp of count)

B. [products] (Product lookup catalog - seeds sample items if empty):
   - barcode       (VARCHAR(100), Primary Key)
   - product_name  (VARCHAR(255), Product description)
   - description   (TEXT, Extended notes)
   - sku           (VARCHAR(100), Product SKU)
   - price         (DECIMAL(10,2), Base price)

------------------------------------------------------------------------
3. SETUP AND SYSTEM CONFIGURATION
------------------------------------------------------------------------

STEP A: Start Apache and MySQL in XAMPP
---------------------------------------
1. Open the XAMPP Control Panel.
2. Start the "Apache" module.
3. Start the "MySQL" module.

STEP B: Open the Administrator Panel
------------------------------------
1. Open your browser on the host PC and go to:
   http://localhost/OWIPI/index.php
2. Go to the "MySQL Setup" tab.
3. Enter your MySQL connection details:
   - Server Host: localhost
   - Port: 3306
   - Database Name: owi_physical_inventory
   - Username: root
   - Password: [leave blank by default in XAMPP]
4. Click "Save & Verify Connection".
5. Click "Initialize DB Tables" to verify connection and construct tables automatically.

STEP C: Connect Your Cellphone (Handheld Terminal)
---------------------------------------------------
1. Connect your cellphone to the SAME Wi-Fi network/router as your computer.
2. On the desktop dashboard (index.php), scan the generated connection QR code 
   with your cellphone camera, or type the network URL in your phone's browser:
   http://[your-computer-ip]/OWIPI/scan.php
3. If Chrome blocks camera access due to an insecure origin (HTTP), look at 
   the warning card displayed on your phone screen. It explains how to add the 
   address to your phone's Chrome flags:
   chrome://flags/#unsafely-treat-insecure-origin-as-secure
   (This allows camera access over local HTTP Wi-Fi routers without SSL certificates!)

------------------------------------------------------------------------
4. HOW TO CREATE C:\Program Files\OWI PHYSICAL INVENTORY DIRECTORY & SHORTCUTS
------------------------------------------------------------------------
To package this gateway app like a native Program Files system (e.g. C:\Program Files\OWI Physical Inventory\):

1. Create a folder named: C:\Program Files\OWI Physical Inventory\
2. Create a file inside named "launch_gateway.bat" with the following content:
   --------------------------------------------------
   @echo off
   echo Starting OWI Physical Inventory Portal...
   start http://localhost/OWIPI/index.php
   --------------------------------------------------
3. Create a shortcut of "launch_gateway.bat" and place it on your Desktop.
   You can rename it to "OWI Physical Inventory Gateway" and give it a database/scanner icon.

This shortcut opens the host dashboard on your computer instantly!
========================================================================
