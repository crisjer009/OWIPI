using System;
using System.IO;
using System.Net;
using System.Text;
using System.Threading;
using System.Windows.Forms;

namespace CasioScannerApp
{
    public partial class Form1 : Form
    {
        private string configPath = "";
        private int activeScannedCount = 0;

        public Form1()
        {
            InitializeComponent();
            // Set up local config file path (wrapped in try-catch to prevent Visual Studio Designer crashes)
            try
            {
                configPath = Path.Combine(Path.GetDirectoryName(System.Reflection.Assembly.GetExecutingAssembly().GetName().CodeBase), "config.txt");
            }
            catch
            {
                configPath = "config.txt"; // Safe design-time fallback
            }

            // Enable KeyPreview to intercept keypad presses at the Form level
            this.KeyPreview = true;
            this.KeyDown += new KeyEventHandler(Form1_KeyDown);
            this.KeyPress += new KeyPressEventHandler(Form1_KeyPress);
            this.HelpRequested += new HelpEventHandler(Form1_HelpRequested);
            if (this.btnF1Exit != null)
            {
                this.btnF1Exit.Click += new EventHandler(btnF1Exit_Click);
            }

            // Register Enter key detection on the Locator input box
            if (this.txtLocator != null)
            {
                this.txtLocator.KeyDown += new KeyEventHandler(txtLocator_KeyDown);
            }

            // Register F1 Exit detection on the other config input boxes
            if (this.txtHost != null)
            {
                this.txtHost.KeyDown += new KeyEventHandler(ConfigTextBox_KeyDown);
            }
            if (this.txtStoreCode != null)
            {
                this.txtStoreCode.KeyDown += new KeyEventHandler(ConfigTextBox_KeyDown);
            }
            if (this.txtOperator != null)
            {
                this.txtOperator.KeyDown += new KeyEventHandler(ConfigTextBox_KeyDown);
            }

            // Automatically capitalize inputs for Store Code and Operator
            if (this.txtStoreCode != null)
            {
                this.txtStoreCode.TextChanged += new EventHandler(txtUppercase_TextChanged);
            }
            if (this.txtOperator != null)
            {
                this.txtOperator.TextChanged += new EventHandler(txtUppercase_TextChanged);
            }

            // Register KeyDown detection on the Scans ListView to prevent CE default scrolling
            if (this.lstScans != null)
            {
                this.lstScans.KeyDown += new KeyEventHandler(lstScans_KeyDown);
            }

            // Trust all SSL certificates for HTTPS Cloud support (crucial for legacy devices with old root certificates)
            try
            {
                System.Net.ServicePointManager.CertificatePolicy = new MyCertificatePolicy();
            }
            catch { }
        }

        private void Form1_Load(object sender, EventArgs e)
        {
            this.WindowState = FormWindowState.Maximized;
            AutoStartBarcodeReader();
            CreateDesktopShortcut();
            LoadConfig();
            txtLocator.Text = "1"; // Default to 1 on initial startup
            UpdateInputModeLabel();
            ShowConfigPanel();
        }

        private void AutoStartBarcodeReader()
        {
            string foundPath = "";
            string exePath = "";
            try
            {
                // First, check the standard programs path dynamically
                string programsPath = System.Environment.GetFolderPath(System.Environment.SpecialFolder.Programs);
                if (string.IsNullOrEmpty(programsPath) || !System.IO.Directory.Exists(programsPath))
                {
                    programsPath = @"\Windows\Start Menu";
                }

                foundPath = FindBarcodeReadShortcut(programsPath);

                if (!string.IsNullOrEmpty(foundPath))
                {
                    // Parse the shortcut file to get the real executable path
                    exePath = ParseCeShortcut(foundPath);
                    if (!string.IsNullOrEmpty(exePath) && System.IO.File.Exists(exePath))
                    {
                        System.Diagnostics.Process.Start(exePath, "");
                        return; // Success!
                    }
                }

                // Fallback to direct executable search
                string[] directExes = new string[] {
                    @"\Windows\obread.exe",
                    @"\Windows\ObrSetting.exe",
                    @"\Windows\xrdsp.exe",
                    @"\Windows\BarcodeRead.exe"
                };
                foreach (string exe in directExes)
                {
                    if (System.IO.File.Exists(exe))
                    {
                        System.Diagnostics.Process.Start(exe, "");
                        return; // Success!
                    }
                }

                // If it fails to find any scanner, display a diagnostic message box
                string debugMsg = "Could not start the Barcode Reader utility.\n\n";
                debugMsg += "Programs directory: " + programsPath + "\n";
                if (!string.IsNullOrEmpty(foundPath))
                {
                    debugMsg += "Shortcut found: " + foundPath + "\n";
                    debugMsg += "Parsed Target EXE: " + (string.IsNullOrEmpty(exePath) ? "(Failed to parse)" : exePath);
                }
                else
                {
                    debugMsg += "No shortcut containing 'barcode' or 'read' found under " + programsPath;
                }
                MessageBox.Show(debugMsg, "Scanner Startup Info");
            }
            catch (Exception ex)
            {
                MessageBox.Show("Scanner AutoStart Error: " + ex.Message + "\nTarget path: " + exePath, "Scanner Startup Error");
            }
        }

        private void CreateDesktopShortcut()
        {
            try
            {
                // Get the local path of the currently executing EXE
                string currentExePath = System.Reflection.Assembly.GetExecutingAssembly().GetName().CodeBase;
                if (string.IsNullOrEmpty(currentExePath)) return;

                // On Windows CE / Windows Mobile, the desktop folder is always \Windows\Desktop
                string desktopPath = @"\Windows\Desktop";

                string shortcutFilePath = System.IO.Path.Combine(desktopPath, "OWI PI Scanner.lnk");

                // Format the WinCE shortcut file content: [charCount]#"[path]"
                string target = "\"" + currentExePath + "\"";
                string lnkContent = target.Length.ToString() + "#" + target;

                // Write the shortcut using StreamWriter (fully compatible with .NET CF)
                using (System.IO.StreamWriter sw = new System.IO.StreamWriter(shortcutFilePath, false, System.Text.Encoding.ASCII))
                {
                    sw.Write(lnkContent);
                }
            }
            catch { }
        }

