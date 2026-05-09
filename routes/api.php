<?php

use Illuminate\Support\Facades\Route;
use Nawasara\Teleport\Http\Controllers\SessionClosedController;

/**
 * Internal API routes — caller adalah sidecar Go (`nawasara-teleport-bridge`)
 * yang push event balik ke Laravel. Bukan untuk public konsumsi.
 *
 * Naming `internal/teleport/...` bukan `webhook/teleport/...` karena
 * "webhook" di Laravel ekosistem biasanya implies external SaaS callback
 * (Stripe, GitHub) — sidecar kita = internal trusted service di same
 * deployment, bukan SaaS.
 *
 * Auth: HMAC bearer dengan secret yang sama (Vault `teleport.bridge_secret`)
 * untuk both direction. Lihat SessionClosedController::verifyBearer.
 *
 * Middleware 'api' (bukan 'web') karena stateless — no session, no CSRF.
 * 'api' middleware group default di Laravel 12+ include throttle:api +
 * SubstituteBindings, sufficient untuk webhook.
 */
Route::middleware(['api'])->prefix('api/internal/teleport')->group(function () {
    Route::post('session-closed', [SessionClosedController::class, 'store'])
        ->name('nawasara-teleport.internal.session-closed');
});
