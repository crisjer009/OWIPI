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
            this.HelpRequested += new HelpEventHandler(Form1_HelpRequested);

            // Register Enter key detection on the Locator input box
            if (this.txtLocator != null)
            {
                this.txtLocator.KeyDown += new KeyEventHandler(txtLocator_KeyDown);
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
            LoadConfig();
            txtLocator.Text = "1"; // Default to 1 on initial startup
            ShowConfigPanel();
        }

        // Global Keydown handler for F1 and F4
        private void Form1_KeyDown(object sender, KeyEventArgs e)
        {
            if (panelScan.Visible)
            {
                if (e.KeyCode == Keys.F1 || e.KeyCode == Keys.Help)
                {
                    e.Handled = true;
                    GoBackToConfig();
                }
                else if (e.KeyCode == Keys.F4)
                {
                    e.Handled = true;
                    FinishActiveLocator();
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
        }

        // Show configuration screen, hide scan screen
        private void ShowConfigPanel()
        {
            panelConfig.Visible = true;
            panelScan.Visible = false;
            txtHost.Focus();
        }

        // Show scan screen, hide configuration screen
        private void ShowScanPanel()
        {
            panelConfig.Visible = false;
            panelScan.Visible = true;

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

        // Barcode keydown event
        private void txtBarcode_KeyDown(object sender, KeyEventArgs e)
        {
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
                        lblItemInfo.Text = "Unknown Product";
                    }));
                }
            }));
            thread.Start();
        }

        private void ParseProductInfoResponse(string rawResponse)
        {
            bool isSuccess = rawResponse.Contains("\"status\":\"success\"") || rawResponse.Contains("\"status\": \"success\"");
            string descr = "Unknown Product";
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
                btnFinish.Text = "F4: Finish Locator";

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
                btnFinish.Text = "F4: Finish Locator";
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

            int qty = 1;
            try
            {
                qty = Convert.ToInt32(qtyStr);
                if (qty <= 0) qty = 1;
            }
            catch
            {
                qty = 1;
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
                    UpdateStatus("SUCCESS: Saved!", System.Drawing.Color.Green);
                    
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
