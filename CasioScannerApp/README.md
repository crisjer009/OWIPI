# OWI PI Casio Handheld Scanner App (Windows CE)

This folder contains the C# source code for the native Windows CE application designed for **CASIO DT-X810E** and **CASIO DT-X7M10R2** handheld barcode scanners.

## Prerequisite Requirements to Compile

1. **Visual Studio:** 
   Use **Visual Studio 2008 Professional** (or VS 2005). These are the versions that contain the native .NET Compact Framework compilers for Windows CE/Windows Mobile.
2. **SDK / Framework:**
   * **.NET Compact Framework v3.5** (or v2.0).
   * **Windows CE 5.0 SDK** (usually pre-packaged with VS2008 or downloadable from Casio Developer portal).

---

## How to Build the Application

1. Open the project file [CasioScannerApp.csproj](file:///c:/xampp/htdocs/OWIPI/CasioScannerApp/CasioScannerApp.csproj) in **Visual Studio 2008**.
2. Set the build configuration to **Release**.
3. Right-click the project and select **Build** (or press `Ctrl+Shift+B`).
4. Go to the project directory under `bin/Release/` to find **`CasioScannerApp.exe`**.

---

## How to Copy & Install on the Casio Scanner

### Quick Transfer via Local Wi-Fi (No Cables Required!)
1. Copy the compiled `CasioScannerApp.exe` to your local XAMPP host server directory:
   `C:\xampp\htdocs\OWIPI\CasioScannerApp.exe`
2. Connect your Casio scanner to the local Wi-Fi router.
3. Open the built-in browser (Internet Explorer Mobile) on the Casio.
4. Go to: `http://<your-host-pc-ip>/OWIPI/CasioScannerApp.exe`
5. Tap **Save As** and store the `.exe` file in the **`FlashDisk`** folder (this keeps the app permanent even if the battery runs out!).

---

## How to Use the App

1. Launch **Windows Explorer** on the Casio.
2. Go to **`FlashDisk`** and double-tap **`CasioScannerApp.exe`**.
3. Fill in the connection settings:
   * **Host URL / IP:** Enter your host server, e.g., `http://192.168.1.100/OWIPI`
   * **Store Code:** The code of the active store (e.g. `TEST`)
   * **Operator Name:** The name of the scan operator
   * **Locator / Slot:** The slot code (e.g. `Slot 1`)
4. Place cursor in **Scan Barcode** and press the physical scanning triggers.
5. The app automatically sends scans in the background over Wi-Fi and gives a success/error sound notification and display message!
