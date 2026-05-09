<div>
    @php
        $statusOptions = ['issued' => 'Issued', 'failed' => 'Failed'];

        $fmtDuration = function (?int $sec): string {
            if ($sec === null) return '-';
            if ($sec < 60) return $sec.'s';
            $m = intdiv($sec, 60);
            $s = $sec % 60;
            return $m.'m '.($s ? $s.'s' : '').'';
        };
    @endphp

    {{-- Page header — title + count + time-window selector. Read-only audit
         data, no primary CTA. --}}
    <x-nawasara-ui::page-header
        title="SSH Sessions"
        description="Riwayat akses admin ke node SSH lewat Teleport. Setiap launch dicatat dengan alasan, OS user target, node, IP, dan ticket ID untuk forensik."
        :count="$this->items->total().' event'">
        <x-nawasara-ui::time-window :window="$window" :from="$from" :to="$to" />
    </x-nawasara-ui::page-header>

    {{-- Hero stats — Total / Issued / Failed clickable filter cards
         + Avg Duration informational (Phase 5: butuh sidecar webhook
         populate duration_seconds dulu sebelum kartu ini punya data). --}}
    @php $summary = $this->summary; @endphp
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <x-nawasara-ui::stat-card
            label="Total Session"
            :value="number_format($summary['total'])"
            icon="lucide-terminal"
            color="primary"
            :active="empty($statusFilter)"
            accent
            wire:click="clearStatusFilter" />

        <x-nawasara-ui::stat-card
            label="Issued"
            :value="number_format($summary['issued'])"
            icon="lucide-circle-check"
            color="success"
            :active="$statusFilter === ['issued']"
            accent
            wire:click="toggleStatusFilter('issued')" />

        <x-nawasara-ui::stat-card
            label="Failed"
            :value="number_format($summary['failed'])"
            icon="lucide-circle-x"
            color="danger"
            :active="$statusFilter === ['failed']"
            accent
            wire:click="toggleStatusFilter('failed')" />

        <x-nawasara-ui::stat-card
            label="Avg Duration"
            :value="$summary['avg_duration'] !== null ? $fmtDuration($summary['avg_duration']) : '-'"
            icon="lucide-clock"
            color="neutral"
            accent />
    </div>

    {{-- Toolbar — Filter (Status) + Node dropdown + Actor dropdown + search + export. --}}
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

                {{-- Node selector — single dropdown supaya admin bisa drill-in
                     ke history specific node ("siapa aja yang pernah masuk
                     Server-Mail"). --}}
                @if (! empty($this->nodeOptions))
                    <select wire:model.live="nodeFilter"
                        class="h-10 rounded-lg border-gray-200 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                        <option value="">Semua Node</option>
                        @foreach ($this->nodeOptions as $node)
                            <option value="{{ $node }}">{{ $node }}</option>
                        @endforeach
                    </select>
                @endif

                {{-- Actor (admin) selector. --}}
                @if (! empty($this->actorOptions))
                    <select wire:model.live="actorFilter"
                        class="h-10 rounded-lg border-gray-200 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                        <option value="">Semua Admin</option>
                        @foreach ($this->actorOptions as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                @endif
            </div>

            <x-nawasara-ui::search-input model="search" placeholder="Cari node, identity, login, alasan, IP, atau ticket..." />

            <div class="flex items-center gap-2 shrink-0">
                <x-nawasara-ui::export-button
                    action="export"
                    tooltip="Ekspor SSH session log (max 10rb baris, sesuai filter)" />
            </div>
        </div>

        <div wire:ignore data-filter-chips></div>

        {{-- Manual chips untuk filter di luar filter-panel: search + node + actor. --}}
        @if ($search || $nodeFilter || $actorFilter)
            <div class="flex flex-wrap items-center gap-2">
                @if ($search)
                    <x-nawasara-ui::filter-chip label="Cari: {{ $search }}" model="search" />
                @endif
                @if ($nodeFilter)
                    <x-nawasara-ui::filter-chip label="Node: {{ $nodeFilter }}" model="nodeFilter" />
                @endif
                @if ($actorFilter && isset($this->actorOptions[$actorFilter]))
                    <x-nawasara-ui::filter-chip
                        label="Admin: {{ $this->actorOptions[$actorFilter] }}"
                        model="actorFilter" />
                @endif
            </div>
        @endif
    </div>

    {{-- Table — read-only audit. Kolom: Waktu, Admin (acted_by_user_id),
         Teleport Identity@node (target_user yang di-mint di cert =
         identity admin di Teleport), OS Login (root/ubuntu/dst), Status,
         Duration, Alasan, IP, Detail.

         "Target" header sengaja di-keep generic karena sempit space —
         tooltip header bisa di-add nanti kalau perlu. Detail modal
         pakai label eksplisit "Teleport Identity". --}}
    <x-nawasara-ui::table stickyLast
        :headers="['Waktu', 'Admin', 'Identity@Node', 'OS Login', 'Status', 'Durasi', 'Alasan', 'IP', '']">
        <x-slot:table>
            @forelse ($this->items as $row)
                <tr wire:key="teleport-session-{{ $row->id }}">
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-800 dark:text-neutral-200">
                        {{ \Carbon\Carbon::parse($row->created_at)->format('d M Y H:i:s') }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-800 dark:text-neutral-200">
                        {{ $row->actor?->name ?? '#'.$row->acted_by_user_id }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-700 dark:text-neutral-300">
                        <span>{{ $row->target_user }}</span><span class="text-gray-400 dark:text-neutral-500">@</span><span class="text-emerald-700 dark:text-emerald-400">{{ $row->node }}</span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-600 dark:text-neutral-400">
                        {{ $row->login }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                        @if ($row->status === 'issued')
                            <x-nawasara-ui::badge color="success" dot>Issued</x-nawasara-ui::badge>
                        @elseif ($row->status === 'failed')
                            <x-nawasara-ui::badge color="danger" dot>Failed</x-nawasara-ui::badge>
                        @else
                            <x-nawasara-ui::badge color="neutral" dot>{{ ucfirst($row->status) }}</x-nawasara-ui::badge>
                        @endif
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700 dark:text-neutral-300">
                        {{ $fmtDuration($row->duration_seconds) }}
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-600 dark:text-neutral-400 max-w-xs truncate" title="{{ $row->reason }}">
                        {{ \Illuminate\Support\Str::limit($row->reason ?? '-', 50) }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-500 dark:text-neutral-400">
                        {{ $row->ip ?? '-' }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right">
                        <button type="button" wire:click="openDetail({{ $row->id }})"
                            class="text-emerald-700 dark:text-emerald-400 hover:underline text-xs font-medium">
                            Detail
                        </button>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9">
                        @if ($search || ! empty($statusFilter) || $nodeFilter || $actorFilter || $window !== '7d' || $from || $to)
                            <x-nawasara-ui::empty-state
                                icon="lucide-search-x"
                                title="Tidak ada session yang cocok"
                                description="Coba ubah periode/filter atau hapus search keyword."
                                variant="filter"
                                inline />
                        @else
                            <x-nawasara-ui::empty-state
                                icon="lucide-terminal"
                                title="Belum ada SSH session 7 hari terakhir"
                                description="Aktivitas admin SSH ke node Teleport akan tercatat di sini setelah klik Connect dari halaman Nodes."
                                inline />
                        @endif
                    </td>
                </tr>
            @endforelse
        </x-slot:table>

        <x-slot:footer>
            {{ $this->items->links() }}
        </x-slot:footer>
    </x-nawasara-ui::table>

    {{-- Detail Modal — full context: alasan lengkap, ticket UUID, error
         (kalau status=failed), UA. --}}
    <x-nawasara-ui::modal id="teleport-session-detail" maxWidth="2xl" title="Detail SSH Session">
        @if ($this->detail)
            @php $d = $this->detail; @endphp
            <div class="space-y-4 text-sm">
                {{-- Grid label/value pairs.
                     Konvensi color (mirror style "Alasan akses" di bawah):
                       Label: text-gray-500 / dark:text-neutral-400 (muted)
                       Value: text-gray-900 / dark:text-neutral-100 (high contrast)
                     Override khusus: Node accent emerald supaya ketauan
                     itu identifier utama dalam konteks audit. --}}
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <span class="text-gray-500 dark:text-neutral-400">Status:</span>
                        <span class="font-medium">
                            @if ($d->status === 'issued')
                                <x-nawasara-ui::badge color="success">Issued</x-nawasara-ui::badge>
                            @elseif ($d->status === 'failed')
                                <x-nawasara-ui::badge color="danger">Failed</x-nawasara-ui::badge>
                            @else
                                <x-nawasara-ui::badge color="neutral">{{ ucfirst($d->status) }}</x-nawasara-ui::badge>
                            @endif
                        </span>
                    </div>
                    <div>
                        <span class="text-gray-500 dark:text-neutral-400">Waktu:</span>
                        <span class="font-medium text-gray-900 dark:text-neutral-100">{{ \Carbon\Carbon::parse($d->created_at)->format('d M Y H:i:s') }}</span>
                    </div>
                    <div>
                        <span class="text-gray-500 dark:text-neutral-400">Admin (Actor):</span>
                        <span class="font-medium text-gray-900 dark:text-neutral-100">{{ $d->actor?->name ?? '#'.($d->acted_by_user_id ?? '?') }}</span>
                    </div>
                    <div>
                        <span class="text-gray-500 dark:text-neutral-400">Durasi:</span>
                        <span class="font-medium text-gray-900 dark:text-neutral-100">{{ $fmtDuration($d->duration_seconds) }}</span>
                    </div>
                    <div>
                        <span class="text-gray-500 dark:text-neutral-400" title="Username yang di-mint di Teleport SSH cert. Untuk admin impersonate cert biasanya = Keycloak username admin sendiri.">Teleport Identity:</span>
                        <span class="font-mono font-medium text-gray-900 dark:text-neutral-100">{{ $d->target_user }}</span>
                    </div>
                    <div>
                        <span class="text-gray-500 dark:text-neutral-400">Node:</span>
                        <span class="font-mono font-medium text-emerald-700 dark:text-emerald-400">{{ $d->node }}</span>
                    </div>
                    <div>
                        <span class="text-gray-500 dark:text-neutral-400" title="OS user (Linux) yang di-login di node target. Mis. root, ubuntu, ec2-user.">OS Login:</span>
                        <span class="font-mono font-medium text-gray-900 dark:text-neutral-100">{{ $d->login }}</span>
                    </div>
                    <div>
                        <span class="text-gray-500 dark:text-neutral-400">IP:</span>
                        <span class="font-mono font-medium text-gray-900 dark:text-neutral-100">{{ $d->ip ?? '-' }}</span>
                    </div>
                    @if ($d->ticket_id)
                        <div class="col-span-2">
                            <span class="text-gray-500 dark:text-neutral-400">Ticket ID:</span>
                            <span class="font-mono text-xs text-gray-700 dark:text-neutral-300">{{ $d->ticket_id }}</span>
                        </div>
                    @endif
                </div>

                @if ($d->reason)
                    <div class="border-t border-gray-200 dark:border-neutral-700 pt-4">
                        <p class="text-gray-500 dark:text-neutral-400 mb-1">Alasan akses:</p>
                        <p class="text-gray-800 dark:text-neutral-200 whitespace-pre-wrap">{{ $d->reason }}</p>
                    </div>
                @endif

                @if ($d->error)
                    <div class="border-t border-gray-200 dark:border-neutral-700 pt-4">
                        <p class="text-gray-500 dark:text-neutral-400 mb-1">Error:</p>
                        <p class="text-red-600 dark:text-red-400 font-mono text-xs whitespace-pre-wrap break-all">{{ $d->error }}</p>
                    </div>
                @endif

                @if ($d->user_agent)
                    <div class="border-t border-gray-200 dark:border-neutral-700 pt-4">
                        <p class="text-gray-500 dark:text-neutral-400 mb-1">User Agent:</p>
                        <p class="font-mono text-xs text-gray-700 dark:text-neutral-300 break-all">{{ $d->user_agent }}</p>
                    </div>
                @endif
            </div>
        @endif

        <x-slot:footer>
            <x-nawasara-ui::button color="neutral" variant="outline" wire:click="closeDetail">Tutup</x-nawasara-ui::button>
        </x-slot:footer>
    </x-nawasara-ui::modal>
</div>
