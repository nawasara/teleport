<!doctype html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    {{-- CSRF token untuk POST reissue endpoint dari JS. Terminal page
         standalone (bukan extends app layout) jadi meta tag harus
         di-render di sini sendiri. --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Terminal: {{ $node }} — Nawasara</title>

    {{-- xterm.js v5.5 dari CDN. Kalau di production butuh offline,
         migrate ke local assets via npm/Vite. --}}
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@xterm/xterm@5.5.0/css/xterm.css" />
    <script src="https://cdn.jsdelivr.net/npm/@xterm/xterm@5.5.0/lib/xterm.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@xterm/addon-fit@0.10.0/lib/addon-fit.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@xterm/addon-web-links@0.11.0/lib/addon-web-links.js"></script>

    <style>
        /* Reset + fullscreen layout. Body padding 0 supaya xterm fit
           edge-to-edge. Header tipis di atas dengan session info. */
        * { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; padding: 0; background: #000; color: #e5e7eb; font-family: ui-sans-serif, system-ui, sans-serif; }

        .terminal-shell {
            display: flex;
            flex-direction: column;
            height: 100vh;
            width: 100vw;
            background: #000;
        }

        .terminal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 16px;
            background: #0a0a0a;
            border-bottom: 1px solid #262626;
            flex-shrink: 0;
            gap: 16px;
            font-size: 13px;
        }

        .terminal-header .session-info {
            display: flex;
            gap: 16px;
            align-items: center;
            flex-wrap: wrap;
            min-width: 0; /* allow truncate */
        }

        .terminal-header .session-info .label {
            color: #737373;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .terminal-header .session-info .value {
            color: #e5e7eb;
            font-family: ui-monospace, "SF Mono", Menlo, monospace;
            font-size: 13px;
        }

        .terminal-header .status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 2px 10px;
            border-radius: 9999px;
            font-size: 11px;
            font-family: ui-monospace, "SF Mono", Menlo, monospace;
            white-space: nowrap;
        }

        .terminal-header .status.connecting { background: #422006; color: #fbbf24; }
        .terminal-header .status.connected { background: #052e16; color: #4ade80; }
        .terminal-header .status.disconnected { background: #450a0a; color: #f87171; }
        .terminal-header .status::before {
            content: ''; width: 6px; height: 6px; border-radius: 9999px; background: currentColor;
        }
        .terminal-header .status.connecting::before { animation: pulse 1.5s infinite; }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }

        .terminal-header .actions button {
            background: transparent;
            color: #a3a3a3;
            border: 1px solid #404040;
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.15s;
        }
        .terminal-header .actions button:hover {
            background: #1f1f1f;
            color: #fafafa;
            border-color: #525252;
        }

        .terminal-container {
            flex: 1;
            overflow: hidden;
            padding: 8px;
            background: #000;
        }

        /* xterm.js mendekati fullscreen dengan FitAddon — pastikan
           parent punya explicit dimensions. */
        .terminal-container .xterm {
            height: 100%;
        }

        /* Hide xterm scrollbar di Webkit — keep functionality, hilangkan
           visual clutter. Native shortcut Shift+PgUp/Dn tetap jalan. */
        .terminal-container .xterm-viewport::-webkit-scrollbar {
            width: 6px;
        }
        .terminal-container .xterm-viewport::-webkit-scrollbar-thumb {
            background: #404040;
            border-radius: 3px;
        }
    </style>
</head>
<body>
    <div class="terminal-shell">
        <header class="terminal-header">
            <div class="session-info">
                <div>
                    <div class="label">Node</div>
                    <div class="value">{{ $node }}</div>
                </div>
                <div>
                    <div class="label">User</div>
                    <div class="value">{{ $targetUser }}</div>
                </div>
                <div>
                    <div class="label">Login</div>
                    <div class="value">{{ $login }}</div>
                </div>
                <span id="terminal-status" class="status connecting">Connecting...</span>
            </div>
            <div class="actions">
                {{-- Reconnect button — hidden default, di-show oleh JS saat
                     ws.onclose. Mint ticket baru via POST /reissue, swap
                     WebSocket di-place tanpa reload tab. --}}
                <button type="button" id="btn-reconnect"
                    title="Buat session SSH baru ke node yang sama (replay alasan)"
                    style="display: none; background: #052e16; color: #4ade80; border-color: #14532d;">
                    ↻ Reconnect
                </button>
                {{-- Back to Nodes — fallback kalau Reconnect gagal /
                     window expired. Default hidden, di-show bareng
                     reconnect button. --}}
                <button type="button" id="btn-back"
                    title="Kembali ke halaman Nodes"
                    style="display: none;">
                    ← Nodes
                </button>
                <button type="button" id="btn-disconnect" title="Tutup koneksi SSH dan tab ini">
                    Disconnect
                </button>
            </div>
        </header>

        <main class="terminal-container">
            <div id="terminal" style="height: 100%; width: 100%;"></div>
        </main>
    </div>

    @php
        // Pre-encode config JSON di server side. Blade @json directive
        // sometimes mistinterpret bracket notation di script type=
        // application/json context — pakai plain echo lebih aman.
        //
        // ticket = current ticket UUID, dipakai JS saat klik [Reconnect]
        // untuk POST ke /terminal/{ticket}/reissue (Laravel lookup audit
        // row by ticket_id untuk replay node/login/reason).
        //
        // reissue_url = pre-built endpoint URL untuk fetch POST. Tampaknya
        // redundant dengan ticket, tapi simpan URL utuh supaya JS tidak
        // perlu hardcode prefix path /nawasara-teleport/...
        $terminalConfig = json_encode([
            'ws_url' => $wsUrl,
            'node' => $node,
            'target_user' => $targetUser,
            'login' => $login,
            'ticket' => $ticket,
            'reissue_url' => route('nawasara-teleport.terminal.reissue', ['ticket' => $ticket]),
            'nodes_url' => url('nawasara-teleport/nodes'),
        ], JSON_UNESCAPED_SLASHES);
    @endphp
    {{-- Bootstrap data — pakai hidden input + JSON parse instead of inline
         JS variable supaya CSP-friendly (no eval / unsafe-inline). --}}
    <script type="application/json" id="terminal-config">{!! $terminalConfig !!}</script>

    <script>
        (function () {
            const config = JSON.parse(document.getElementById('terminal-config').textContent);
            const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
            const statusEl = document.getElementById('terminal-status');
            const containerEl = document.getElementById('terminal');
            const disconnectBtn = document.getElementById('btn-disconnect');
            const reconnectBtn = document.getElementById('btn-reconnect');
            const backBtn = document.getElementById('btn-back');

            // Mutable state — disengaja deklarasi di luar fungsi supaya
            // bisa di-update saat reconnect (URL ws baru, ticket baru
            // setelah reissue, dll).
            let currentWsUrl = config.ws_url;
            let currentReissueUrl = config.reissue_url;
            let currentTicket = config.ticket;

            // Disengaja flag untuk bedakan "user klik Disconnect" (true)
            // vs "ws drop external" (false). Saat disconnectIntent=true,
            // ws.onclose tidak show reconnect button — admin minta exit.
            let disconnectIntent = false;

            const setStatus = (text, cls) => {
                statusEl.textContent = text;
                statusEl.className = 'status ' + cls;
            };

            const showReconnect = () => {
                reconnectBtn.style.display = '';
                backBtn.style.display = '';
                disconnectBtn.style.display = 'none';
            };

            const hideReconnect = () => {
                reconnectBtn.style.display = 'none';
                backBtn.style.display = 'none';
                disconnectBtn.style.display = '';
            };

            // Init xterm dengan theme dark + addons (fit + web-links).
            const term = new Terminal({
                cursorBlink: true,
                fontFamily: 'ui-monospace, "SF Mono", Menlo, Monaco, "Courier New", monospace',
                fontSize: 14,
                scrollback: 5000,
                theme: {
                    background: '#000000',
                    foreground: '#e5e7eb',
                    cursor: '#10b981',
                    cursorAccent: '#000000',
                    black: '#0a0a0a',
                    red: '#ef4444',
                    green: '#10b981',
                    yellow: '#f59e0b',
                    blue: '#3b82f6',
                    magenta: '#a855f7',
                    cyan: '#06b6d4',
                    white: '#e5e7eb',
                    brightBlack: '#404040',
                    brightRed: '#f87171',
                    brightGreen: '#4ade80',
                    brightYellow: '#fbbf24',
                    brightBlue: '#60a5fa',
                    brightMagenta: '#c084fc',
                    brightCyan: '#22d3ee',
                    brightWhite: '#fafafa',
                },
            });

            const fitAddon = new FitAddon.FitAddon();
            term.loadAddon(fitAddon);
            term.loadAddon(new WebLinksAddon.WebLinksAddon());
            term.open(containerEl);
            fitAddon.fit();
            term.focus();

            // term.onData di-attach SEKALI saja (di luar attachWebSocket).
            // Saat reconnect, ws variable di-update lewat closure — handler
            // ini auto-pickup ws baru. Kalau di-attach per-connect bakal
            // double-trigger setelah reconnect.
            term.onData((data) => {
                if (!ws || ws.readyState !== WebSocket.OPEN) return;
                ws.send(new TextEncoder().encode(data));
            });

            // === WebSocket bridge state ===
            let ws = null;
            let resizeObserver = null;

            const sendResize = () => {
                if (!ws || ws.readyState !== WebSocket.OPEN) return;
                ws.send(JSON.stringify({
                    type: 'resize',
                    cols: term.cols,
                    rows: term.rows,
                }));
            };

            const cleanup = () => {
                if (ws) {
                    try { ws.close(); } catch (e) { /* ignore */ }
                    ws = null;
                }
                if (resizeObserver) {
                    try { resizeObserver.disconnect(); } catch (e) { /* ignore */ }
                    resizeObserver = null;
                }
            };

            // attachWebSocket — open ws ke wsUrl, wire events. Bisa di-call
            // ulang setelah reconnect (cleanup() dulu sebelum re-attach).
            const attachWebSocket = (wsUrl) => {
                ws = new WebSocket(wsUrl);
                ws.binaryType = 'arraybuffer';

                ws.onopen = () => {
                    setStatus('Connected to ' + config.node, 'connected');
                    hideReconnect();
                    sendResize(); // initial PTY size

                    // Auto-fit + send window-change saat browser resize.
                    // Disconnect old observer dulu (kalau ada dari sesi
                    // sebelumnya yg ws drop) supaya gak double-fire.
                    if (resizeObserver) {
                        try { resizeObserver.disconnect(); } catch (e) { /* ignore */ }
                    }
                    resizeObserver = new ResizeObserver(() => {
                        try { fitAddon.fit(); } catch (e) { /* ignore */ }
                        sendResize();
                    });
                    resizeObserver.observe(containerEl);
                };

                ws.onmessage = (event) => {
                    if (event.data instanceof ArrayBuffer) {
                        term.write(new Uint8Array(event.data));
                    } else if (typeof event.data === 'string') {
                        // Bridge error / notice (text frames)
                        term.write(event.data);
                    }
                };

                ws.onerror = () => {
                    setStatus('Connection error', 'disconnected');
                };

                ws.onclose = () => {
                    if (disconnectIntent) {
                        // Admin klik Disconnect — tidak show reconnect.
                        setStatus('Disconnected', 'disconnected');
                        term.write('\r\n\x1b[33m[session closed — tab dapat ditutup]\x1b[0m\r\n');
                        return;
                    }
                    // Drop tidak intentional — show reconnect button.
                    setStatus('Disconnected', 'disconnected');
                    term.write('\r\n\x1b[33m[session ended — klik Reconnect untuk session baru, atau Disconnect/Nodes]\x1b[0m\r\n');
                    showReconnect();
                };
            };

            // First connect.
            attachWebSocket(currentWsUrl);

            // Reconnect button — POST /reissue, replace state, attach ws baru.
            reconnectBtn.addEventListener('click', async () => {
                reconnectBtn.disabled = true;
                setStatus('Reconnecting...', 'connecting');
                term.write('\r\n\x1b[36m[reconnecting — minting cert baru...]\x1b[0m\r\n');

                try {
                    const resp = await fetch(currentReissueUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });

                    const data = await resp.json().catch(() => ({}));
                    if (!resp.ok) {
                        const msg = data.message || ('HTTP ' + resp.status);
                        term.write('\r\n\x1b[31m[reconnect gagal] ' + msg + '\x1b[0m\r\n');
                        setStatus('Reconnect failed', 'disconnected');
                        reconnectBtn.disabled = false;
                        return;
                    }

                    // Sukses — update state ke ticket baru. URL bar juga
                    // di-update via pushState supaya kalau admin Ctrl+R,
                    // landing page-nya match dengan ticket aktif (cache
                    // session info di Laravel masih valid 5 menit).
                    currentWsUrl = data.ws_url;
                    currentTicket = data.ticket_id;
                    currentReissueUrl = '{{ url('nawasara-teleport/terminal') }}/' + data.ticket_id + '/reissue';
                    if (data.terminal_url) {
                        try { window.history.pushState({}, '', data.terminal_url); } catch (e) { /* ignore */ }
                    }

                    cleanup(); // jaga-jaga walau ws sudah closed
                    term.write('\x1b[36m[connected — session baru]\x1b[0m\r\n');
                    attachWebSocket(currentWsUrl);
                } catch (err) {
                    term.write('\r\n\x1b[31m[reconnect error] ' + err.message + '\x1b[0m\r\n');
                    setStatus('Reconnect error', 'disconnected');
                } finally {
                    reconnectBtn.disabled = false;
                }
            });

            // Back to Nodes — kalau admin pilih udahan + balik ke list
            // node manual (mis. mau ke node yang berbeda).
            backBtn.addEventListener('click', () => {
                window.location.href = config.nodes_url;
            });

            // Disconnect button — close ws + close tab.
            // Browser akan block window.close() kalau tab tidak di-spawn
            // via window.open(); fallback: just close ws + show notice.
            disconnectBtn.addEventListener('click', () => {
                disconnectIntent = true;
                cleanup();
                setStatus('Disconnecting...', 'disconnected');
                try {
                    window.close();
                } catch (e) { /* ignored */ }
                // Setelah 500ms kalau tab masih ada (window.close blocked),
                // navigate ke nodes page sebagai fallback yang reasonable.
                setTimeout(() => {
                    window.location.href = config.nodes_url;
                }, 500);
            });

            // Cleanup saat tab di-close paksa (Ctrl+W, X button)
            window.addEventListener('beforeunload', () => {
                disconnectIntent = true;
                cleanup();
            });
        })();
    </script>
</body>
</html>
