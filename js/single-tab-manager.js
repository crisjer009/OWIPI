/**
 * OWI Physical Inventory - Single Tab Manager
 * Prevents multiple tabs from piling up when mobile users scan QR codes repeatedly.
 * Communicates across browser tabs using BroadcastChannel + localStorage fallback.
 */
(function (window) {
    'use strict';

    const channelName = 'owipi_scanner_session';
    const storageKey = 'owipi_active_scanner_tab';
    const tabId = 'tab_' + Date.now() + '_' + Math.random().toString(36).substring(2, 9);
    let channel = null;
    let isDeactivated = false;

    // Initialize BroadcastChannel if supported
    if (typeof window.BroadcastChannel === 'function') {
        try {
            channel = new BroadcastChannel(channelName);
            channel.onmessage = function (event) {
                if (!event || !event.data) return;
                if (event.data.type === 'NEW_ACTIVE_TAB' && event.data.tabId !== tabId) {
                    deactivateTab();
                }
            };
        } catch (e) {
            console.warn('[SingleTabManager] BroadcastChannel unavailable, using storage fallback:', e);
        }
    }

    // Storage event fallback (for older mobile browsers & cross-context tabs)
    window.addEventListener('storage', function (e) {
        if (e.key !== storageKey || !e.newValue) return;
        try {
            const data = JSON.parse(e.newValue);
            if (data && data.tabId && data.tabId !== tabId) {
                deactivateTab();
            }
        } catch (err) { }
    });

    // Cleanup when tab is explicitly closed
    window.addEventListener('pagehide', cleanup);
    window.addEventListener('beforeunload', cleanup);

    function cleanup() {
        try {
            const stored = localStorage.getItem(storageKey);
            if (stored) {
                const data = JSON.parse(stored);
                if (data && data.tabId === tabId) {
                    localStorage.removeItem(storageKey);
                }
            }
        } catch (e) { }
    }

    function claimActive() {
        isDeactivated = false;
        const payload = {
            type: 'NEW_ACTIVE_TAB',
            tabId: tabId,
            time: Date.now()
        };

        if (channel) {
            try {
                channel.postMessage(payload);
            } catch (e) { }
        }

        try {
            localStorage.setItem(storageKey, JSON.stringify(payload));
        } catch (e) { }

        hideOverlay();
    }

    function deactivateTab() {
        if (isDeactivated) return;
        isDeactivated = true;

        // 1. Attempt automatic browser tab close
        try {
            window.close();
        } catch (e) { }

        // 2. Stop camera hardware if currently running
        try {
            if (window.html5QrCode && typeof window.html5QrCode.isScanning === 'boolean' && window.html5QrCode.isScanning) {
                window.html5QrCode.stop().catch(function () { });
            } else if (typeof window.stopCameraScanning === 'function') {
                window.stopCameraScanning();
            }
        } catch (e) { }

        // 3. Suspend Web Audio to conserve power
        try {
            if (window.audioCtx && window.audioCtx.state === 'running') {
                window.audioCtx.suspend();
            }
        } catch (e) { }

        // 4. Show the Pause Overlay
        showOverlay();
    }

    function showOverlay() {
        let overlay = document.getElementById('owipi-single-tab-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'owipi-single-tab-overlay';
            overlay.innerHTML = `
                <style>
                    #owipi-single-tab-overlay {
                        position: fixed;
                        top: 0;
                        left: 0;
                        width: 100vw;
                        height: 100vh;
                        background: rgba(13, 17, 23, 0.96);
                        backdrop-filter: blur(8px);
                        -webkit-backdrop-filter: blur(8px);
                        z-index: 9999999;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        padding: 20px;
                        box-sizing: border-box;
                        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                    }
                    .owipi-tab-card {
                        background: #161b22;
                        border: 1px solid rgba(240, 246, 252, 0.12);
                        border-radius: 16px;
                        padding: 24px;
                        max-width: 360px;
                        width: 100%;
                        text-align: center;
                        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.6);
                        color: #c9d1d9;
                    }
                    .owipi-tab-icon {
                        width: 60px;
                        height: 60px;
                        margin: 0 auto 16px;
                        background: rgba(210, 153, 34, 0.15);
                        border: 1px solid rgba(210, 153, 34, 0.3);
                        border-radius: 50%;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        font-size: 28px;
                    }
                    .owipi-tab-title {
                        color: #ffffff;
                        font-size: 1.25rem;
                        font-weight: 700;
                        margin: 0 0 10px 0;
                    }
                    .owipi-tab-desc {
                        font-size: 0.9rem;
                        color: #8b949e;
                        line-height: 1.5;
                        margin: 0 0 20px 0;
                    }
                    .owipi-tab-actions {
                        display: flex;
                        flex-direction: column;
                        gap: 10px;
                    }
                    .owipi-btn-primary {
                        background: linear-gradient(135deg, #2563eb, #1d4ed8);
                        color: #ffffff;
                        border: none;
                        padding: 12px 16px;
                        font-size: 0.95rem;
                        font-weight: 600;
                        border-radius: 8px;
                        cursor: pointer;
                        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
                    }
                    .owipi-btn-secondary {
                        background: rgba(255, 255, 255, 0.05);
                        color: #c9d1d9;
                        border: 1px solid rgba(255, 255, 255, 0.1);
                        padding: 11px 16px;
                        font-size: 0.9rem;
                        font-weight: 500;
                        border-radius: 8px;
                        cursor: pointer;
                    }
                    .owipi-tab-hint {
                        font-size: 0.78rem;
                        color: #6e7681;
                        margin-top: 14px;
                        line-height: 1.4;
                    }
                </style>
                <div class="owipi-tab-card">
                    <div class="owipi-tab-icon">📑</div>
                    <h3 class="owipi-tab-title">Duplicate Tab Paused</h3>
                    <p class="owipi-tab-desc">
                        This scanner was reopened in a newer tab. This tab has been paused to prevent accidental double-counts and save battery.
                    </p>
                    <div class="owipi-tab-actions">
                        <button type="button" class="owipi-btn-primary" id="owipi-btn-reactivate">
                            ↻ Use This Tab Instead
                        </button>
                        <button type="button" class="owipi-btn-secondary" id="owipi-btn-close">
                            ✕ Close Tab
                        </button>
                    </div>
                    <div class="owipi-tab-hint">
                        💡 Tip: You can also swipe this tab away in your mobile browser's tab switcher.
                    </div>
                </div>
            `;

            if (document.body) {
                document.body.appendChild(overlay);
            } else {
                window.addEventListener('DOMContentLoaded', function () {
                    document.body.appendChild(overlay);
                });
            }

            const btnReactivate = document.getElementById('owipi-btn-reactivate');
            if (btnReactivate) {
                btnReactivate.addEventListener('click', function () {
                    claimActive();
                });
            }

            const btnClose = document.getElementById('owipi-btn-close');
            if (btnClose) {
                btnClose.addEventListener('click', function () {
                    try {
                        window.close();
                    } catch (e) { }
                });
            }
        } else {
            overlay.style.display = 'flex';
        }
    }

    function hideOverlay() {
        const overlay = document.getElementById('owipi-single-tab-overlay');
        if (overlay) {
            overlay.style.display = 'none';
        }
    }

    // Auto-claim active status on load
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', claimActive);
    } else {
        claimActive();
    }

    // Expose control API globally if needed
    window.OWIPISingleTab = {
        claimActive: claimActive,
        deactivateTab: deactivateTab,
        getTabId: function () { return tabId; }
    };
})(window);
