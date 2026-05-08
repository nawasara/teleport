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
        <x-nawasara-ui::table
            :headers="['Hostname', 'Address', 'Labels', 'Version', 'Last Heartbeat', 'Status']">
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
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
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
</div>
