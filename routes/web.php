<?php

use Illuminate\Support\Facades\Route;
use Nawasara\Teleport\Livewire\Node\Index as NodeIndex;
use Nawasara\Teleport\Livewire\Role\Index as RoleIndex;
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
});
