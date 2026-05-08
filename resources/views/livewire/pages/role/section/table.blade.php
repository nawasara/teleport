<div>
    <x-nawasara-ui::page-header
        title="Teleport Roles"
        description="RBAC roles di Teleport dengan allow/deny conditions. Klik Detail untuk lihat full spec."
        :count="count($this->roles).' role'">
        <x-nawasara-ui::icon-button
            icon="refresh-cw"
            tooltip="Refresh dari Teleport"
            wire:click="refresh"
            loadingTarget="refresh" />
    </x-nawasara-ui::page-header>

    <div class="space-y-2 mb-4">
        <div class="flex flex-col md:flex-row md:flex-nowrap md:items-center gap-2">
            <x-nawasara-ui::search-input model="search" placeholder="Cari role name..." />
        </div>

        @if ($search)
            <div class="flex flex-wrap items-center gap-2">
                <x-nawasara-ui::filter-chip label="Cari: {{ $search }}" model="search" />
            </div>
        @endif
    </div>

    <x-nawasara-ui::table stickyLast
        :headers="['Role Name', 'Allow Logins', 'Allow Node Labels', '']">
        <x-slot:table>
            @forelse ($this->roles as $role)
                @php
                    $allowLogins = $role['allow']['logins'] ?? null;
                    $allowNodeLabels = $role['allow']['node_labels'] ?? null;
                @endphp
                <tr wire:key="role-{{ $role['name'] ?? '' }}">
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-800 dark:text-neutral-200 font-mono">
                        {{ $role['name'] ?? '-' }}
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-700 dark:text-neutral-300 font-mono">
                        @if (! empty($allowLogins) && is_array($allowLogins))
                            <div class="flex flex-wrap gap-1 max-w-md">
                                @foreach (array_slice($allowLogins, 0, 5) as $login)
                                    <span class="px-1.5 py-0.5 rounded text-[10px] bg-gray-100 dark:bg-neutral-700">{{ $login }}</span>
                                @endforeach
                                @if (count($allowLogins) > 5)
                                    <span class="text-xs text-gray-500">+{{ count($allowLogins) - 5 }}</span>
                                @endif
                            </div>
                        @else
                            <span class="text-gray-400">-</span>
                        @endif
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-700 dark:text-neutral-300 font-mono">
                        @if (! empty($allowNodeLabels) && is_array($allowNodeLabels))
                            <div class="flex flex-wrap gap-1 max-w-md">
                                @foreach (array_slice(array_keys($allowNodeLabels), 0, 3) as $key)
                                    <span class="px-1.5 py-0.5 rounded text-[10px] bg-gray-100 dark:bg-neutral-700">
                                        {{ $key }}={{ is_array($allowNodeLabels[$key]) ? '['.count($allowNodeLabels[$key]).']' : $allowNodeLabels[$key] }}
                                    </span>
                                @endforeach
                                @if (count($allowNodeLabels) > 3)
                                    <span class="text-xs text-gray-500">+{{ count($allowNodeLabels) - 3 }}</span>
                                @endif
                            </div>
                        @else
                            <span class="text-gray-400">-</span>
                        @endif
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right">
                        <button type="button" wire:click="openDetail('{{ $role['name'] ?? '' }}')"
                            class="text-emerald-700 dark:text-emerald-400 hover:underline text-xs font-medium">
                            Detail
                        </button>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4">
                        @if ($search)
                            <x-nawasara-ui::empty-state
                                icon="lucide-search-x"
                                title="Tidak ada role yang cocok"
                                description="Coba ubah search keyword."
                                variant="filter"
                                inline />
                        @else
                            <x-nawasara-ui::empty-state
                                icon="lucide-shield-check"
                                title="Belum bisa ambil data role"
                                description="Cek koneksi sidecar atau permission user nawasara-admin di Teleport."
                                inline />
                        @endif
                    </td>
                </tr>
            @endforelse
        </x-slot:table>
    </x-nawasara-ui::table>

    {{-- Detail modal — full role spec sebagai raw JSON yang readable. --}}
    <x-nawasara-ui::modal id="teleport-role-detail" maxWidth="3xl" :title="'Role: '.($this->detail['name'] ?? '-')">
        @if ($this->detail)
            @php $d = $this->detail; @endphp
            <div class="space-y-4">
                <div>
                    <h4 class="text-sm font-semibold text-emerald-700 dark:text-emerald-400 mb-2">
                        <x-lucide-circle-check class="size-4 inline" /> Allow conditions
                    </h4>
                    <pre class="text-xs font-mono bg-gray-50 dark:bg-neutral-900 p-3 rounded-lg overflow-x-auto whitespace-pre-wrap break-all">{{ json_encode($d['allow'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                </div>

                <div>
                    <h4 class="text-sm font-semibold text-rose-700 dark:text-rose-400 mb-2">
                        <x-lucide-circle-x class="size-4 inline" /> Deny conditions
                    </h4>
                    <pre class="text-xs font-mono bg-gray-50 dark:bg-neutral-900 p-3 rounded-lg overflow-x-auto whitespace-pre-wrap break-all">{{ json_encode($d['deny'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                </div>
            </div>
        @endif

        <x-slot:footer>
            <x-nawasara-ui::button color="neutral" variant="outline" wire:click="closeDetail">Tutup</x-nawasara-ui::button>
        </x-slot:footer>
    </x-nawasara-ui::modal>
</div>
