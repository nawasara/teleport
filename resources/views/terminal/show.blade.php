<!doctype html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
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
        $terminalConfig = json_encode([
            'ws_url' => $wsUrl,
            'node' => $node,
            'target_user' => $targetUser,
            'login' => $login,
        ], JSON_UNESCAPED_SLASHES);
    @endphp
    {{-- Bootstrap data — pakai hidden input + JSON parse instead of inline
         JS variable supaya CSP-friendly (no eval / unsafe-inline). --}}
    <script type="application/json" id="terminal-config">{!! $terminalConfig !!}</script>

    <script>
        (function () {
            const config = JSON.parse(document.getElementById('terminal-config').textContent);
            const statusEl = document.getElementById('terminal-status');
            const containerEl = document.getElementById('terminal');
            const disconnectBtn = document.getElementById('btn-disconnect');

            const setStatus = (text, cls) => {
                statusEl.textContent = text;
                statusEl.className = 'status ' + cls;
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

            // === WebSocket bridge ===
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

            ws = new WebSocket(config.ws_url);
            ws.binaryType = 'arraybuffer';

            ws.onopen = () => {
                setStatus('Connected to ' + config.node, 'connected');
                sendResize(); // initial PTY size

                // Forward keystrokes ke server (binary frames TextEncoder)
                term.onData((data) => {
                    if (!ws || ws.readyState !== WebSocket.OPEN) return;
                    ws.send(new TextEncoder().encode(data));
                });

                // Auto-fit + send window-change saat browser resize
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
                setStatus('Disconnected', 'disconnected');
                term.write('\r\n\x1b[33m[session closed — tab dapat ditutup]\x1b[0m\r\n');
                cleanup();
            };

            // Disconnect button — close ws + close tab.
            // Browser akan block window.close() kalau tab tidak di-spawn
            // via window.open(); fallback: just close ws + show notice.
            disconnectBtn.addEventListener('click', () => {
                cleanup();
                setStatus('Disconnecting...', 'disconnected');
                try {
                    window.close();
                } catch (e) { /* ignored */ }
                // Setelah 500ms kalau tab masih ada (window.close blocked),
                // navigate ke nodes page sebagai fallback yang reasonable.
                setTimeout(() => {
                    window.location.href = '{{ url('nawasara-teleport/nodes') }}';
                }, 500);
            });

            // Cleanup saat tab di-close paksa (Ctrl+W, X button)
            window.addEventListener('beforeunload', cleanup);
        })();
    </script>
</body>
</html>