        private string ParseCeShortcut(string lnkPath)
        {
            try
            {
                if (!System.IO.File.Exists(lnkPath)) return null;

                string content = "";
                using (System.IO.StreamReader sr = new System.IO.StreamReader(lnkPath, System.Text.Encoding.ASCII))
                {
                    content = sr.ReadToEnd();
                }

                if (string.IsNullOrEmpty(content)) return null;

                int hashIdx = content.IndexOf('#');
                if (hashIdx == -1 || hashIdx >= content.Length - 1) return null;

                string target = content.Substring(hashIdx + 1).Trim();

                // If path is quoted, extract whatever is between the quotes
                if (target.StartsWith("\""))
                {
                    int endQuote = target.IndexOf('"', 1);
                    if (endQuote != -1)
                    {
                        return target.Substring(1, endQuote - 1);
                    }
                }

                // Otherwise, split by space to remove any trailing arguments
                int spaceIdx = target.IndexOf(' ');
                if (spaceIdx != -1)
                {
                    return target.Substring(0, spaceIdx);
                }

                return target;
            }
            catch
            {
                return null;
            }
        }

        private string FindBarcodeReadShortcut(string dir)
        {
            if (!System.IO.Directory.Exists(dir)) return null;

            // Check files in the current folder
            try
            {
                string[] files = System.IO.Directory.GetFiles(dir);
                foreach (string file in files)
                {
                    string name = System.IO.Path.GetFileNameWithoutExtension(file).ToLower();

                    // Exclude configuration utilities or demos
                    if (name.Contains("setting") || name.Contains("settings") || name.Contains("demo") || name.Contains("test"))
                    {
                        continue;
                    }

                    // Match barcode reader shortcuts specifically
                    if (name.Contains("barcode read") || name.Contains("barcoderead") || name.Contains("obread") || name.Contains("scanner read") || name.Contains("read"))
                    {
                        return file;
                    }
                }
            }
            catch { }

            // Recursively check subfolders
            try
            {
                string[] subdirs = System.IO.Directory.GetDirectories(dir);
                foreach (string subdir in subdirs)
                {
                    string res = FindBarcodeReadShortcut(subdir);
                    if (res != null) return res;
                }
            }
            catch { }

            return null;
        }

        // Reusable helper to handle shortcuts on the scan screen
        private bool HandleScanScreenShortcuts(KeyEventArgs e)
        {
            if (e.KeyCode == Keys.F1 || e.KeyCode == Keys.Help || e.KeyCode == Keys.Menu || (int)e.KeyCode == 18)
            {
                e.Handled = true;
                GoBackToConfig();
                return true;
            }
            else if (e.KeyCode == Keys.F6 || (int)e.KeyCode == 189 || e.KeyCode == Keys.Escape || e.KeyCode == Keys.Left)
            {
                e.Handled = true;
                OpenViewScansPanel();
                return true;
            }
            else if (e.KeyCode == Keys.F4)
            {
                e.Handled = true;
                FinishActiveLocator();
                return true;
            }
            else if (e.KeyCode == Keys.F7 || e.KeyCode == Keys.Right)
            {
                e.Handled = true;
                btnClear_Click(null, null);
                return true;
            }
            return false;
        }

        // Global Keydown handler for F-keys navigation
        private void Form1_KeyDown(object sender, KeyEventArgs e)
        {
            if (e.KeyCode == Keys.F8 || (int)e.KeyCode == 134)
            {
                CycleInputMode();
            }

            if (panelScan.Visible)
            {
                HandleScanScreenShortcuts(e);
            }
            else if (panelViewScans.Visible)
            {
                if (e.KeyCode == Keys.F4)
                {
                    e.Handled = true;
                    btnBackFromView_Click(null, null);
                }
                else if (e.KeyCode == Keys.F7 || e.KeyCode == Keys.Back || e.KeyCode == Keys.Delete || e.KeyCode == Keys.Right)
                {
                    e.Handled = true;
                    btnDeleteScan_Click(null, null);
                }
                else if (e.KeyCode == Keys.Enter)
                {
                    e.Handled = true;
                    btnEditScan_Click(null, null);
                }
            }
            else if (panelConfig.Visible)
            {
                if (e.KeyCode == Keys.F1 || e.KeyCode == Keys.Help || e.KeyCode == Keys.Menu || (int)e.KeyCode == 18)
                {
                    e.Handled = true;
                    this.Close();
                }
            }
        }

        // Intercept standard Windows Help command triggered by physical F1
        private void Form1_HelpRequested(object sender, HelpEventArgs hlpevent)
        {
            if (panelScan.Visible)
            {
                hlpevent.Handled = true;
                GoBackToConfig();
            }
            else if (panelConfig.Visible)
            {
                hlpevent.Handled = true;
                this.Close();
            }
        }

        // Show configuration screen, hide scan screen
        private void ShowConfigPanel()
        {
            panelConfig.Visible = true;
            panelScan.Visible = false;
            panelViewScans.Visible = false;
            txtHost.Focus();
        }

        // Show scan screen, hide configuration screen
        private void ShowScanPanel()
        {
            panelConfig.Visible = false;
            panelScan.Visible = true;
            panelViewScans.Visible = false;

            // Display the active locator name exactly as typed by the operator
            lblActiveLoc.Text = "Locator: " + txtLocator.Text.Trim();

            txtBarcode.Focus();
        }

        // Load configuration from local text file
        private void LoadConfig()
        {
            try
            {
                if (File.Exists(configPath))
                {
                    using (StreamReader sr = new StreamReader(configPath))
                    {
                        string line1 = sr.ReadLine();
                        string line2 = sr.ReadLine();
                        string line3 = sr.ReadLine();
                        string line4 = sr.ReadLine();

                        if (line1 != null) txtHost.Text = line1.Trim();
                        txtStoreCode.Text = ""; // Always start blank on load
                        txtOperator.Text = "";  // Always start blank on load
                        if (line4 != null) txtLocator.Text = line4.Trim(); // Load locator history
                    }
                }
            }
            catch (Exception)
            {
                lblStatus.Text = "Config Load Error";
                lblStatus.ForeColor = System.Drawing.Color.Red;
            }
        }

        // Save configuration to local text file
        private void SaveConfig()
        {
            try
            {
                using (StreamWriter sw = new StreamWriter(configPath, false))
                {
                    sw.WriteLine(txtHost.Text.Trim());
                    sw.WriteLine(txtStoreCode.Text.Trim());
                    sw.WriteLine(txtOperator.Text.Trim());
                    sw.WriteLine(txtLocator.Text.Trim());
                }
            }
            catch { }
        }

