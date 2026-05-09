<?php

namespace Nawasara\Teleport\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Nawasara\Teleport\Models\TeleportSession;
use Nawasara\Teleport\Services\TeleportClient;

/**
 * Reconnect endpoint untuk terminal page. Saat WebSocket drop (network
 * blip, cert expired, idle timeout), tombol [Reconnect] di terminal page
 * POST ke sini untuk mint ticket baru tanpa kembali ke /nodes.
 *
 * Flow:
 *   1. Lookup original session row by ticket_id (path param)
 *   2. Verify ownership (acted_by_user_id == auth()->id())
 *   3. Verify recency (created_at within last 30 minutes — RECONNECT_WINDOW)
 *   4. Call TeleportClient::connect() dengan SAME node/login dari original
 *   5. Insert NEW audit row dengan reason="[reconnect of #N] {orig reason}"
 *   6. Stash session info di Cache (single-use, 5 min TTL)
 *   7. Return JSON {ws_url, ticket_id, terminal_url} ke browser
 *
 * Browser side:
 *   - Receive new ticket_id
 *   - Replace history.pushState ke URL terminal baru (so refresh works)
 *   - Close old ws
 *   - Open new ws dengan ws_url baru
 *   - xterm.clear() + tulis banner "[reconnected]"
 *
 * Audit chain:
 *   Original session id=N stays as-is (status=issued atau failed).
 *   Reconnect = ROW BARU dengan ticket baru, reason mengandung referensi
 *   ke #N supaya forensik tetap traceable. Setiap cert mint = audit row
 *   terpisah (matches reality: each SSH session = independent shell).
 *
 * Permission: 'teleport.ssh.connect' (same dengan original Connect button).
 * Tidak ada permission baru "reconnect" supaya admin yang already authorized
 * untuk SSH tetap bisa, tapi auditor read-only tidak gain capability baru.
 */
class TerminalReissueController extends Controller
{
    /**
     * Window setelah session created saat tombol Reconnect masih valid.
     * Cukup buat handle interrupt eksternal (rapat 15 menit, network
     * restart, dll) tanpa expose tab abandon dari kemarin.
     */
    protected const RECONNECT_WINDOW_MINUTES = 30;

    public function __construct(
        protected TeleportClient $client,
    ) {
    }

    public function reissue(Request $request, string $ticket): JsonResponse
    {
        Gate::authorize('teleport.ssh.connect');

        // Lookup original session — source of truth untuk node/login/reason.
        $original = TeleportSession::where('ticket_id', $ticket)->first();

        if (! $original) {
            return response()->json([
                'error' => 'session_not_found',
                'message' => 'Session asli tidak ditemukan. Silakan kembali ke halaman Nodes dan klik Connect lagi.',
            ], 404);
        }

        // Ownership check — defense in depth (selain permission gate).
        // Admin lain pakai URL share tab tidak boleh reconnect.
        if ((int) $original->acted_by_user_id !== (int) auth()->id()) {
            Log::warning('[teleport] reconnect attempt by non-owner', [
                'admin_id' => auth()->id(),
                'original_admin_id' => $original->acted_by_user_id,
                'ticket' => $ticket,
            ]);
            return response()->json([
                'error' => 'forbidden',
                'message' => 'Session ini bukan milik anda.',
            ], 403);
        }

        // Recency check — window 30 menit setelah session created. Setelah
        // ini, admin harus mulai flow baru dari /nodes.
        $minAge = now()->subMinutes(self::RECONNECT_WINDOW_MINUTES);
        if ($original->created_at->lt($minAge)) {
            return response()->json([
                'error' => 'window_expired',
                'message' => 'Session sudah lebih dari '.self::RECONNECT_WINDOW_MINUTES.' menit. Mulai ulang dari halaman Nodes.',
            ], 410);
        }

        // Mint cert + ticket baru via sidecar. Pakai username yang sama
        // dengan original (= Keycloak username admin saat itu — match
        // dengan auth()->user()->username yang sekarang karena ownership
        // check di atas pass).
        $username = auth()->user()->username ?? auth()->user()->email;
        $node = $original->node;
        $login = $original->login;

        $result = $this->client->connect($username, $node, $login, 300);

        if (! ($result['success'] ?? false)) {
            // Audit even kalau gagal — admin perlu trace kalau Teleport down.
            $this->logReissueAttempt(
                original: $original,
                status: TeleportSession::STATUS_FAILED,
                error: $result['error'] ?? 'unknown',
                ticketId: null,
            );

            return response()->json([
                'error' => 'reissue_failed',
                'message' => 'Gagal mint cert baru: '.($result['error'] ?? 'unknown'),
            ], 502);
        }

        // Insert audit row baru. Reason di-prefix supaya self-explanatory
        // di session viewer page.
        $newTicketId = $result['ticket_id'];
        $newRow = $this->logReissueAttempt(
            original: $original,
            status: TeleportSession::STATUS_ISSUED,
            error: null,
            ticketId: $newTicketId,
        );

        // Stash session info untuk fallback render kalau admin direct-load
        // URL baru (mis. via window.history.pushState lalu Ctrl+R).
        // Pattern same dengan flow original di Livewire Node\Section\Table.
        Cache::put("teleport:terminal:{$newTicketId}", [
            'ws_url' => $result['ws_url'],
            'node' => $node,
            'login' => $login,
            'target_user' => $username,
            'expires_at' => $result['expires_at'] ?? null,
            'user_id' => auth()->id(),
        ], now()->addMinutes(5));

        $terminalUrl = route('nawasara-teleport.terminal.show', ['ticket' => $newTicketId]);

        return response()->json([
            'status' => 'ok',
            'ws_url' => $result['ws_url'],
            'ticket_id' => $newTicketId,
            'terminal_url' => $terminalUrl,
            'audit_id' => $newRow?->id,
        ]);
    }

    /**
     * Log reissue attempt. Same shape dengan logAttempt di Livewire
     * Node\Section\Table tapi reason di-prefix dengan referensi ke
     * original session id.
     */
    protected function logReissueAttempt(
        TeleportSession $original,
        string $status,
        ?string $error,
        ?string $ticketId,
    ): ?TeleportSession {
        try {
            $reason = sprintf(
                '[reconnect of #%d] %s',
                $original->id,
                $original->reason ?? '-',
            );

            // Limit ke 500 char (matches original validation max).
            $reason = mb_substr($reason, 0, 500);

            return TeleportSession::create([
                'acted_by_user_id' => auth()->id(),
                'target_user' => $original->target_user,
                'node' => $original->node,
                'login' => $original->login,
                'reason' => $reason,
                'ip' => request()->ip(),
                'user_agent' => substr((string) request()->userAgent(), 0, 500),
                'status' => $status,
                'error' => $error,
                'ticket_id' => $ticketId,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[teleport] reconnect audit log failed: '.$e->getMessage(), [
                'admin_id' => auth()->id(),
                'original_id' => $original->id,
            ]);
            return null;
        }
    }
}
