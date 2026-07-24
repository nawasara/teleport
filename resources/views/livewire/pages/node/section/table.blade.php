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
                                         (toh hit-nya bakal fail di sidecar).

                                         wire:target di-set EKSPLISIT dengan argumen node supaya
                                         cuma tombol node INI yang loading/disabled saat diklik —
                                         bukan semua tombol Connect. Tanpa argumen, auto-loading
                                         button component pakai target "openConnect" (nama action
                                         saja) → semua baris ter-trigger bersamaan. --}}
                                    @php $nodeHost = $node['hostname'] ?? ''; @endphp
                                    <x-nawasara-ui::button
                                        size="sm"
                                        color="success"
                                        wire:click="openConnect('{{ addslashes($nodeHost) }}')"
                                        wire:target="openConnect('{{ addslashes($nodeHost) }}')"
                                        wire:loading.attr="disabled">
                                        <x-slot:icon>
                                            <x-lucide-terminal class="size-4" wire:loading.remove wire:target="openConnect('{{ addslashes($nodeHost) }}')" />
                                            <x-lucide-loader-circle class="size-4 animate-spin" wire:loading wire:target="openConnect('{{ addslashes($nodeHost) }}')" />
                                        </x-slot:icon>
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

            <div>
                <x-nawasara-ui::form.input label="Login User Di Node" wire:model="connectLogin"
                    useError errorVariable="connectLogin" />
                <p class="text-xs text-gray-500 dark:text-neutral-400 mt-1.5">Default: root. Override hanya kalau Teleport user kamu punya trait login lain di server target.</p>
            </div>

            <div>
                {{-- Label dipisah supaya bisa tampilkan bintang wajib —
                     form.textarea meneruskan `required` sebagai atribut HTML
                     saja, tidak render tanda *. --}}
                <x-nawasara-ui::form.label class="mb-1">
                    Alasan Akses <span class="text-red-500">*</span>
                </x-nawasara-ui::form.label>
                <x-nawasara-ui::form.textarea
                    wire:model="connectReason"
                    :rows="3"
                    required
                    placeholder="Contoh: Investigate disk full alarm di / partition setelah cron backup"
                    hint="Minimal 10 karakter. Spesifik supaya audit trail actionable." />
                @error('connectReason')
                    <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>
                @enderror
            </div>
        </form>

        <x-slot:footer>
            <x-nawasara-ui::button color="neutral" variant="outline" @click="$dispatch('close-modal', 'teleport-connect')">Batal</x-nawasara-ui::button>
            {{-- Submit button: SYNC click handler pre-open about:blank di tab
                 baru sebelum form submit. Reference disimpan di window scope
                 supaya JS listener (di-script bawah) bisa update URL tab
                 setelah Livewire response balik dengan terminal page URL.
                 Tidak pakai noopener,noreferrer di pre-open karena akan bikin
                 window.open() return null (per HTML spec). Defense in depth:
                 null out opener setelah URL update di JS listener. --}}
            <x-nawasara-ui::button
                type="submit"
                form="teleport-connect-form"
                color="success"
                wire:target="confirmConnect"
                wire:loading.attr="disabled"
                onclick="window.__nawasaraTeleportTerminalTab = window.open('about:blank', '_blank')">
                <x-slot:icon>
                    <x-lucide-terminal wire:loading.remove wire:target="confirmConnect" />
                    <x-lucide-loader-circle class="animate-spin" wire:loading wire:target="confirmConnect" />
                </x-slot:icon>
                <span wire:loading.remove wire:target="confirmConnect">Connect</span>
                <span wire:loading wire:target="confirmConnect">Menyambungkan…</span>
            </x-nawasara-ui::button>
        </x-slot:footer>
    </x-nawasara-ui::modal>

    {{-- JS bridge: open SSH terminal di tab baru.

         Pattern popup-blocker safe (sama dengan webmail/cpanel launch-as):
           1. User klik [Connect] di footer modal — submit button onclick
              pre-open about:blank (SYNC click context, allowed by browser).
           2. Tab reference disimpan di window.__nawasaraTeleportTerminalTab
           3. Form submit → Livewire confirmConnect mint ticket di sidecar
              + stash session info ke cache via Cache::put().
           4. Livewire response dispatch event `teleport-terminal-open`
              dengan { url: route('...terminal.show', ['ticket' => $id]) }.
           5. JS listener update tab.location.href ke URL terminal page.
           6. Terminal page (TerminalController::show) fetch session info
              dari cache, render fullscreen xterm.js + ws connect.

         Kalau pre-open tab gagal (browser blok meskipun sync click) ATAU
         user sudah close tab pre-opened → fallback window.open. --}}
    <script>
        document.addEventListener('livewire:init', () => {
            Livewire.on('teleport-terminal-open', (event) => {
                const payload = Array.isArray(event) ? event[0] : event;
                const url = payload?.url;
                if (!url) return;

                const tab = window.__nawasaraTeleportTerminalTab;
                if (tab && !tab.closed) {
                    tab.location.href = url;
                    // Defense in depth — null out opener supaya target page
                    // tidak bisa manipulate window.opener.location.
                    try { tab.opener = null; } catch (e) { /* cross-origin */ }
                } else {
                    // Fallback: tab pre-opened gagal/closed. Pakai noopener
                    // di sini boleh karena tidak butuh reference balik.
                    window.open(url, '_blank', 'noopener,noreferrer');
                }
                window.__nawasaraTeleportTerminalTab = null;
            });
        });
    </script>
</div>
