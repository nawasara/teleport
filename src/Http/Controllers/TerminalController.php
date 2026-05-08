<?php

namespace Nawasara\Teleport\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

/**
 * Render fullscreen terminal page di tab baru.
 *
 * Flow:
 *   1. Livewire confirmConnect() di Node\Section\Table mint ticket via
 *      sidecar /api/connect → dapat {ticket_id, ws_url}.
 *   2. Livewire stash session info (ws_url + node + login + reason) di
 *      cache (key=ticket_id, TTL 5 menit) DAN dispatch event ke browser
 *      untuk pre-open tab + redirect ke route ini dengan ticket_id.
 *   3. Browser open new tab dengan URL /nawasara-teleport/terminal/{ticket}
 *      → controller ini fetch session info dari cache → render fullscreen
 *      page dengan xterm.js + ws connection.
 *
 * Cache approach (vs query string carry ws_url):
 *   - ws_url contain HMAC-equivalent capability token; embed di URL akan
 *     log di browser history + access log (security risk, walaupun
 *     ticket TTL pendek).
 *   - Cache key by ticket_id supaya URL bersih, dan cache auto-expire.
 *
 * Permission: gate teleport.ssh.connect — same dengan Connect button
 * di Livewire. Defense in depth.
 */
class TerminalController extends Controller
{
    public function show(Request $request, string $ticket)
    {
        Gate::authorize('teleport.ssh.connect');

        // Fetch session info dari cache (di-stash oleh Livewire confirmConnect)
        $sessionKey = 'teleport:terminal:'.$ticket;
        $session = Cache::get($sessionKey);

        if (! $session) {
            // Ticket tidak valid / expired / sudah di-consume di tab lain.
            // Render error page (bukan abort 404 — UX lebih jelas user paham).
            return response()->view('nawasara-teleport::terminal.expired', [], 410);
        }

        // Verify session ownership: hanya admin yang issue ticket yang bisa
        // open terminal-nya. Cegah user lain pakai URL share ticket.
        if (($session['user_id'] ?? null) !== auth()->id()) {
            abort(403, 'Ticket tidak milik anda.');
        }

        // Cache hit — pop value supaya single-use (tab refresh = ticket
        // re-fetch dari sidecar, bukan replay session lama).
        Cache::forget($sessionKey);

        return response()->view('nawasara-teleport::terminal.show', [
            'ticket' => $ticket,
            'wsUrl' => $session['ws_url'],
            'node' => $session['node'],
            'login' => $session['login'],
            'targetUser' => $session['target_user'],
            'expiresAt' => $session['expires_at'] ?? null,
        ]);
    }
}
