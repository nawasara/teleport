<?php

namespace Nawasara\Teleport\Livewire\Node\Section;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Nawasara\Teleport\Services\TeleportClient;
use Nawasara\Ui\Livewire\Concerns\HasBrowserToast;

/**
 * Read-only table untuk SSH nodes registered di Teleport. Data di-fetch
 * fresh dari sidecar (yang cache 60s server-side via TeleportClient).
 *
 * Phase 1 = list saja. Tombol [Connect] (browser SSH terminal) menyusul
 * di Phase 4+. Untuk sekarang user bisa lihat inventory + verify
 * Teleport-side state match dengan expectation.
 */
class Table extends Component
{
    use HasBrowserToast;

    #[Url]
    public string $search = '';

    /**
     * Filter status online/offline. Empty = both.
     *
     * @var array<int, string>
     */
    #[Url]
    public array $statusFilter = [];

    public function updatedSearch(): void
    {
        // No pagination di Phase 1 (Teleport cluster jarang >100 nodes,
        // cukup di-render in-memory). Reset bukan urgent.
    }

    /**
     * Raw nodes array dari sidecar. Di-cache di service layer (60s),
     * jadi method ini cheap call.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function allNodes(): array
    {
        return app(TeleportClient::class)->listNodes();
    }

    /**
     * Filtered + sorted nodes untuk display. Filter di PHP karena
     * dataset kecil + sidecar tidak expose query param (yet).
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function nodes(): array
    {
        $rows = $this->allNodes;

        if ($this->search !== '') {
            $needle = mb_strtolower($this->search);
            $rows = array_filter($rows, function ($n) use ($needle) {
                $haystack = mb_strtolower(
                    ($n['hostname'] ?? '').' '.
                    ($n['name'] ?? '').' '.
                    ($n['addr'] ?? '').' '.
                    implode(' ', $n['labels'] ?? [])
                );
                return str_contains($haystack, $needle);
            });
        }

        if (! empty($this->statusFilter)) {
            $wantOnline = in_array('online', $this->statusFilter, true);
            $wantOffline = in_array('offline', $this->statusFilter, true);
            // Both selected = no-op (every row matches)
            if ($wantOnline xor $wantOffline) {
                $rows = array_filter($rows, fn ($n) => ($wantOnline ? ($n['online'] ?? false) : ! ($n['online'] ?? false)));
            }
        }

        // Sort: online first, then by hostname
        usort($rows, function ($a, $b) {
            $cmp = (int) ($b['online'] ?? false) <=> (int) ($a['online'] ?? false);
            if ($cmp !== 0) return $cmp;
            return strcmp($a['hostname'] ?? '', $b['hostname'] ?? '');
        });

        return array_values($rows);
    }

    /**
     * Health stat untuk sub-header.
     *
     * @return array{cluster_name: ?string, server_time: ?string, status: ?string, error: ?string}
     */
    #[Computed]
    public function health(): array
    {
        $h = app(TeleportClient::class)->health();
        return [
            'cluster_name' => $h['cluster_name'] ?? null,
            'server_time' => $h['server_time'] ?? null,
            'status' => $h['status'] ?? null,
            'error' => $h['error'] ?? null,
        ];
    }

    /**
     * Hero stats untuk top page.
     *
     * @return array{total: int, online: int, offline: int}
     */
    #[Computed]
    public function summary(): array
    {
        $total = count($this->allNodes);
        $online = count(array_filter($this->allNodes, fn ($n) => $n['online'] ?? false));
        return [
            'total' => $total,
            'online' => $online,
            'offline' => $total - $online,
        ];
    }

    /**
     * Force refresh — flush cache server-side, reload component.
     */
    public function refresh(): void
    {
        app(TeleportClient::class)->flushCache('nodes');
        unset($this->allNodes, $this->nodes, $this->summary, $this->health);
        $this->toastSuccess('Data nodes di-refresh dari Teleport.');
    }

    public function render()
    {
        return view('nawasara-teleport::livewire.pages.node.section.table');
    }
}
