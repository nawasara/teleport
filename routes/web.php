<?php

use Illuminate\Support\Facades\Route;
use Nawasara\Teleport\Http\Controllers\TerminalController;
use Nawasara\Teleport\Http\Controllers\TerminalReissueController;
use Nawasara\Teleport\Livewire\Node\Index as NodeIndex;
use Nawasara\Teleport\Livewire\Role\Index as RoleIndex;
use Nawasara\Teleport\Livewire\Session\Index as SessionIndex;
use Nawasara\Teleport\Livewire\User\Index as UserIndex;
use Spatie\Permission\Middleware\PermissionMiddleware;

Route::middleware(['web', 'auth'])->prefix('nawasara-teleport')->group(function () {
    Route::get('nodes', NodeIndex::class)
        ->middleware(PermissionMiddleware::using('teleport.node.view'))
        ->name('nawasara-teleport.node.index');

    Route::get('users', UserIndex::class)
        ->middleware(PermissionMiddleware::using('teleport.user.view'))
        ->name('nawasara-teleport.user.index');

    Route::get('roles', RoleIndex::class)
        ->middleware(PermissionMiddleware::using('teleport.role.view'))
        ->name('nawasara-teleport.role.index');

    // SSH session audit log — read-only audit trail dari Phase 4 launches.
    // Permission terpisah dari teleport.ssh.connect karena audit reviewer
    // (mis. internal compliance) butuh view tanpa harus bisa execute.
    Route::get('sessions', SessionIndex::class)
        ->middleware(PermissionMiddleware::using('teleport.session.view'))
        ->name('nawasara-teleport.session.index');

    // SSH terminal — fullscreen page di tab baru. Browser redirect ke
    // sini setelah Livewire confirmConnect mint ticket di sidecar.
    // Permission gate di controller (selain route middleware) untuk
    // defense in depth.
    Route::get('terminal/{ticket}', [TerminalController::class, 'show'])
        ->middleware(PermissionMiddleware::using('teleport.ssh.connect'))
        ->where('ticket', '[a-f0-9-]+')
        ->name('nawasara-teleport.terminal.show');

    // Reconnect endpoint — dipanggil JS dari terminal page saat tombol
    // [Reconnect] di-klik setelah ws drop. Lookup audit row by
    // ticket_id, replay node/login/reason, mint cert+ticket baru,
    // return JSON dengan ws_url baru.
    //
    // POST (bukan GET) karena state-changing (insert audit row +
    // mint cert). CSRF token wajib — JS pakai meta name=csrf-token
    // dari layout default Laravel + header X-CSRF-TOKEN.
    Route::post('terminal/{ticket}/reissue', [TerminalReissueController::class, 'reissue'])
        ->middleware(PermissionMiddleware::using('teleport.ssh.connect'))
        ->where('ticket', '[a-f0-9-]+')
        ->name('nawasara-teleport.terminal.reissue');
});