        // Configuration Save & Continue button click
        private void btnSaveConfig_Click(object sender, EventArgs e)
        {
            string host = txtHost.Text.Trim();
            string store = txtStoreCode.Text.Trim();
            string op = txtOperator.Text.Trim();
            string loc = txtLocator.Text.Trim();

            if (string.IsNullOrEmpty(host) || string.IsNullOrEmpty(store) || string.IsNullOrEmpty(op) || string.IsNullOrEmpty(loc))
            {
                MessageBox.Show("All configuration fields are required!", "Validation Error", MessageBoxButtons.OK, MessageBoxIcon.Exclamation, MessageBoxDefaultButton.Button1);
                txtLocator.Focus();
                txtLocator.SelectAll();
                return;
            }

            // Auto-prepend "Slot " if numeric
            string finalLoc = loc;
            try
            {
                Convert.ToInt32(loc);
                finalLoc = "Slot " + loc;
            }
            catch { }

            btnSaveConfig.Enabled = false;
            btnSaveConfig.Text = "Validating...";

            Thread thread = new Thread(new ThreadStart(delegate ()
            {
                CheckAndClaimLocatorOnServer(host, store, op, finalLoc);
            }));
            thread.Start();
        }

        private void CheckAndClaimLocatorOnServer(string host, string store, string op, string loc)
        {
            try
            {
                string url = host;
                if (!url.Contains("api.php"))
                {
                    url = url.TrimEnd('/') + "/api.php";
                }
                url += "?action=claim_locator";

                HttpWebRequest request = (HttpWebRequest)WebRequest.Create(url);
                request.Method = "POST";
                request.Timeout = 8000;
                request.ContentType = "application/json";

                // Build clean JSON payload
                string jsonPayload = string.Format(
                    "{{\"locator_name\":\"{0}\",\"scanned_by\":\"{1}\",\"store_code\":\"{2}\"}}",
                    loc.Replace("\\", "\\\\").Replace("\"", "\\\""),
                    op.Replace("\\", "\\\\").Replace("\"", "\\\""),
                    store.Replace("\\", "\\\\").Replace("\"", "\\\"")
                );

                byte[] byteArray = Encoding.UTF8.GetBytes(jsonPayload);
                request.ContentLength = byteArray.Length;

                using (Stream dataStream = request.GetRequestStream())
                {
                    dataStream.Write(byteArray, 0, byteArray.Length);
                }

                using (HttpWebResponse response = (HttpWebResponse)request.GetResponse())
                {
                    using (Stream responseStream = response.GetResponseStream())
                    {
                        using (StreamReader reader = new StreamReader(responseStream, Encoding.UTF8))
                        {
                            string rawResponse = reader.ReadToEnd();
                            ParseClaimResponse(rawResponse);
                        }
                    }
                }
            }
            catch (WebException webEx)
            {
                string msg = "Network error: Connection timed out.";
                if (webEx.Response != null)
                {
                    using (Stream stream = webEx.Response.GetResponseStream())
                    {
                        using (StreamReader reader = new StreamReader(stream))
                        {
                            msg = reader.ReadToEnd();
                        }
                    }
                }
                HandleClaimError(msg);
            }
            catch (Exception ex)
            {
                HandleClaimError("Connection failed: " + ex.Message);
            }
        }

        private void ParseClaimResponse(string rawResponse)
        {
            bool isSuccess = rawResponse.Contains("\"status\":\"success\"") || rawResponse.Contains("\"status\": \"success\"");
            string message = "Unknown Response from server.";

            // Basic parsing of message field
            int msgIdx = rawResponse.IndexOf("\"message\":");
            if (msgIdx != -1)
            {
                int start = rawResponse.IndexOf("\"", msgIdx + 10) + 1;
                int end = rawResponse.IndexOf("\"", start);
                if (start > 0 && end > start)
                {
                    message = rawResponse.Substring(start, end - start);
                }
            }

            // Parse scanned_count from server if returned
            int countVal = 0;
            int countIdx = rawResponse.IndexOf("\"scanned_count\":");
            if (countIdx != -1)
            {
                int start = countIdx + 16;
                int endComma = rawResponse.IndexOf(",", start);
                int endBrace = rawResponse.IndexOf("}", start);
                int end = (endComma != -1 && endComma < endBrace) ? endComma : endBrace;
                if (end != -1)
                {
                    string countStr = rawResponse.Substring(start, end - start).Trim().Replace("}", "").Replace("]", "");
                    try { countVal = Convert.ToInt32(countStr); } catch { }
                }
            }

            this.Invoke(new Action(delegate ()
            {
                btnSaveConfig.Enabled = true;
                btnSaveConfig.Text = "Save Setting";

                if (isSuccess)
                {
                    SaveConfig();
                    ShowScanPanel();
                    lblScannedCount.Text = "Scanned #: " + (countVal + 1);
                }
                else
                {
                    MessageBox.Show(message, "Locator In Use", MessageBoxButtons.OK, MessageBoxIcon.Hand, MessageBoxDefaultButton.Button1);
                    txtLocator.Focus();
                    txtLocator.SelectAll();
                }
            }));
        }

        private void HandleClaimError(string errorMsg)
        {
            // Try parsing message from error if possible
            string message = errorMsg;
            int msgIdx = errorMsg.IndexOf("\"message\":");
            if (msgIdx != -1)
            {
                int start = errorMsg.IndexOf("\"", msgIdx + 10) + 1;
                int end = errorMsg.IndexOf("\"", start);
                if (start > 0 && end > start)
                {
                    message = errorMsg.Substring(start, end - start);
                }
            }

            this.Invoke(new Action(delegate ()
            {
                btnSaveConfig.Enabled = true;
                btnSaveConfig.Text = "Save Setting";
                MessageBox.Show(message, "Connection / Claim Error", MessageBoxButtons.OK, MessageBoxIcon.Hand, MessageBoxDefaultButton.Button1);
                txtLocator.Focus();
                txtLocator.SelectAll();
            }));
        }

        // Auto-submit settings when Enter is pressed in the Locator input box
        private void txtLocator_KeyDown(object sender, KeyEventArgs e)
        {
            if (e.KeyCode == Keys.Enter)
            {
                e.Handled = true;
                btnSaveConfig_Click(sender, e);
            }
            else if (e.KeyCode == Keys.F1 || e.KeyCode == Keys.Help || e.KeyCode == Keys.Menu || (int)e.KeyCode == 18)
            {
                e.Handled = true;
                this.Close();
            }
        }

