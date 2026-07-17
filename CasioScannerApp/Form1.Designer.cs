namespace CasioScannerApp
{
    partial class Form1
    {
        private System.ComponentModel.IContainer components = null;

        protected override void Dispose(bool disposing)
        {
            if (disposing && (components != null))
            {
                components.Dispose();
            }
            base.Dispose(disposing);
        }

        #region Windows Form Designer generated code

        private void InitializeComponent()
        {
            this.panelHeader = new System.Windows.Forms.Panel();
            this.lblHeader = new System.Windows.Forms.Label();
            this.lblInputMode = new System.Windows.Forms.Label();
            this.btnF1Exit = new System.Windows.Forms.Button();
            this.panelConfig = new System.Windows.Forms.Panel();
            this.txtHost = new System.Windows.Forms.TextBox();
            this.label1 = new System.Windows.Forms.Label();
            this.label2 = new System.Windows.Forms.Label();
            this.txtStoreCode = new System.Windows.Forms.TextBox();
            this.label3 = new System.Windows.Forms.Label();
            this.txtOperator = new System.Windows.Forms.TextBox();
            this.label4 = new System.Windows.Forms.Label();
            this.txtLocator = new System.Windows.Forms.TextBox();
            this.btnSaveConfig = new System.Windows.Forms.Button();
            
            this.panelScan = new System.Windows.Forms.Panel();
            this.btnBackToConfig = new System.Windows.Forms.Button();
            this.lblActiveLoc = new System.Windows.Forms.Label();
            this.lblScannedCount = new System.Windows.Forms.Label();
            this.label5 = new System.Windows.Forms.Label();
            this.txtBarcode = new System.Windows.Forms.TextBox();
            this.label6 = new System.Windows.Forms.Label();
            this.txtQty = new System.Windows.Forms.TextBox();
            this.btnSend = new System.Windows.Forms.Button();
            this.btnClear = new System.Windows.Forms.Button();
            this.btnViewScans = new System.Windows.Forms.Button();
            this.btnFinish = new System.Windows.Forms.Button();
            this.lblStatus = new System.Windows.Forms.Label();
            this.lblItemInfo = new System.Windows.Forms.Label();

            this.panelViewScans = new System.Windows.Forms.Panel();
            this.lstScans = new System.Windows.Forms.ListView();
            this.colBarcode = new System.Windows.Forms.ColumnHeader();
            this.colQty = new System.Windows.Forms.ColumnHeader();
            this.colName = new System.Windows.Forms.ColumnHeader();
            this.btnEditScan = new System.Windows.Forms.Button();
            this.btnDeleteScan = new System.Windows.Forms.Button();
            this.btnBackFromView = new System.Windows.Forms.Button();
            
            this.panelHeader.SuspendLayout();
            this.panelConfig.SuspendLayout();
            this.panelScan.SuspendLayout();
            this.panelViewScans.SuspendLayout();
            this.SuspendLayout();
            // 
            // panelHeader
            // 
            this.panelHeader.BackColor = System.Drawing.Color.MidnightBlue;
            this.panelHeader.Controls.Add(this.lblInputMode);
            this.panelHeader.Controls.Add(this.lblHeader);
            this.panelHeader.Location = new System.Drawing.Point(0, 0);
            this.panelHeader.Name = "panelHeader";
            this.panelHeader.Size = new System.Drawing.Size(238, 26);
            // 
            // lblHeader
            // 
            this.lblHeader.Font = new System.Drawing.Font("Tahoma", 9F, System.Drawing.FontStyle.Bold);
            this.lblHeader.ForeColor = System.Drawing.Color.White;
            this.lblHeader.Location = new System.Drawing.Point(5, 5);
            this.lblHeader.Size = new System.Drawing.Size(185, 18);
            this.lblHeader.Text = "OWI PHYSICAL INVENTORY";
            this.lblHeader.TextAlign = System.Drawing.ContentAlignment.TopLeft;
            // 
            // lblInputMode
            // 
            this.lblInputMode.Font = new System.Drawing.Font("Tahoma", 8F, System.Drawing.FontStyle.Bold);
            this.lblInputMode.ForeColor = System.Drawing.Color.Gold;
            this.lblInputMode.Location = new System.Drawing.Point(192, 5);
            this.lblInputMode.Size = new System.Drawing.Size(43, 16);
            this.lblInputMode.Text = "[abc]";
            this.lblInputMode.TextAlign = System.Drawing.ContentAlignment.TopRight;
            // 
            // panelConfig
            // 
            this.panelConfig.BackColor = System.Drawing.Color.FromArgb(((int)(((byte)(240)))), ((int)(((byte)(240)))), ((int)(((byte)(240)))));
            this.panelConfig.Controls.Add(this.btnF1Exit);
            this.panelConfig.Controls.Add(this.btnSaveConfig);
            this.panelConfig.Controls.Add(this.txtLocator);
            this.panelConfig.Controls.Add(this.label4);
            this.panelConfig.Controls.Add(this.txtOperator);
            this.panelConfig.Controls.Add(this.label3);
            this.panelConfig.Controls.Add(this.txtStoreCode);
            this.panelConfig.Controls.Add(this.label2);
            this.panelConfig.Controls.Add(this.label1);
            this.panelConfig.Controls.Add(this.txtHost);
            this.panelConfig.Location = new System.Drawing.Point(0, 26);
            this.panelConfig.Name = "panelConfig";
            this.panelConfig.Size = new System.Drawing.Size(238, 269);
            // 
            // txtHost
            // 
            this.txtHost.Font = new System.Drawing.Font("Tahoma", 9F, System.Drawing.FontStyle.Regular);
            this.txtHost.Location = new System.Drawing.Point(5, 20);
            this.txtHost.Size = new System.Drawing.Size(228, 21);
            this.txtHost.TabIndex = 0;
            // 
            // label1
            // 
            this.label1.Font = new System.Drawing.Font("Tahoma", 8F, System.Drawing.FontStyle.Bold);
            this.label1.ForeColor = System.Drawing.Color.DarkSlateGray;
            this.label1.Location = new System.Drawing.Point(5, 6);
            this.label1.Size = new System.Drawing.Size(150, 13);
            this.label1.Text = "Host URL / IP:";
            // 
            // label2
            // 
            this.label2.Font = new System.Drawing.Font("Tahoma", 8F, System.Drawing.FontStyle.Bold);
            this.label2.ForeColor = System.Drawing.Color.DarkSlateGray;
            this.label2.Location = new System.Drawing.Point(5, 45);
            this.label2.Size = new System.Drawing.Size(100, 13);
            this.label2.Text = "Store Code:";
            // 
            // txtStoreCode
            // 
            this.txtStoreCode.Font = new System.Drawing.Font("Tahoma", 9F, System.Drawing.FontStyle.Regular);
            this.txtStoreCode.Location = new System.Drawing.Point(5, 59);
            this.txtStoreCode.Size = new System.Drawing.Size(110, 21);
            this.txtStoreCode.TabIndex = 1;
            // 
            // label3
            // 
            this.label3.Font = new System.Drawing.Font("Tahoma", 8F, System.Drawing.FontStyle.Bold);
            this.label3.ForeColor = System.Drawing.Color.DarkSlateGray;
            this.label3.Location = new System.Drawing.Point(120, 45);
            this.label3.Size = new System.Drawing.Size(100, 13);
            this.label3.Text = "Operator Name:";
            // 
            // txtOperator
            // 
            this.txtOperator.Font = new System.Drawing.Font("Tahoma", 9F, System.Drawing.FontStyle.Regular);
            this.txtOperator.Location = new System.Drawing.Point(120, 59);
            this.txtOperator.Size = new System.Drawing.Size(113, 21);
            this.txtOperator.TabIndex = 2;
            // 
            // label4
            // 
            this.label4.Font = new System.Drawing.Font("Tahoma", 8F, System.Drawing.FontStyle.Bold);
            this.label4.ForeColor = System.Drawing.Color.DarkSlateGray;
            this.label4.Location = new System.Drawing.Point(5, 84);
            this.label4.Size = new System.Drawing.Size(228, 13);
            this.label4.Text = "Locator / Slot (Numbers Only):";
            // 
            // txtLocator
            // 
            this.txtLocator.Font = new System.Drawing.Font("Tahoma", 9F, System.Drawing.FontStyle.Regular);
            this.txtLocator.Location = new System.Drawing.Point(5, 98);
            this.txtLocator.Size = new System.Drawing.Size(228, 21);
            this.txtLocator.TabIndex = 3;
            this.txtLocator.KeyPress += new System.Windows.Forms.KeyPressEventHandler(this.txtLocator_KeyPress);
            // 
            // btnSaveConfig
            // 
            this.btnSaveConfig.Font = new System.Drawing.Font("Tahoma", 9.5F, System.Drawing.FontStyle.Bold);
            this.btnSaveConfig.Location = new System.Drawing.Point(5, 135);
            this.btnSaveConfig.Size = new System.Drawing.Size(228, 30);
            this.btnSaveConfig.TabIndex = 4;
            this.btnSaveConfig.Text = "Save Setting";
            this.btnSaveConfig.Click += new System.EventHandler(this.btnSaveConfig_Click);
            // 
            // 
            // btnF1Exit
            // 
            this.btnF1Exit.Font = new System.Drawing.Font("Tahoma", 9.5F, System.Drawing.FontStyle.Bold);
            this.btnF1Exit.Location = new System.Drawing.Point(5, 185);
            this.btnF1Exit.Size = new System.Drawing.Size(228, 30);
            this.btnF1Exit.Text = "F1: EXIT SYSTEM";
            // 
            // panelScan
            // 
            this.panelScan.BackColor = System.Drawing.Color.FromArgb(((int)(((byte)(240)))), ((int)(((byte)(240)))), ((int)(((byte)(240)))));
            this.panelScan.Controls.Add(this.lblScannedCount);
            this.panelScan.Controls.Add(this.lblActiveLoc);
            this.panelScan.Controls.Add(this.btnBackToConfig);
            this.panelScan.Controls.Add(this.label5);
            this.panelScan.Controls.Add(this.txtBarcode);
            this.panelScan.Controls.Add(this.label6);
            this.panelScan.Controls.Add(this.txtQty);
            this.panelScan.Controls.Add(this.btnSend);
            this.panelScan.Controls.Add(this.btnClear);
            this.panelScan.Controls.Add(this.btnViewScans);
            this.panelScan.Controls.Add(this.btnFinish);
            this.panelScan.Controls.Add(this.lblStatus);
            this.panelScan.Controls.Add(this.lblItemInfo);
            this.panelScan.Location = new System.Drawing.Point(0, 26);
            this.panelScan.Name = "panelScan";
            this.panelScan.Size = new System.Drawing.Size(238, 269);
            // 
            // btnBackToConfig
            // 
            this.btnBackToConfig.Font = new System.Drawing.Font("Tahoma", 8F, System.Drawing.FontStyle.Regular);
            this.btnBackToConfig.Location = new System.Drawing.Point(5, 5);
            this.btnBackToConfig.Size = new System.Drawing.Size(100, 22);
            this.btnBackToConfig.TabIndex = 0;
            this.btnBackToConfig.Text = "F1: Change Config";
            this.btnBackToConfig.Click += new System.EventHandler(this.btnBackToConfig_Click);
            // 
            // lblActiveLoc
            // 
            this.lblActiveLoc.Font = new System.Drawing.Font("Tahoma", 8.5F, System.Drawing.FontStyle.Bold);
            this.lblActiveLoc.ForeColor = System.Drawing.Color.Navy;
            this.lblActiveLoc.Location = new System.Drawing.Point(110, 5);
            this.lblActiveLoc.Size = new System.Drawing.Size(123, 13);
            this.lblActiveLoc.Text = "Locator: 1";
            this.lblActiveLoc.TextAlign = System.Drawing.ContentAlignment.TopRight;
            // 
            // lblScannedCount
            // 
            this.lblScannedCount.Font = new System.Drawing.Font("Tahoma", 8F, System.Drawing.FontStyle.Bold);
            this.lblScannedCount.ForeColor = System.Drawing.Color.DarkSlateGray;
            this.lblScannedCount.Location = new System.Drawing.Point(110, 19);
            this.lblScannedCount.Size = new System.Drawing.Size(123, 12);
            this.lblScannedCount.Text = "Scanned #: 0";
            this.lblScannedCount.TextAlign = System.Drawing.ContentAlignment.TopRight;
            // 
            // label5
            // 
            this.label5.Font = new System.Drawing.Font("Tahoma", 8F, System.Drawing.FontStyle.Bold);
            this.label5.ForeColor = System.Drawing.Color.DarkSlateGray;
            this.label5.Location = new System.Drawing.Point(5, 32);
            this.label5.Size = new System.Drawing.Size(120, 14);
            this.label5.Text = "Scan Barcode:";
            // 
            // txtBarcode
            // 
            this.txtBarcode.Font = new System.Drawing.Font("Tahoma", 10F, System.Drawing.FontStyle.Bold);
            this.txtBarcode.Location = new System.Drawing.Point(5, 47);
            this.txtBarcode.Size = new System.Drawing.Size(228, 23);
            this.txtBarcode.TabIndex = 1;
            this.txtBarcode.KeyDown += new System.Windows.Forms.KeyEventHandler(this.txtBarcode_KeyDown);
            // 
            // label6
            // 
            this.label6.Font = new System.Drawing.Font("Tahoma", 8F, System.Drawing.FontStyle.Bold);
            this.label6.ForeColor = System.Drawing.Color.DarkSlateGray;
            this.label6.Location = new System.Drawing.Point(5, 75);
            this.label6.Size = new System.Drawing.Size(120, 14);
            this.label6.Text = "Quantity (QTY):";
            // 
            // txtQty
            // 
            this.txtQty.Font = new System.Drawing.Font("Tahoma", 10F, System.Drawing.FontStyle.Bold);
            this.txtQty.Location = new System.Drawing.Point(5, 90);
            this.txtQty.Size = new System.Drawing.Size(228, 23);
            this.txtQty.TabIndex = 2;
            this.txtQty.Text = "";
            this.txtQty.KeyDown += new System.Windows.Forms.KeyEventHandler(this.txtQty_KeyDown);
            this.txtQty.KeyPress += new System.Windows.Forms.KeyPressEventHandler(this.txtQty_KeyPress);
            // 
            // btnSend
            // 
            this.btnSend.Font = new System.Drawing.Font("Tahoma", 9F, System.Drawing.FontStyle.Bold);
            this.btnSend.Location = new System.Drawing.Point(5, 120);
            this.btnSend.Size = new System.Drawing.Size(110, 30);
            this.btnSend.TabIndex = 3;
            this.btnSend.Text = "Send";
            this.btnSend.Click += new System.EventHandler(this.btnSend_Click);
            // 
            // btnClear
            // 
            this.btnClear.Font = new System.Drawing.Font("Tahoma", 9F, System.Drawing.FontStyle.Bold);
            this.btnClear.Location = new System.Drawing.Point(120, 120);
            this.btnClear.Size = new System.Drawing.Size(113, 30);
            this.btnClear.TabIndex = 4;
            this.btnClear.Text = "F7: Clear";
            this.btnClear.Click += new System.EventHandler(this.btnClear_Click);
            // 
            // btnViewScans
            // 
            this.btnViewScans.Font = new System.Drawing.Font("Tahoma", 9F, System.Drawing.FontStyle.Bold);
            this.btnViewScans.Location = new System.Drawing.Point(5, 155);
            this.btnViewScans.Size = new System.Drawing.Size(110, 30);
            this.btnViewScans.TabIndex = 5;
            this.btnViewScans.Text = "F6: View Scan";
            this.btnViewScans.Click += new System.EventHandler(this.btnViewScans_Click);
            // 
            // btnFinish
            // 
            this.btnFinish.BackColor = System.Drawing.Color.Gold;
            this.btnFinish.Font = new System.Drawing.Font("Tahoma", 9F, System.Drawing.FontStyle.Bold);
            this.btnFinish.ForeColor = System.Drawing.Color.Black;
            this.btnFinish.Location = new System.Drawing.Point(120, 155);
            this.btnFinish.Size = new System.Drawing.Size(113, 30);
            this.btnFinish.TabIndex = 6;
            this.btnFinish.Text = "F4: Finish";
            this.btnFinish.Click += new System.EventHandler(this.btnFinish_Click);
            // 
            // lblStatus
            // 
            this.lblStatus.Font = new System.Drawing.Font("Tahoma", 9.5F, System.Drawing.FontStyle.Bold);
            this.lblStatus.Location = new System.Drawing.Point(5, 190);
            this.lblStatus.Size = new System.Drawing.Size(228, 18);
            this.lblStatus.Text = "Ready to scan";
            this.lblStatus.TextAlign = System.Drawing.ContentAlignment.TopCenter;
            // 
            // lblItemInfo
            // 
            this.lblItemInfo.Font = new System.Drawing.Font("Tahoma", 9.5F, System.Drawing.FontStyle.Bold);
            this.lblItemInfo.ForeColor = System.Drawing.Color.Navy;
            this.lblItemInfo.Location = new System.Drawing.Point(5, 210);
            this.lblItemInfo.Size = new System.Drawing.Size(228, 75);
            this.lblItemInfo.Text = "";
            this.lblItemInfo.TextAlign = System.Drawing.ContentAlignment.TopCenter;
            // 
            // panelViewScans
            // 
            this.panelViewScans.BackColor = System.Drawing.Color.FromArgb(((int)(((byte)(240)))), ((int)(((byte)(240)))), ((int)(((byte)(240)))));
            this.panelViewScans.Controls.Add(this.lstScans);
            this.panelViewScans.Controls.Add(this.btnEditScan);
            this.panelViewScans.Controls.Add(this.btnDeleteScan);
            this.panelViewScans.Controls.Add(this.btnBackFromView);
            this.panelViewScans.Location = new System.Drawing.Point(0, 26);
            this.panelViewScans.Name = "panelViewScans";
            this.panelViewScans.Size = new System.Drawing.Size(238, 269);
            this.panelViewScans.Visible = false;
            // 
            // lstScans
            // 
            this.lstScans.Columns.Add(this.colBarcode);
            this.lstScans.Columns.Add(this.colQty);
            this.lstScans.Columns.Add(this.colName);
            this.lstScans.Font = new System.Drawing.Font("Tahoma", 8F, System.Drawing.FontStyle.Regular);
            this.lstScans.FullRowSelect = true;
            this.lstScans.Location = new System.Drawing.Point(5, 5);
            this.lstScans.Name = "lstScans";
            this.lstScans.Size = new System.Drawing.Size(228, 175);
            this.lstScans.TabIndex = 0;
            this.lstScans.View = System.Windows.Forms.View.Details;
            this.lstScans.HeaderStyle = System.Windows.Forms.ColumnHeaderStyle.Nonclickable;
            // 
            // colBarcode
            // 
            this.colBarcode.Text = "Barcode";
            this.colBarcode.Width = 95;
            // 
            // colQty
            // 
            this.colQty.Text = "Qty";
            this.colQty.Width = 35;
            // 
            // colName
            // 
            this.colName.Text = "Description";
            this.colName.Width = 140;
            // 
            // btnEditScan
            // 
            this.btnEditScan.Font = new System.Drawing.Font("Tahoma", 9F, System.Drawing.FontStyle.Bold);
            this.btnEditScan.Location = new System.Drawing.Point(5, 190);
            this.btnEditScan.Size = new System.Drawing.Size(110, 30);
            this.btnEditScan.TabIndex = 1;
            this.btnEditScan.Text = "Edit";
            this.btnEditScan.Click += new System.EventHandler(this.btnEditScan_Click);
            // 
            // btnDeleteScan
            // 
            this.btnDeleteScan.Font = new System.Drawing.Font("Tahoma", 9F, System.Drawing.FontStyle.Bold);
            this.btnDeleteScan.Location = new System.Drawing.Point(120, 190);
            this.btnDeleteScan.Size = new System.Drawing.Size(113, 30);
            this.btnDeleteScan.TabIndex = 2;
            this.btnDeleteScan.Text = "F7: Delete";
            this.btnDeleteScan.Click += new System.EventHandler(this.btnDeleteScan_Click);
            // 
            // btnBackFromView
            // 
            this.btnBackFromView.Font = new System.Drawing.Font("Tahoma", 9.5F, System.Drawing.FontStyle.Bold);
            this.btnBackFromView.Location = new System.Drawing.Point(5, 226);
            this.btnBackFromView.Size = new System.Drawing.Size(228, 30);
            this.btnBackFromView.TabIndex = 3;
            this.btnBackFromView.Text = "F4: Back to Scan";
            this.btnBackFromView.Click += new System.EventHandler(this.btnBackFromView_Click);
            // 
            // Form1
            // 
            this.AutoScaleDimensions = new System.Drawing.SizeF(96F, 96F);
            this.AutoScaleMode = System.Windows.Forms.AutoScaleMode.Dpi;
            this.AutoScroll = false;
            this.ClientSize = new System.Drawing.Size(238, 295);
            this.Controls.Add(this.panelViewScans);
            this.Controls.Add(this.panelScan);
            this.Controls.Add(this.panelConfig);
            this.Controls.Add(this.panelHeader);
            this.MaximizeBox = false;
            this.MinimizeBox = false;
            this.Name = "Form1";
            this.Text = "OWI PI Scanner App";
            this.WindowState = System.Windows.Forms.FormWindowState.Normal;
            this.Load += new System.EventHandler(this.Form1_Load);
            this.panelHeader.ResumeLayout(false);
            this.panelConfig.ResumeLayout(false);
            this.panelScan.ResumeLayout(false);
            this.panelViewScans.ResumeLayout(false);
            this.ResumeLayout(false);

        }

        #endregion

        private System.Windows.Forms.Panel panelHeader;
        private System.Windows.Forms.Label lblHeader;
        private System.Windows.Forms.Label lblInputMode;
        private System.Windows.Forms.Button btnF1Exit;
        private System.Windows.Forms.Panel panelConfig;
        private System.Windows.Forms.TextBox txtHost;
        private System.Windows.Forms.Label label1;
        private System.Windows.Forms.Label label2;
        private System.Windows.Forms.TextBox txtStoreCode;
        private System.Windows.Forms.Label label3;
        private System.Windows.Forms.TextBox txtOperator;
        private System.Windows.Forms.Label label4;
        private System.Windows.Forms.TextBox txtLocator;
        private System.Windows.Forms.Button btnSaveConfig;
        
        private System.Windows.Forms.Panel panelScan;
        private System.Windows.Forms.Button btnBackToConfig;
        private System.Windows.Forms.Label lblActiveLoc;
        private System.Windows.Forms.Label lblScannedCount;
        private System.Windows.Forms.Label label5;
        private System.Windows.Forms.TextBox txtBarcode;
        private System.Windows.Forms.Label label6;
        private System.Windows.Forms.TextBox txtQty;
        private System.Windows.Forms.Button btnSend;
        private System.Windows.Forms.Button btnClear;
        private System.Windows.Forms.Button btnViewScans;
        private System.Windows.Forms.Button btnFinish;
        private System.Windows.Forms.Label lblStatus;
        private System.Windows.Forms.Label lblItemInfo;

        private System.Windows.Forms.Panel panelViewScans;
        private System.Windows.Forms.ListView lstScans;
        private System.Windows.Forms.ColumnHeader colBarcode;
        private System.Windows.Forms.ColumnHeader colQty;
        private System.Windows.Forms.ColumnHeader colName;
        private System.Windows.Forms.Button btnEditScan;
        private System.Windows.Forms.Button btnDeleteScan;
        private System.Windows.Forms.Button btnBackFromView;
    }
}
