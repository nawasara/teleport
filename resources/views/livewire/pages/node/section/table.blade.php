<div>
    @php
        $statusOptions = ['online' => 'Online', 'offline' => 'Offline'];
    @endphp

    <x-nawasara-ui::page-header
        title="Teleport Nodes"
        description="SSH servers terdaftar di Teleport. Phase 1 read-only — tombol Connect untuk browser-based terminal akan ditambahkan di phase berikutnya."
        :count="count($this->nodes).' / '.$this->summary['total'].' node'">
        @if ($this->health['cluster_name'])
            <span class="text-xs text-gray-500 dark:text-neutral-400">
                Cluster: <code class="font-mono">{{ $this->health['cluster_name'] }}</code>
            </span>
        @endif

        <x-nawasara-ui::icon-button
            icon="refresh-cw"
            tooltip="Refresh dari Teleport (flush cache 60s)"
            wire:click="refresh"
            loadingTarget="refresh" />
    </x-nawasara-ui::page-header>

    {{-- Hero stats — clickable filter cards. Total card = clear filter. --}}
    @php $summary = $this->summary; @endphp
    <div class="grid grid-cols-2 md:grid-cols-3 gap-4 mb-6">
        <x-nawasara-ui::stat-card
            label="Total Nodes"
            :value="number_format($summary['total'])"
            icon="lucide-server-cog"
            color="primary"
            :active="empty($statusFilter)"
            accent
            wire:click="$set('statusFilter', [])" />

        <x-nawasara-ui::stat-card
            label="Online"
            :value="number_format($summary['online'])"
            icon="lucide-circle-check"
            color="success"
            :active="$statusFilter === ['online']"
            accent
            wire:click="$set('statusFilter', ['online'])" />

        <x-nawasara-ui::stat-card
            label="Offline"
            :value="number_format($summary['offline'])"
            icon="lucide-circle-x"
            color="danger"
            :active="$statusFilter === ['offline']"
            accent
            wire:click="$set('statusFilter', ['offline'])" />
    </div>

    {{-- Toolbar — filter + search --}}
    <div class="space-y-2 mb-4">
        <div class="flex flex-col md:flex-row md:flex-nowrap md:items-center gap-2">
            <div class="flex flex-wrap items-center gap-2 shrink-0">
                <x-nawasara-ui::filter-panel
                    label="Filter"
                    :state="['statusFilter' => $statusFilter]"
                    :multiple="['statusFilter']"
                    :labels="['statusFilter' => $statusOptions]"
                    :dimensions="['statusFilter' => 'Status']">
                    <x-nawasara-ui::filter-group label="Status" model="statusFilter" :items="$statusOptions" icon="lucide-circle-check" />
                </x-nawasara-ui::filter-panel>
            </div>

            <x-nawasara-ui::search-input model="search" placeholder="Cari hostname, address, atau label..." />
        </div>

        <div wire:ignore data-filter-chips></div>

        @if ($search)
            <div class="flex flex-wrap items-center gap-2">
                <x-nawasara-ui::filter-chip label="Cari: {{ $search }}" model="search" />
            </div>
        @endif
    </div>

    {{-- Empty state untuk Vault belum di-config. Beda dari "no nodes
         match filter" supaya admin tahu next action-nya beda. --}}
    @if ($this->health['error'] ?? null)
        <x-nawasara-ui::empty-state
            icon="lucide-shield-alert"
            title="Tidak bisa hubungi Teleport bridge"
            :description="'Error: '.$this->health['error'].'. Cek Vault group `teleport` (bridge_url + bridge_secret) atau status sidecar Docker.'"
            variant="filter" />
    @else
        <x-nawasara-ui::table stickyLast
            :headers="['Hostname', 'Address', 'Labels', 'Version', 'Last Heartbeat', 'Status', '']">
            <x-slot:table>
                @forelse ($this->nodes as $node)
                    <tr wire:key="node-{{ $node['name'] ?? '' }}">
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-800 dark:text-neutral-200">
                            {{ $node['hostname'] ?? '-' }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-neutral-400 font-mono">
                            {{ $node['addr'] ?: '-' }}
                        </td>
                        <td class="px-6 py-4 text-sm">
                            @php $labels = $node['labels'] ?? []; @endphp
                            @if (! empty($labels))
                                <div class="flex flex-wrap gap-1 max-w-md">
                                    @foreach ($labels as $key => $value)
                                        @if (! str_starts_with($key, 'teleport.internal/'))
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-mono bg-gray-100 text-gray-700 dark:bg-neutral-700 dark:text-neutral-300">
                                                {{ $key }}={{ $value }}
                                            </span>
                                        @endif
                                    @endforeach
                                </div>
                            @else
                                <span class="text-gray-400">-</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-neutral-400 font-mono">
                            {{ $node['teleport_version'] ?? '-' }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-neutral-400">
                            @php $hb = $node['last_heartbeat_at'] ?? null; @endphp
                            @if ($hb)
                                <span title="{{ $hb }}">{{ \Carbon\Carbon::parse($hb)->diffForHumans() }}</span>
                            @else
                                -
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                            @if ($node['online'] ?? false)
                                <x-nawasara-ui::badge color="success" dot>Online</x-nawasara-ui::badge>
                            @else
                                <x-nawasara-ui::badge color="danger" dot>Offline</x-nawasara-ui::badge>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-right">
                            @can('teleport.ssh.connect')
                                @if ($node['online'] ?? false)
                                    {{-- Connect button: terminal browser-based dengan auto-login
                                         via Teleport cert impersonation. Disabled kalau node offline
                                         (toh hit-nya bakal fail di sidecar). --}}
                                    <x-nawasara-ui::button
                                        size="sm"
                                        color="success"
                                        wire:click="openConnect('{{ addslashes($node['hostname'] ?? '') }}')">
                                        <x-slot:icon><x-lucide-terminal class="size-4" /></x-slot:icon>
                                        Connect
                                    </x-nawasara-ui::button>
                                @else
                                    <span class="text-xs text-gray-400">offline</span>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            @if ($search || ! empty($statusFilter))
                                <x-nawasara-ui::empty-state
                                    icon="lucide-search-x"
                                    title="Tidak ada node yang cocok"
                                    description="Coba ubah filter atau hapus search keyword."
                                    variant="filter"
                                    inline />
                            @else
                                <x-nawasara-ui::empty-state
                                    icon="lucide-server"
                                    title="Belum ada node terdaftar di Teleport"
                                    description="Register nodes via 'Add Server' di Teleport Web UI atau pakai tctl tokens add --type=node."
                                    inline />
                            @endif
                        </td>
                    </tr>
                @endforelse
            </x-slot:table>
        </x-nawasara-ui::table>
    @endif

    {{-- Modal 1: Konfirmasi Connect (alasan akses + login override).
         Pattern same dengan webmail/cpanel launch-as: explicit confirm
         step + audit reason wajib min 10 char, bukan template copy. --}}
    <x-nawasara-ui::modal id="teleport-connect" maxWidth="lg" :title="'SSH Connect: '.$connectNode">
        <form wire:submit="confirmConnect" id="teleport-connect-form" class="space-y-4">
            <div class="rounded-lg border border-amber-200 bg-amber-50 dark:bg-amber-900/20 dark:border-amber-800/50 p-3 text-sm text-amber-800 dark:text-amber-200">
                <div class="flex gap-2">
                    <x-lucide-shield-alert class="size-5 shrink-0 mt-0.5" />
                    <div>
                        <p class="font-medium">Anda akan SSH ke <code class="font-mono">{{ $connectNode }}</code> sebagai user <code class="font-mono">{{ auth()->user()->username ?? auth()->user()->email }}</code> (login OS: <code class="font-mono">{{ $connectLogin }}</code>).</p>
                        <p class="mt-1 text-xs">Akses ini dicatat di audit log dengan IP, user agent, dan alasan akses. Atasan dapat melihat aktivitas ini.</p>
                    </div>
                </div>
            </div>

            <x-nawasara-ui::form.input label="Login user di node" wire:model="connectLogin"
                useError errorVariable="connectLogin" />
            <p class="text-xs text-gray-500 dark:text-neutral-400 -mt-2">Default: root. Override hanya kalau Teleport user kamu punya trait login lain di server target.</p>

            <div>
                <x-nawasara-ui::form.label>
                    Alasan akses <span class="text-red-500">*</span>
                </x-nawasara-ui::form.label>
                <textarea wire:model="connectReason" rows="3"
                    class="block w-full rounded-lg border-gray-200 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 text-sm focus:border-emerald-600 focus:ring-emerald-600"
                    placeholder="Contoh: Investigate disk full alarm di / partition setelah cron backup"></textarea>
                @error('connectReason')
                    <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>
                @enderror
                <p class="text-xs text-gray-500 dark:text-neutral-400 mt-1">Minimal 10 karakter. Spesifik supaya audit trail actionable.</p>
            </div>
        </form>

        <x-slot:footer>
            <x-nawasara-ui::button color="neutral" variant="outline" @click="$dispatch('close-modal', 'teleport-connect')">Batal</x-nawasara-ui::button>
            <x-nawasara-ui::button type="submit" form="teleport-connect-form" color="success">
                <x-slot:icon><x-lucide-terminal /></x-slot:icon>
                Connect
            </x-nawasara-ui::button>
        </x-slot:footer>
    </x-nawasara-ui::modal>

    {{-- Modal 2: Terminal session live xterm.js. Ini modal terbesar
         (hampir full-screen) supaya admin punya cukup viewport untuk
         actual terminal work. --}}
    <x-nawasara-ui::modal id="teleport-terminal" maxWidth="3xl"
        :title="$terminalNodeName ? 'Terminal: '.$terminalNodeName : 'Terminal'">
        <div wire:ignore class="space-y-3">
            <div id="teleport-terminal-status" class="text-xs font-mono text-gray-500 dark:text-neutral-400">
                Initializing...
            </div>
            <div id="teleport-terminal-container"
                class="bg-black rounded-lg overflow-hidden border border-gray-800"
                style="min-height: 480px; height: 60vh;">
                {{-- xterm.js akan attach ke div ini saat modal open --}}
            </div>
        </div>

        <x-slot:footer>
            <x-nawasara-ui::button color="neutral" variant="outline" @click="$dispatch('close-modal', 'teleport-terminal'); window.dispatchEvent(new CustomEvent('teleport-terminal-disconnect'))">
                Disconnect & Tutup
            </x-nawasara-ui::button>
        </x-slot:footer>
    </x-nawasara-ui::modal>

    {{-- xterm.js bridge — load dari CDN saat first render. --}}
    @once
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@xterm/xterm@5.5.0/css/xterm.css" />
        <script src="https://cdn.jsdelivr.net/npm/@xterm/xterm@5.5.0/lib/xterm.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/@xterm/addon-fit@0.10.0/lib/addon-fit.js"></script>
    @endonce

    <script>
        document.addEventListener('livewire:init', () => {
            let term = null;
            let fitAddon = null;
            let ws = null;
            let resizeObserver = null;

            const statusEl = () => document.getElementById('teleport-terminal-status');
            const containerEl = () => document.getElementById('teleport-terminal-container');

            const setStatus = (msg, cls = '') => {
                const el = statusEl();
                if (!el) return;
                el.textContent = msg;
                el.className = 'text-xs font-mono ' + (cls || 'text-gray-500 dark:text-neutral-400');
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
                if (term) {
                    try { term.dispose(); } catch (e) { /* ignore */ }
                    term = null;
                    fitAddon = null;
                }
            };

            // Listener: Livewire dispatch event saat ticket ready
            Livewire.on('teleport-terminal-open', (event) => {
                const payload = Array.isArray(event) ? event[0] : event;
                const wsUrl = payload?.url;
                const node = payload?.node || 'unknown';
                if (!wsUrl) return;

                // Show terminal modal first — Livewire close bridge yg lain
                window.dispatchEvent(new CustomEvent('open-modal', {
                    detail: { id: 'teleport-terminal', loading: false }
                }));

                // Wait next tick supaya modal DOM ready
                requestAnimationFrame(() => {
                    cleanup(); // safety: clean prev state kalau ada

                    const container = containerEl();
                    if (!container) {
                        setStatus('Error: terminal container not found in DOM', 'text-red-500');
                        return;
                    }

                    // Init xterm
                    term = new Terminal({
                        cursorBlink: true,
                        fontFamily: 'Menlo, Monaco, "Courier New", monospace',
                        fontSize: 13,
                        theme: { background: '#000000' },
                    });
                    fitAddon = new FitAddon.FitAddon();
                    term.loadAddon(fitAddon);
                    term.open(container);
                    fitAddon.fit();
                    term.focus();

                    setStatus(`Connecting to ${node}...`);

                    // Open WS
                    ws = new WebSocket(wsUrl);
                    ws.binaryType = 'arraybuffer';

                    ws.onopen = () => {
                        setStatus(`Connected to ${node}`, 'text-emerald-600 dark:text-emerald-400');

                        // Send initial resize so server PTY matches xterm geometry
                        const sendResize = () => {
                            if (!ws || ws.readyState !== WebSocket.OPEN) return;
                            ws.send(JSON.stringify({
                                type: 'resize',
                                cols: term.cols,
                                rows: term.rows,
                            }));
                        };
                        sendResize();

                        // Forward keystrokes ke server (binary frames)
                        term.onData((data) => {
                            if (!ws || ws.readyState !== WebSocket.OPEN) return;
                            ws.send(new TextEncoder().encode(data));
                        });

                        // Resize observer untuk auto-fit + send window-change
                        resizeObserver = new ResizeObserver(() => {
                            if (!fitAddon) return;
                            try { fitAddon.fit(); } catch (e) { /* ignore */ }
                            sendResize();
                        });
                        resizeObserver.observe(container);
                    };

                    ws.onmessage = (event) => {
                        // Server kirim binary stdout/stderr; xterm handle Uint8Array
                        if (event.data instanceof ArrayBuffer) {
                            term.write(new Uint8Array(event.data));
                        } else if (typeof event.data === 'string') {
                            // Bridge error messages atau notice (text frames)
                            term.write(event.data);
                        }
                    };

                    ws.onerror = () => {
                        setStatus(`Connection error to ${node}`, 'text-red-500');
                    };

                    ws.onclose = () => {
                        setStatus(`Disconnected from ${node}`, 'text-amber-600');
                        if (term) {
                            term.write('\r\n\x1b[33m[session closed]\x1b[0m\r\n');
                        }
                    };
                });
            });

            // Listener: cleanup saat user klik Tutup atau Esc close modal
            window.addEventListener('teleport-terminal-disconnect', () => {
                cleanup();
                setStatus('Disconnected.');
            });

            // Cleanup juga saat modal di-close lewat overlay click / Esc
            window.addEventListener('close-modal', (e) => {
                if (e?.detail === 'teleport-terminal' || e?.detail?.id === 'teleport-terminal') {
                    cleanup();
                }
            });
        });
    </script>
</div>