        // Close form if F1 is pressed inside configuration text boxes
        private void ConfigTextBox_KeyDown(object sender, KeyEventArgs e)
        {
            if (e.KeyCode == Keys.F1 || e.KeyCode == Keys.Help || e.KeyCode == Keys.Menu || (int)e.KeyCode == 18)
            {
                e.Handled = true;
                this.Close();
            }
        }

        // Restrict locator input to numbers only
        private void txtLocator_KeyPress(object sender, KeyPressEventArgs e)
        {
            if (!char.IsDigit(e.KeyChar) && !char.IsControl(e.KeyChar))
            {
                e.Handled = true;
            }
        }

        // Restrict quantity input to numbers only
        private void txtQty_KeyPress(object sender, KeyPressEventArgs e)
        {
            if (!char.IsDigit(e.KeyChar) && !char.IsControl(e.KeyChar))
            {
                e.Handled = true;
            }
        }

        // Automatically capitalize text inputs
        private void txtUppercase_TextChanged(object sender, EventArgs e)
        {
            TextBox tb = sender as TextBox;
            if (tb == null) return;

            string txt = tb.Text;
            bool hasLower = false;
            foreach (char c in txt)
            {
                if (char.IsLower(c))
                {
                    hasLower = true;
                    break;
                }
            }

            if (hasLower)
            {
                int selStart = tb.SelectionStart;
                int selLength = tb.SelectionLength;
                tb.Text = txt.ToUpper();
                tb.SelectionStart = selStart;
                tb.SelectionLength = selLength;
            }
        }

        // Barcode keydown event
        private void txtBarcode_KeyDown(object sender, KeyEventArgs e)
        {
            if (HandleScanScreenShortcuts(e))
            {
                return;
            }

            if (e.KeyCode == Keys.Enter)
            {
                e.Handled = true;
                string barcode = txtBarcode.Text.Trim();
                if (string.IsNullOrEmpty(barcode))
                {
                    BeepError();
                    return;
                }

                // Fetch product description instantly from database
                FetchAndShowProductInfo(barcode);

                // Automatically move focus to QTY text box and select the text
                txtQty.Focus();
                txtQty.SelectAll();
            }
        }

        private void FetchAndShowProductInfo(string barcode)
        {
            string host = txtHost.Text.Trim();
            string store = txtStoreCode.Text.Trim();

            // Clear display first or show loading
            lblItemInfo.Text = "Loading details...";

            Thread thread = new Thread(new ThreadStart(delegate ()
            {
                try
                {
                    string url = host;
                    if (!url.Contains("api.php"))
                    {
                        url = url.TrimEnd('/') + "/api.php";
                    }
                    url += "?action=get_product_info&barcode=" + Uri.EscapeDataString(barcode) + "&store_code=" + Uri.EscapeDataString(store);

                    HttpWebRequest request = (HttpWebRequest)WebRequest.Create(url);
                    request.Method = "GET";
                    request.Timeout = 4000; // 4 seconds timeout

                    using (HttpWebResponse response = (HttpWebResponse)request.GetResponse())
                    {
                        using (Stream responseStream = response.GetResponseStream())
                        {
                            using (StreamReader reader = new StreamReader(responseStream, Encoding.UTF8))
                            {
                                string rawResponse = reader.ReadToEnd();
                                ParseProductInfoResponse(rawResponse);
                            }
                        }
                    }
                }
                catch
                {
                    this.Invoke(new Action(delegate ()
                    {
                        lblItemInfo.Text = "Item Not Found";
                    }));
                }
            }));
            thread.Start();
        }

        private void ParseProductInfoResponse(string rawResponse)
        {
            bool isSuccess = rawResponse.Contains("\"status\":\"success\"") || rawResponse.Contains("\"status\": \"success\"");
            string descr = "Item Not Found";
            string desc2 = "";

            if (isSuccess)
            {
                // Parse description/product name
                int descIdx = rawResponse.IndexOf("\"product_name\":");
                if (descIdx != -1)
                {
                    int start = rawResponse.IndexOf("\"", descIdx + 15) + 1;
                    int end = rawResponse.IndexOf("\"", start);
                    if (start > 0 && end > start)
                    {
                        descr = rawResponse.Substring(start, end - start);
                    }
                }

                // Parse product_type (Desc2)
                int typeIdx = rawResponse.IndexOf("\"product_type\":");
                if (typeIdx != -1)
                {
                    int start = rawResponse.IndexOf("\"", typeIdx + 15) + 1;
                    int end = rawResponse.IndexOf("\"", start);
                    if (start > 0 && end > start)
                    {
                        desc2 = rawResponse.Substring(start, end - start);
                    }
                }
            }

            this.Invoke(new Action(delegate ()
            {
                string finalDesc = descr;
                if (!string.IsNullOrEmpty(desc2))
                {
                    finalDesc += "\n" + desc2;
                }
                lblItemInfo.Text = finalDesc;

                // Change status label from "Ready to scan" to "Description"
                lblStatus.Text = "Description";
                lblStatus.ForeColor = System.Drawing.Color.DarkSlateGray;
            }));
        }

        // QTY keydown event
        private void txtQty_KeyDown(object sender, KeyEventArgs e)
        {
            if (HandleScanScreenShortcuts(e))
            {
                return;
            }

            if (e.KeyCode == Keys.Enter)
            {
                e.Handled = true;
                SubmitScan();
            }
        }

        private void btnSend_Click(object sender, EventArgs e)
        {
            SubmitScan();
        }

        private void btnClear_Click(object sender, EventArgs e)
        {
            txtBarcode.Text = "";
            txtQty.Text = "";
            lblItemInfo.Text = ""; // Clear the item description
            UpdateStatus("Ready to scan", System.Drawing.Color.Black);
            txtBarcode.Focus();
        }

        private void btnBackToConfig_Click(object sender, EventArgs e)
        {
            GoBackToConfig();
        }

        private void GoBackToConfig()
        {
            ShowConfigPanel();
        }

        private int currentInputMode = 0; // 0 = abc, 1 = 123, 2 = ABC

        private void CycleInputMode()
        {
            currentInputMode = (currentInputMode + 1) % 3;
            UpdateInputModeLabel();
        }

