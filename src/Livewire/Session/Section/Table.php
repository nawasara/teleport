<?php

namespace Nawasara\Teleport\Livewire\Session\Section;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Nawasara\Teleport\Models\TeleportSession;
use Nawasara\Ui\Livewire\Concerns\HasArrayFilters;
use Nawasara\Ui\Livewire\Concerns\HasExport;
use Nawasara\Ui\Livewire\Concerns\HasTimeWindow;

/**
 * Audit log untuk admin Teleport SSH session launches (Phase 4).
 *
 * Single-source — read langsung dari nawasara_teleport_sessions (vs.
 * ImpersonationLog yang UNION webmail+cpanel+teleport untuk view gabungan).
 * Sengaja terpisah supaya admin yang fokus ops/SSH bisa lihat detail
 * teleport-only (node, login, ticket_id, duration) tanpa noise dari
 * webmail/cpanel events.
 *
 * Status enum: issued | failed (model TeleportSession; no rejected karena
 * Phase 4 belum ada flow approval, semua attempt langsung di-execute).
 *
 * Stats di hero cards: Total / Issued / Failed / Avg Duration. Avg duration
 * cuma counted dari rows yang punya duration_seconds (Phase 4 belum populate
 * — kolom exists tapi never set; tunggu webhook sidecar di Phase 5).
 */
class Table extends Component
{
    use HasArrayFilters;
    use HasExport;
    use HasTimeWindow;
    use WithPagination;

    #[Url]
    public string $search = '';

    /**
     * Status filter as multi-select array (['issued', 'failed']).
     * Empty array == semua status.
     *
     * @var array<int, string>
     */
    #[Url]
    public $statusFilter = [];

    /**
     * Filter by admin (acted_by_user_id) — single user dropdown. Empty
     * string == semua admin.
     */
    #[Url]
    public string $actorFilter = '';

    /**
     * Filter by target node hostname — single-select dropdown,
     * options dari distinct node values di table.
     */
    #[Url]
    public string $nodeFilter = '';

    public int $perPage = 25;

    public ?int $detailKey = null;

    /**
     * Filter properties yang accept scalar dari URL legacy bookmarks.
     */
    protected function arrayFilters(): array
    {
        return ['statusFilter'];
    }

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedStatusFilter(): void { $this->resetPage(); }
    public function updatedActorFilter(): void { $this->resetPage(); }
    public function updatedNodeFilter(): void { $this->resetPage(); }

    /**
     * Build base query dengan semua filter common (time window, status,
     * actor, node, search) applied. Reuse di items(), summary(), export().
     */
    protected function baseQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $q = TeleportSession::query();

        // Time window dari HasTimeWindow trait — default 7d, bisa override
        // via UI selector ke 1d/30d/custom range.
        $q->tap(fn ($qq) => $this->applyTimeWindow($qq, 'created_at'));

        if (! empty($this->statusFilter)) {
            $q->whereIn('status', $this->statusFilter);
        }

        if ($this->actorFilter !== '') {
            $q->where('acted_by_user_id', (int) $this->actorFilter);
        }

        if ($this->nodeFilter !== '') {
            $q->where('node', $this->nodeFilter);
        }

        if ($this->search !== '') {
            $needle = '%'.$this->search.'%';
            $q->where(function ($q) use ($needle) {
                $q->where('node', 'like', $needle)
                    ->orWhere('target_user', 'like', $needle)
                    ->orWhere('login', 'like', $needle)
                    ->orWhere('reason', 'like', $needle)
                    ->orWhere('ip', 'like', $needle)
                    ->orWhere('ticket_id', 'like', $needle);
            });
        }

