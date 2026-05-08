<?php

namespace Nawasara\Teleport\Livewire\Node\Section;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Nawasara\Teleport\Models\TeleportSession;
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

    // ─── Phase 4: SSH terminal connect flow ─────────────────────────────

    /**
     * Connect modal state — Reason textarea + target node info.
     * Pattern same dengan webmail/cpanel launch-as: admin isi alasan,
     * submit → server forge cert + ticket → frontend buka modal terminal
     * dengan xterm.js + ws.connect.
     */
    public string $connectNode = '';        // hostname dari klik tombol
    public string $connectLogin = 'root';   // OS user di node target
    public string $connectReason = '';

    /**
     * Buka modal konfirmasi sebelum admin trigger SSH session ke node.
     * Permission check di sini PLUS di submit handler (defense in depth).
     */
    public function openConnect(string $node): void
    {
        Gate::authorize('teleport.ssh.connect');

        $this->connectNode = $node;
        $this->connectLogin = config('nawasara-teleport.default_login', 'root');
        $this->connectReason = '';
        $this->resetErrorBag('connectReason');

        $this->dispatch('modal-open:teleport-connect');
    }

    /**
     * Mint cert + ticket di sidecar lewat TeleportClient::connect, audit
     * log, lalu dispatch event ke browser yg trigger modal terminal +
     * ws.connect. Browser frontend bertanggungjawab buka WebSocket ke
     * sidecar pakai URL yang kita pass via Livewire event.
     *
     * Username yg dipakai untuk impersonate di Teleport = Keycloak
     * username dari auth()->user()->username. Sidecar EnsureUser bakal
     * idempotent create kalau belum exist.
     */
    public function confirmConnect(TeleportClient $client)
    {
        Gate::authorize('teleport.ssh.connect');

        $this->validate([
            'connectNode' => 'required|string|max:255',
            'connectLogin' => 'required|string|max:64',
            'connectReason' => [
                'required', 'string', 'min:10', 'max:500',
                // Reject template/banner copy-paste — pattern same dengan
                // webmail/cpanel launch-as untuk consistency audit trail.
                function ($attr, $value, $fail) {
                    $banned = ['akses ini dicatat', 'audit log', 'atasan dapat melihat'];
                    $lower = mb_strtolower(trim((string) $value));
                    foreach ($banned as $needle) {
                        if (str_contains($lower, $needle)) {
                            $fail('Alasan tidak boleh copy-paste teks banner. Tulis alasan akses yang spesifik.');
                            return;
                        }
                    }
                },
            ],
        ], [], ['connectReason' => 'alasan akses']);

        $node = $this->connectNode;
        $login = $this->connectLogin;
        $reason = trim($this->connectReason);
        $username = auth()->user()->username ?? auth()->user()->email;

        $result = $client->connect($username, $node, $login, 300);

        if (! $result['success']) {
            $this->logAttempt(
                node: $node,
                login: $login,
                reason: $reason,
                status: TeleportSession::STATUS_FAILED,
                error: $result['error'] ?? 'unknown',
                ticketId: null,
            );

            Log::warning('[teleport] connect failed', [
                'admin_id' => auth()->id(),
                'target_user' => $username,
                'node' => $node,
                'message' => $result['error'] ?? 'unknown',
            ]);

            $this->dispatch('modal-close:teleport-connect');
            $this->toastError('Connect gagal: '.($result['error'] ?? 'unknown'));
            return null;
        }

        $this->logAttempt(
            node: $node,
            login: $login,
            reason: $reason,
            status: TeleportSession::STATUS_ISSUED,
            error: null,
            ticketId: $result['ticket_id'] ?? null,
        );

        // Stash session info ke cache supaya terminal page (tab baru)
        // bisa fetch detail tanpa query string yg leak ws_url ke browser
        // history. Key = ticket_id (UUID v7), TTL 5 menit (selaras
        // dengan ticket TTL di sidecar).
        //
        // Single-use: TerminalController::show() Cache::forget setelah
        // berhasil baca — refresh tab = ticket sudah dipakai → render
        // expired page.
        $ticketId = $result['ticket_id'];
        Cache::put("teleport:terminal:{$ticketId}", [
            'ws_url' => $result['ws_url'],
            'node' => $node,
            'login' => $login,
            'target_user' => $username,
            'expires_at' => $result['expires_at'] ?? null,
            'user_id' => auth()->id(),    // verify ownership di controller
        ], now()->addMinutes(5));

        // Build URL terminal page yang akan di-load di tab baru.
        $terminalUrl = route('nawasara-teleport.terminal.show', ['ticket' => $ticketId]);

        $this->dispatch('modal-close:teleport-connect');

        // Dispatch event ke browser. JS listener pre-opened tab kosong
        // (about:blank) saat user klik submit — sekarang tinggal update
        // tab.location.href ke terminal page URL.
        $this->dispatch('teleport-terminal-open',
            url: $terminalUrl,
            node: $node,
        );

        $this->toastSuccess("Terminal {$node} di-buka di tab baru.");

        return null;
    }

    /**
     * Insert audit row. Defensive — kalau insert gagal jangan throw,
     * admin tetap perlu feedback untuk action utamanya.
     */
    protected function logAttempt(
        string $node,
        string $login,
        string $reason,
        string $status,
        ?string $error,
        ?string $ticketId,
    ): void {
        try {
            TeleportSession::create([
                'acted_by_user_id' => auth()->id(),
                'target_user' => auth()->user()->username ?? auth()->user()->email,
                'node' => $node,
                'login' => $login,
                'reason' => $reason,
                'ip' => request()->ip(),
                'user_agent' => substr((string) request()->userAgent(), 0, 500),
                'status' => $status,
                'error' => $error,
                'ticket_id' => $ticketId,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[teleport] session audit log failed: '.$e->getMessage(), [
                'admin_id' => auth()->id(),
                'node' => $node,
            ]);
        }
    }

    public function render()
    {
        return view('nawasara-teleport::livewire.pages.node.section.table');
    }
}