        private void UpdateInputModeLabel()
        {
            string modeText = "abc";
            if (currentInputMode == 1) modeText = "123";
            else if (currentInputMode == 2) modeText = "ABC";

            if (lblInputMode != null)
            {
                lblInputMode.Text = "[" + modeText + "]";
            }
        }

        private void Form1_KeyPress(object sender, KeyPressEventArgs e)
        {
            char c = e.KeyChar;
            if (char.IsDigit(c))
            {
                if (currentInputMode != 1)
                {
                    currentInputMode = 1;
                    UpdateInputModeLabel();
                }
            }
            else if (char.IsLower(c))
            {
                if (currentInputMode != 0)
                {
                    currentInputMode = 0;
                    UpdateInputModeLabel();
                }
            }
            else if (char.IsUpper(c))
            {
                if (currentInputMode != 2)
                {
                    currentInputMode = 2;
                    UpdateInputModeLabel();
                }
            }
        }

        private void btnF1Exit_Click(object sender, EventArgs e)
        {
            this.Close();
        }

        private void btnFinish_Click(object sender, EventArgs e)
        {
            FinishActiveLocator();
        }

        private void FinishActiveLocator()
        {
            string loc = txtLocator.Text.Trim();
            // Auto-prepend "Slot " if numeric
            string finalLoc = loc;
            try
            {
                Convert.ToInt32(loc);
                finalLoc = "Slot " + loc;
            }
            catch { }

            DialogResult res = MessageBox.Show(
                "Are you sure you want to finish and close locator: " + finalLoc + "?",
                "Confirm Finish",
                MessageBoxButtons.YesNo,
                MessageBoxIcon.Question,
                MessageBoxDefaultButton.Button2
            );

            if (res == DialogResult.Yes)
            {
                string host = txtHost.Text.Trim();
                string store = txtStoreCode.Text.Trim();

                btnFinish.Enabled = false;
                btnFinish.Text = "Closing...";

                Thread thread = new Thread(new ThreadStart(delegate ()
                {
                    SendCloseLocatorRequest(host, store, finalLoc);
                }));
                thread.Start();
            }
        }

        private void SendCloseLocatorRequest(string host, string store, string loc)
        {
            try
            {
                string url = host;
                if (!url.Contains("api.php"))
                {
                    url = url.TrimEnd('/') + "/api.php";
                }
                url += "?action=close_locator";

                HttpWebRequest request = (HttpWebRequest)WebRequest.Create(url);
                request.Method = "POST";
                request.Timeout = 8000;
                request.ContentType = "application/json";

                string jsonPayload = string.Format(
                    "{{\"locator_name\":\"{0}\",\"store_code\":\"{1}\"}}",
                    loc.Replace("\\", "\\\\").Replace("\"", "\\\""),
                    store.Replace("\\", "\\\\").Replace("\"", "\\\"")
                );

                byte[] byteArray = Encoding.UTF8.GetBytes(jsonPayload);
                request.ContentLength = byteArray.Length;

                using (Stream dataStream = request.GetRequestStream())
                {
                    dataStream.Write(byteArray, 0, byteArray.Length);
                }

                using (HttpWebResponse response = (HttpWebResponse)request.GetResponse())
                {
                    using (Stream responseStream = response.GetResponseStream())
                    {
                        using (StreamReader reader = new StreamReader(responseStream, Encoding.UTF8))
                        {
                            string rawResponse = reader.ReadToEnd();
                            ParseCloseResponse(rawResponse);
                        }
                    }
                }
            }
            catch (Exception ex)
            {
                HandleCloseError("Close failed: " + ex.Message);
            }
        }

        private void ParseCloseResponse(string rawResponse)
        {
            bool isSuccess = rawResponse.Contains("\"status\":\"success\"") || rawResponse.Contains("\"status\": \"success\"");
            string message = "Unknown Response.";

            int msgIdx = rawResponse.IndexOf("\"message\":");
            if (msgIdx != -1)
            {
                int start = rawResponse.IndexOf("\"", msgIdx + 10) + 1;
                int end = rawResponse.IndexOf("\"", start);
                if (start > 0 && end > start)
                {
                    message = rawResponse.Substring(start, end - start);
                }
            }

            this.Invoke(new Action(delegate ()
            {
                btnFinish.Enabled = true;
                btnFinish.Text = "F4: Finish";

                if (isSuccess)
                {
                    MessageBox.Show("Locator closed successfully!", "Success", MessageBoxButtons.OK, MessageBoxIcon.Asterisk, MessageBoxDefaultButton.Button1);
                    ShowConfigPanel();
                    txtLocator.Focus();
                    txtLocator.SelectAll();
                }
                else
                {
                    MessageBox.Show(message, "Error Closing Locator", MessageBoxButtons.OK, MessageBoxIcon.Hand, MessageBoxDefaultButton.Button1);
                }
            }));
        }

        private void HandleCloseError(string errorMsg)
        {
            this.Invoke(new Action(delegate ()
            {
                btnFinish.Enabled = true;
                btnFinish.Text = "F4: Finish";
                MessageBox.Show(errorMsg, "Error", MessageBoxButtons.OK, MessageBoxIcon.Hand, MessageBoxDefaultButton.Button1);
            }));
        }

        private void SubmitScan()
        {
            string host = txtHost.Text.Trim();
            string store = txtStoreCode.Text.Trim();
            string op = txtOperator.Text.Trim();
            string loc = txtLocator.Text.Trim();
            string barcode = txtBarcode.Text.Trim();
            string qtyStr = txtQty.Text.Trim();

            if (string.IsNullOrEmpty(barcode))
            {
                UpdateStatus("Scan a barcode first!", System.Drawing.Color.Red);
                BeepError();
                txtBarcode.Focus();
                return;
            }

            if (string.IsNullOrEmpty(qtyStr))
            {
                UpdateStatus("Enter quantity!", System.Drawing.Color.Red);
                BeepError();
                txtQty.Focus();
                return;
            }

            int qty = 1;
            try
            {
                qty = Convert.ToInt32(qtyStr);
                if (qty <= 0)
                {
                    UpdateStatus("Qty must be > 0!", System.Drawing.Color.Red);
                    BeepError();
                    txtQty.Focus();
                    txtQty.SelectAll();
                    return;
                }
            }
            catch
            {
                UpdateStatus("Invalid qty!", System.Drawing.Color.Red);
                BeepError();
                txtQty.Focus();
                txtQty.SelectAll();
                return;
            }

            UpdateStatus("Sending...", System.Drawing.Color.Blue);
            lblItemInfo.Text = "";

            // Auto-prepend "Slot " if numeric
            string finalLoc = loc;
            try
            {
                Convert.ToInt32(loc);
                finalLoc = "Slot " + loc;
            }
            catch { }

            // Start async thread so scanner UI doesn't freeze
            Thread thread = new Thread(new ThreadStart(delegate ()
            {
                SendPostRequest(host, store, op, finalLoc, barcode, qty);
            }));
            thread.Start();
        }

