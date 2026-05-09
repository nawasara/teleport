<?php

namespace Nawasara\Teleport\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Nawasara\Teleport\Models\TeleportSession;
use Nawasara\Vault\Facades\Vault;

/**
 * Webhook receiver untuk event ws-close dari sidecar Go. Sidecar POST
 * ke endpoint ini setelah SSH session berakhir untuk patch row audit
 * dengan duration_seconds + (future) error/exit_code metadata.
 *
 * Direction reverse dari /api/connect:
 *   - Laravel → Sidecar: HMAC bearer di outbound request (TeleportClient)
 *   - Sidecar → Laravel: HMAC bearer di inbound POST (controller ini verify)
 *
 * Same shared secret di Vault (`teleport.bridge_secret`), same algorithm
 * (`hash_hmac('sha256', '', $secret)` hex). Sehingga sidecar restart /
 * secret rotation di-handle sekali doang di Vault.
 *
 * Gak butuh auth middleware ('auth') karena caller bukan user — caller
 * adalah sidecar internal. Auth via HMAC bearer instead.
 *
 * Defensive design:
 *   - Row mungkin gak ada (sidecar webhook fire sebelum Laravel insert
 *     selesai → race condition; atau row ke-prune setelah retention).
 *     Treat as no-op + 200 supaya sidecar tidak retry indefinitely.
 *   - Duration mungkin invalid (negative, terlalu besar). Clamp ke
 *     reasonable range [0, 86400] (max 1 hari).
 *   - HMAC mismatch → 401 + log warning (potensi attempt forge audit row).
 */
class SessionClosedController extends Controller
{
    /**
     * Max duration yang reasonable untuk single SSH session. Tampaknya
     * 24 jam berlebihan untuk interactive session, tapi cap di sini
     * adalah safety bound (cert TTL 5 menit Phase 4 — duration_seconds
     * realistically <300, tapi future Phase mungkin extend cert TTL).
     */
    protected const MAX_DURATION_SECONDS = 86400;

    public function store(Request $request): JsonResponse
    {
        // Auth: HMAC bearer same dengan outbound TeleportClient. Sidecar
        // sign dengan shared secret, Laravel verify pakai secret yang
        // sama dari Vault.
        if (! $this->verifyBearer($request)) {
            Log::warning('[teleport] session-closed webhook: HMAC mismatch', [
                'ip' => $request->ip(),
                'ua' => $request->userAgent(),
            ]);
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $data = $request->validate([
            'ticket_id' => 'required|string|min:8|max:64',
            'duration_seconds' => 'required|integer|min:0|max:'.self::MAX_DURATION_SECONDS,
            'ended_reason' => 'nullable|string|in:normal,error,timeout',
            'error_message' => 'nullable|string|max:1000',
        ]);

        $row = TeleportSession::where('ticket_id', $data['ticket_id'])->first();

        if (! $row) {
            // Row belum exist atau sudah di-prune. Bukan error — sidecar
            // tetep ack OK supaya tidak retry endless.
            Log::info('[teleport] session-closed for unknown ticket', [
                'ticket_id' => $data['ticket_id'],
                'duration_seconds' => $data['duration_seconds'],
            ]);
            return response()->json(['status' => 'ok', 'updated' => false]);
        }

        // Patch row. Keep status existing (issued/failed) — sidecar
        // webhook hanya enrich, tidak overwrite status awal.
        // Kalau session berakhir karena error setelah issued, append
        // error_message ke kolom error tanpa flip status (status reflects
        // launch success, bukan session lifetime success).
        $update = [
            'duration_seconds' => $data['duration_seconds'],
        ];

        if (! empty($data['error_message']) && empty($row->error)) {
            // Hanya set error kalau belum ada — tidak overwrite error
            // dari connect setup (pre-shell).
            $update['error'] = '[ws-close] '.substr($data['error_message'], 0, 500);
        }

        $row->update($update);

        return response()->json([
            'status' => 'ok',
            'updated' => true,
            'session_id' => $row->id,
        ]);
    }

    /**
     * Verify HMAC bearer token. Pattern same dengan TeleportClient::computeToken
     * dan sidecar internal/auth/hmac.go — token = hex(hmac_sha256(secret, "")).
     *
     * Kenapa empty message: token static per-instance sidecar, bukan
     * per-request signed. Equivalent dengan shared API key dengan
     * timing-safe compare (hash_equals).
     */
    protected function verifyBearer(Request $request): bool
    {
        $header = $request->header('Authorization', '');
        if (! str_starts_with($header, 'Bearer ')) {
            return false;
        }
        $given = substr($header, 7);

        $secret = (string) Vault::get('teleport', 'bridge_secret');
        if ($secret === '') {
            return false;
        }

        $expected = hash_hmac('sha256', '', $secret);

        return hash_equals($expected, $given);
    }
}
