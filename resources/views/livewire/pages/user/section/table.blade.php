<div>
    <x-nawasara-ui::page-header
        title="Teleport Users"
        description="User accounts di Teleport (local + auto-provisioned dari SSO). Phase 1 read-only."
        :count="count($this->users).' user'">
        <x-nawasara-ui::icon-button
            icon="refresh-cw"
            tooltip="Refresh dari Teleport"
            wire:click="refresh"
            loadingTarget="refresh" />
    </x-nawasara-ui::page-header>

    <div class="space-y-2 mb-4">
        <div class="flex flex-col md:flex-row md:flex-nowrap md:items-center gap-2">
            <x-nawasara-ui::search-input model="search" placeholder="Cari username atau role..." />
        </div>

        @if ($search)
            <div class="flex flex-wrap items-center gap-2">
                <x-nawasara-ui::filter-chip label="Cari: {{ $search }}" model="search" />
            </div>
        @endif
    </div>

    <x-nawasara-ui::table
        :headers="['Username', 'Roles', 'Logins', 'Created At']">
        <x-slot:table>
            @forelse ($this->users as $user)
                <tr wire:key="user-{{ $user['name'] ?? '' }}">
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-800 dark:text-neutral-200 font-mono">
                        {{ $user['name'] ?? '-' }}
                    </td>
                    <td class="px-6 py-4 text-sm">
                        <div class="flex flex-wrap gap-1">
                            @foreach ($user['roles'] ?? [] as $role)
                                <x-nawasara-ui::badge color="info">{{ $role }}</x-nawasara-ui::badge>
                            @endforeach
                        </div>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-700 dark:text-neutral-300 font-mono">
                        @php $logins = $user['traits']['logins'] ?? null; @endphp
                        @if (! empty($logins) && is_array($logins))
                            {{ implode(', ', array_filter($logins)) ?: '-' }}
                        @else
                            <span class="text-gray-400">-</span>
                        @endif
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-neutral-400">
                        @php $ca = $user['created_at'] ?? null; @endphp
                        @if ($ca)
                            <span title="{{ $ca }}">{{ \Carbon\Carbon::parse($ca)->format('d M Y') }}</span>
                        @else
                            -
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4">
                        @if ($search)
                            <x-nawasara-ui::empty-state
                                icon="lucide-search-x"
                                title="Tidak ada user yang cocok"
                                description="Coba ubah search keyword."
                                variant="filter"
                                inline />
                        @else
                            <x-nawasara-ui::empty-state
                                icon="lucide-users"
                                title="Belum bisa ambil data user"
                                description="Cek koneksi sidecar ke Teleport, atau pastikan nawasara-admin punya role editor di Teleport."
                                inline />
                        @endif
                    </td>
                </tr>
            @endforelse
        </x-slot:table>
    </x-nawasara-ui::table>
</div>