        private void SendPostRequest(string host, string store, string op, string loc, string barcode, int qty)
        {
            try
            {
                // Ensure correct URL ending
                string url = host;
                if (!url.Contains("api.php"))
                {
                    url = url.TrimEnd('/') + "/api.php";
                }
                url += "?action=submit_scan";

                HttpWebRequest request = (HttpWebRequest)WebRequest.Create(url);
                request.Method = "POST";
                request.Timeout = 8000; // 8 seconds timeout
                request.ContentType = "application/x-www-form-urlencoded";

                // Build url-encoded payload with variable quantity
                string postData = string.Format(
                    "barcode={0}&quantity={1}&location={2}&scanned_by={3}&store_code={4}",
                    Uri.EscapeDataString(barcode),
                    qty,
                    Uri.EscapeDataString(loc),
                    Uri.EscapeDataString(op),
                    Uri.EscapeDataString(store)
                );

                byte[] byteArray = Encoding.UTF8.GetBytes(postData);
                request.ContentLength = byteArray.Length;

                using (Stream dataStream = request.GetRequestStream())
                {
                    dataStream.Write(byteArray, 0, byteArray.Length);
                }

                using (HttpWebResponse response = (HttpWebResponse)request.GetResponse())
                {
                    using (Stream responseStream = response.GetResponseStream())
                    {
                        using (StreamReader reader = new StreamReader(responseStream, Encoding.UTF8))
                        {
                            string rawResponse = reader.ReadToEnd();
                            ParseResponse(rawResponse);
                        }
                    }
                }
            }
            catch (WebException webEx)
            {
                string msg = "Network error: Connection timed out.";
                if (webEx.Response != null)
                {
                    using (Stream stream = webEx.Response.GetResponseStream())
                    {
                        using (StreamReader reader = new StreamReader(stream))
                        {
                            msg = reader.ReadToEnd();
                        }
                    }
                }
                UpdateStatus(msg, System.Drawing.Color.Red);
                BeepError();
            }
            catch (Exception ex)
            {
                UpdateStatus("Connection failed: " + ex.Message, System.Drawing.Color.Red);
                BeepError();
            }
        }

        private void ParseResponse(string rawResponse)
        {
            bool isSuccess = rawResponse.Contains("\"status\":\"success\"") || rawResponse.Contains("\"status\": \"success\"");
            string message = "Unknown Response";
            string descr = "";

            // Basic parsing of message field
            int msgIdx = rawResponse.IndexOf("\"message\":");
            if (msgIdx != -1)
            {
                int start = rawResponse.IndexOf("\"", msgIdx + 10) + 1;
                int end = rawResponse.IndexOf("\"", start);
                if (start > 0 && end > start)
                {
                    message = rawResponse.Substring(start, end - start);
                }
            }

            // Parse description/product name if returned
            int descIdx = rawResponse.IndexOf("\"product_name\":");
            if (descIdx != -1)
            {
                int start = rawResponse.IndexOf("\"", descIdx + 15) + 1;
                int end = rawResponse.IndexOf("\"", start);
                if (start > 0 && end > start)
                {
                    descr = rawResponse.Substring(start, end - start);
                }
            }

            // Parse product_type (Desc2) if returned
            string desc2 = "";
            int typeIdx = rawResponse.IndexOf("\"product_type\":");
            if (typeIdx != -1)
            {
                int start = rawResponse.IndexOf("\"", typeIdx + 15) + 1;
                int end = rawResponse.IndexOf("\"", start);
                if (start > 0 && end > start)
                {
                    desc2 = rawResponse.Substring(start, end - start);
                }
            }

            // Parse scanned_count from server response if returned
            int countVal = -1;
            int countIdx = rawResponse.IndexOf("\"scanned_count\":");
            if (countIdx != -1)
            {
                int start = countIdx + 16;
                int endComma = rawResponse.IndexOf(",", start);
                int endBrace = rawResponse.IndexOf("}", start);
                int end = (endComma != -1 && endComma < endBrace) ? endComma : endBrace;
                if (end != -1)
                {
                    string countStr = rawResponse.Substring(start, end - start).Trim().Replace("}", "").Replace("]", "");
                    try { countVal = Convert.ToInt32(countStr); } catch { }
                }
            }

            this.Invoke(new Action(delegate ()
            {
                if (isSuccess)
                {
                    UpdateStatus("SUCCESS: " + message, System.Drawing.Color.Green);

                    lblItemInfo.Text = ""; // Clear description after successfully saved

                    if (countVal != -1)
                    {
                        lblScannedCount.Text = "Scanned #: " + (countVal + 1);
                    }

                    txtBarcode.Text = ""; // Clear scan input
                    txtQty.Text = "";    // Reset qty input to empty
                    txtBarcode.Focus();   // Focus back to barcode scanner
                    BeepSuccess();
                }
                else
                {
                    UpdateStatus("ERROR: " + message, System.Drawing.Color.Red);
                    BeepError();
                }
            }));
        }

        private void UpdateStatus(string text, System.Drawing.Color color)
        {
            this.Invoke(new Action(delegate ()
            {
                lblStatus.Text = text;
                lblStatus.ForeColor = color;
            }));
        }

        // Play scanner status beeps
        private void BeepSuccess()
        {
            try
            {
                System.Media.SystemSounds.Asterisk.Play();
            }
            catch { }
        }

        private void BeepError()
        {
            try
            {
                System.Media.SystemSounds.Hand.Play();
            }
            catch { }
        }