        return $q;
    }

    /**
     * Summary aggregates untuk hero stat cards. Strip statusFilter (cards
     * tetap show all-status counts even saat user filter ke status tertentu)
     * supaya bisa berfungsi sebagai clickable filter toggles.
     */
    #[Computed]
    public function summary(): array
    {
        $stashed = $this->statusFilter;
        $this->statusFilter = [];
        try {
            $base = $this->baseQuery();
            $total = (clone $base)->count();
            $issued = (clone $base)->where('status', TeleportSession::STATUS_ISSUED)->count();
            $failed = (clone $base)->where('status', TeleportSession::STATUS_FAILED)->count();

            // Avg duration — hanya rows yang sudah punya duration_seconds
            // (filled by sidecar webhook on ws-close, Phase 5 work). Sekarang
            // mostly null → avg = null → display "-" di card.
            $avgDuration = (clone $base)
                ->whereNotNull('duration_seconds')
                ->avg('duration_seconds');
        } finally {
            $this->statusFilter = $stashed;
        }

        return [
            'total' => $total,
            'issued' => $issued,
            'failed' => $failed,
            'avg_duration' => $avgDuration ? (int) round($avgDuration) : null,
        ];
    }

    /**
     * Toggle status filter dari stat card click. Klik aktif lagi = reset.
     */
    public function toggleStatusFilter(string $status): void
    {
        if ($this->statusFilter === [$status]) {
            $this->statusFilter = [];
        } else {
            $this->statusFilter = [$status];
        }
        $this->resetPage();
    }

    public function clearStatusFilter(): void
    {
        $this->statusFilter = [];
        $this->resetPage();
    }

    #[Computed]
    public function items()
    {
        return $this->baseQuery()
            ->with('actor')
            ->orderBy('created_at', 'desc')
            ->paginate($this->perPage);
    }

    /**
     * Distinct admin yang pernah trigger SSH session — dropdown opsi.
     * Pre-resolve user names supaya dropdown gampang scan.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function actorOptions(): array
    {
        $ids = TeleportSession::query()
            ->whereNotNull('acted_by_user_id')
            ->distinct()
            ->pluck('acted_by_user_id')
            ->all();

        if (empty($ids)) return [];

        return User::whereIn('id', $ids)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Distinct nodes yang pernah di-akses — dropdown opsi.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function nodeOptions(): array
    {
        return TeleportSession::query()
            ->whereNotNull('node')
            ->distinct()
            ->orderBy('node')
            ->pluck('node')
            ->all();
    }

    /**
     * Single row detail untuk modal, di-resolve dari $detailKey supaya
     * tidak perlu serialize whole row di state.
     */
    #[Computed]
    public function detail(): ?TeleportSession
    {
        if (! $this->detailKey) {
            return null;
        }

        return TeleportSession::with('actor')->find($this->detailKey);
    }

    public function openDetail(int $id): void
    {
        $this->detailKey = $id;
        $this->dispatch('modal-open:teleport-session-detail');
    }

    public function closeDetail(): void
    {
        $this->dispatch('modal-close:teleport-session-detail');
        $this->detailKey = null;
    }

    /**
     * Export filename base — timestamp + extension appended by HasExport.
     */
    protected function exportFilename(): string
    {
        return 'teleport-sessions';
    }

    /**
     * Export FULL filtered dataset (capped 10k baris) sesuai filter aktif.
     * Audit reviewer butuh raw context (IP, UA, ticket_id, error).
     */
    protected function exportData(): iterable
    {
        $rows = $this->baseQuery()
            ->with('actor')
            ->orderBy('created_at', 'desc')
            ->limit(10000)
            ->get();

        return $rows->map(fn (TeleportSession $r) => [
            'ID' => $r->id,
            'Waktu' => (string) $r->created_at,
            'Admin (Actor)' => $r->actor?->name ?? '-',
            // Teleport Identity = username yang di-mint di SSH cert (= Keycloak
            // username admin yang trigger Connect, biasanya sama dengan Admin/Actor
            // di kolom sebelahnya, tapi di-keep terpisah untuk traceability cert).
            'Teleport Identity' => $r->target_user,
            'Node' => $r->node,
            'OS Login' => $r->login,
            'Status' => $r->status,
            'Alasan' => $r->reason ?? '-',
            'Duration (s)' => $r->duration_seconds ?? '-',
            'Ticket ID' => $r->ticket_id ?? '-',
            'Error' => $r->error ?? '-',
            'IP' => $r->ip ?? '-',
            'User Agent' => $r->user_agent ?? '-',
        ]);
    }

    public function render()
    {
        return view('nawasara-teleport::livewire.pages.session.section.table');
    }
}