        private void lstScans_KeyDown(object sender, KeyEventArgs e)
        {
            if (e.KeyCode == Keys.F4 || e.KeyCode == Keys.F7 || e.KeyCode == Keys.Back || e.KeyCode == Keys.Delete || e.KeyCode == Keys.Enter || e.KeyCode == Keys.Left || e.KeyCode == Keys.Right || e.KeyCode == Keys.F6)
            {
                e.Handled = true;

                if (e.KeyCode == Keys.F4)
                {
                    btnBackFromView_Click(null, null);
                }
                else if (e.KeyCode == Keys.F7 || e.KeyCode == Keys.Back || e.KeyCode == Keys.Delete || e.KeyCode == Keys.Right)
                {
                    btnDeleteScan_Click(null, null);
                }
                else if (e.KeyCode == Keys.Enter)
                {
                    btnEditScan_Click(null, null);
                }
            }
        }

        // --- SCANS LIST VIEW & EDIT ACTIONS ---

        private string SendHttpRequest(string action, string method, string postData)
        {
            string url = txtHost.Text.Trim();
            if (!url.EndsWith("api.php"))
            {
                url = url.TrimEnd('/') + "/api.php";
            }

            // Append essential store_code and location query params to all scanner actions
            url += "?action=" + action +
                   "&store_code=" + Uri.EscapeDataString(txtStoreCode.Text.Trim()) +
                   "&location=" + Uri.EscapeDataString(txtLocator.Text.Trim());

            HttpWebRequest request = (HttpWebRequest)WebRequest.Create(url);
            request.Method = method;
            request.Timeout = 8000;

            if (method == "POST")
            {
                request.ContentType = "application/x-www-form-urlencoded";
                byte[] byteArray = Encoding.UTF8.GetBytes(postData);
                request.ContentLength = byteArray.Length;
                using (Stream dataStream = request.GetRequestStream())
                {
                    dataStream.Write(byteArray, 0, byteArray.Length);
                }
            }

            using (HttpWebResponse response = (HttpWebResponse)request.GetResponse())
            {
                using (Stream responseStream = response.GetResponseStream())
                {
                    using (StreamReader reader = new StreamReader(responseStream, Encoding.UTF8))
                    {
                        return reader.ReadToEnd();
                    }
                }
            }
        }

        private void btnViewScans_Click(object sender, EventArgs e)
        {
            OpenViewScansPanel();
        }

        private void OpenViewScansPanel()
        {
            panelScan.Visible = false;
            panelConfig.Visible = false;
            panelViewScans.Visible = true;
            LoadScans();
        }

        private void btnBackFromView_Click(object sender, EventArgs e)
        {
            panelViewScans.Visible = false;
            panelConfig.Visible = false;
            panelScan.Visible = true;
            RefreshScannedCount();
            txtBarcode.Focus();
        }

        private void RefreshScannedCount()
        {
            activeScannedCount = lstScans.Items.Count;
            lblScannedCount.Text = "Scanned #: " + (activeScannedCount + 1);
        }

        private void LoadScans()
        {
            lstScans.Items.Clear();
            try
            {
                string raw = SendHttpRequest("get_scans", "GET", null);
                if (!raw.Contains("\"status\":\"success\"") && !raw.Contains("\"status\": \"success\""))
                {
                    MessageBox.Show("Failed to load scans: Invalid response.");
                    return;
                }

                // Parse scans array: [{"id":...,"barcode":"...","quantity":...,"product_name":"..."}]
                int idx = raw.IndexOf("\"scans\":");
                if (idx == -1) return;

                int startArr = raw.IndexOf("[", idx);
                int endArr = raw.IndexOf("]", startArr);
                if (startArr == -1 || endArr == -1) return;

                string scansArray = raw.Substring(startArr + 1, endArr - startArr - 1).Trim();
                if (scansArray == "") return;

                // Split scansArray by objects: } , {
                string temp = scansArray.Replace("},{", "}~{");
                string[] scanObjects = temp.Split('~');

                foreach (string scanObj in scanObjects)
                {
                    string id = ExtractJsonField(scanObj, "id");
                    string barcode = ExtractJsonField(scanObj, "barcode");
                    string quantity = ExtractJsonField(scanObj, "quantity");
                    string productName = ExtractJsonField(scanObj, "product_name");

                    // Format quantity: e.g. "1.00" -> "1"
                    try
                    {
                        double qVal = double.Parse(quantity);
                        quantity = qVal.ToString();
                    }
                    catch { }

                    ListViewItem item = new ListViewItem(barcode);
                    item.SubItems.Add(quantity);
                    item.SubItems.Add(productName);
                    item.Tag = id; // Save record ID

                    lstScans.Items.Add(item);
                }

                // Auto-select and highlight the 1st item in the list
                if (lstScans.Items.Count > 0)
                {
                    lstScans.Items[0].Selected = true;
                    lstScans.Items[0].Focused = true;
                    lstScans.Focus();
                }
            }
            catch (Exception ex)
            {
                MessageBox.Show("Error loading scans: " + ex.Message);
            }
        }

        private string ExtractJsonField(string json, string fieldName)
        {
            string searchStr = "\"" + fieldName + "\":";
            int idx = json.IndexOf(searchStr);
            if (idx == -1) return "";

            int startVal = idx + searchStr.Length;
            string valuePart = json.Substring(startVal).Trim();

            if (valuePart.StartsWith("\""))
            {
                int endQuote = valuePart.IndexOf("\"", 1);
                if (endQuote != -1)
                {
                    return valuePart.Substring(1, endQuote - 1);
                }
            }
            else
            {
                int endComma = valuePart.IndexOf(",");
                int endBrace = valuePart.IndexOf("}");
                int end = (endComma != -1 && endComma < endBrace) ? endComma : endBrace;
                if (end != -1)
                {
                    return valuePart.Substring(0, end).Trim();
                }
            }
            return "";
        }

        private void btnDeleteScan_Click(object sender, EventArgs e)
        {
            if (lstScans.SelectedIndices.Count == 0)
            {
                MessageBox.Show("Please select an item to delete.");
                return;
            }

            ListViewItem item = lstScans.Items[lstScans.SelectedIndices[0]];
            string id = item.Tag as string;
            string barcode = item.Text;
            string desc = item.SubItems[2].Text;

            if (MessageBox.Show("Delete scan for " + barcode + " (" + desc + ")?", "Confirm Delete",
                MessageBoxButtons.YesNo, MessageBoxIcon.Question, MessageBoxDefaultButton.Button2) == DialogResult.Yes)
            {
                try
                {
                    string postData = string.Format("id={0}&store_code={1}&scanned_by={2}", id, Uri.EscapeDataString(txtStoreCode.Text.Trim()), Uri.EscapeDataString(txtOperator.Text.Trim()));
                    string response = SendHttpRequest("delete_scan", "POST", postData);
                    if (response.Contains("\"status\":\"success\"") || response.Contains("\"status\": \"success\""))
                    {
                        MessageBox.Show("Scan deleted successfully!");
                        LoadScans(); // Reload list
                    }
                    else
                    {
                        MessageBox.Show("Failed to delete: " + response);
                    }
                }
                catch (Exception ex)
                {
                    MessageBox.Show("Error: " + ex.Message);
                }
            }
        }

        private void btnEditScan_Click(object sender, EventArgs e)
        {
            if (lstScans.SelectedIndices.Count == 0)
            {
                MessageBox.Show("Please select an item to edit.");
                return;
            }

            ListViewItem item = lstScans.Items[lstScans.SelectedIndices[0]];
            string id = item.Tag as string;
            string barcode = item.Text;
            string currentQty = item.SubItems[1].Text;

            using (ScanEditDialog dlg = new ScanEditDialog(barcode, currentQty))
            {
                if (dlg.ShowDialog() == DialogResult.OK)
                {
                    string newBarcode = dlg.txtBarcode.Text.Trim();
                    string newQty = dlg.txtQty.Text.Trim();

                    if (string.IsNullOrEmpty(newBarcode))
                    {
                        MessageBox.Show("Barcode cannot be empty.");
                        return;
                    }

                    double testVal;
                    try
                    {
                        testVal = double.Parse(newQty);
                        if (testVal < 0)
                        {
                            MessageBox.Show("Please enter a valid positive quantity.");
                            return;
                        }
                    }
                    catch
                    {
                        MessageBox.Show("Please enter a valid positive quantity.");
                        return;
                    }

                    try
                    {
                        string postData = string.Format("id={0}&barcode={1}&quantity={2}&store_code={3}&scanned_by={4}",
                            id,
                            Uri.EscapeDataString(newBarcode),
                            newQty,
                            Uri.EscapeDataString(txtStoreCode.Text.Trim()),
                            Uri.EscapeDataString(txtOperator.Text.Trim())
                        );
                        string response = SendHttpRequest("edit_scan", "POST", postData);
                        if (response.Contains("\"status\":\"success\"") || response.Contains("\"status\": \"success\""))
                        {
                            MessageBox.Show("Scan updated successfully!");
                            LoadScans(); // Reload list
                        }
                        else
                        {
                            MessageBox.Show("Failed to update: " + response);
                        }
                    }
                    catch (Exception ex)
                    {
                        MessageBox.Show("Error: " + ex.Message);
                    }
                }
            }
        }
    }

    // Dynamic Edit dialog form for WinCE
    public class ScanEditDialog : Form
    {
        public TextBox txtBarcode;
        public TextBox txtQty;

        public ScanEditDialog(string currentBarcode, string currentQty)
        {
            this.Width = 210;
            this.Height = 160;
            this.Text = "Edit Scan";
            this.MaximizeBox = false;
            this.MinimizeBox = false;
            this.FormBorderStyle = FormBorderStyle.FixedDialog;

            Label lblBarcode = new Label();
            lblBarcode.Left = 10;
            lblBarcode.Top = 8;
            lblBarcode.Width = 190;
            lblBarcode.Height = 15;
            lblBarcode.Text = "Barcode:";

            txtBarcode = new TextBox();
            txtBarcode.Left = 10;
            txtBarcode.Top = 24;
            txtBarcode.Width = 180;
            txtBarcode.Text = currentBarcode;
            txtBarcode.KeyDown += new KeyEventHandler(txtBarcode_Edit_KeyDown);

            Label lblQty = new Label();
            lblQty.Left = 10;
            lblQty.Top = 49;
            lblQty.Width = 190;
            lblQty.Height = 15;
            lblQty.Text = "Quantity:";

            txtQty = new TextBox();
            txtQty.Left = 10;
            txtQty.Top = 65;
            txtQty.Width = 180;
            txtQty.Text = currentQty;
            txtQty.KeyDown += new KeyEventHandler(txtQty_Edit_KeyDown);
            txtQty.KeyPress += new KeyPressEventHandler(txtQty_Edit_KeyPress);

            Button btnOk = new Button();
            btnOk.Text = "OK";
            btnOk.Left = 10;
            btnOk.Top = 93;
            btnOk.Width = 80;
            btnOk.DialogResult = DialogResult.OK;

            Button btnCancel = new Button();
            btnCancel.Text = "Cancel";
            btnCancel.Left = 110;
            btnCancel.Top = 93;
            btnCancel.Width = 80;
            btnCancel.DialogResult = DialogResult.Cancel;

            this.Controls.Add(lblBarcode);
            this.Controls.Add(txtBarcode);
            this.Controls.Add(lblQty);
            this.Controls.Add(txtQty);
            this.Controls.Add(btnOk);
            this.Controls.Add(btnCancel);

            // Focus barcode and select all initially
            txtBarcode.Focus();
            txtBarcode.SelectAll();
        }

        private void txtBarcode_Edit_KeyDown(object sender, KeyEventArgs e)
        {
            if (e.KeyCode == Keys.Enter)
            {
                e.Handled = true;
                txtQty.Focus();
                txtQty.SelectAll();
            }
        }

        private void txtQty_Edit_KeyDown(object sender, KeyEventArgs e)
        {
            if (e.KeyCode == Keys.Enter)
            {
                e.Handled = true;
                this.DialogResult = DialogResult.OK;
                this.Close();
            }
        }

        private void txtQty_Edit_KeyPress(object sender, KeyPressEventArgs e)
        {
            if (e.KeyChar == (char)13 || e.KeyChar == '\r')
            {
                e.Handled = true;
                this.DialogResult = DialogResult.OK;
                this.Close();
            }
        }
    }

    // Custom certificate policy to accept all SSL certificates (crucial for legacy devices with old root certs)
    public class MyCertificatePolicy : System.Net.ICertificatePolicy
    {
        public bool CheckValidationResult(System.Net.ServicePoint srvPoint, System.Security.Cryptography.X509Certificates.X509Certificate certificate, System.Net.WebRequest request, int certificateProblem)
        {
            return true; // Always trust the certificate
        }
    }
}
