<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log untuk Teleport SSH session launch flow (Phase 4).
 *
 * Setiap row = satu attempt admin Nawasara klik [Connect] di node.
 * Sister-table dari nawasara_webmail_sessions + nawasara_cpanel_sessions —
 * ditampilkan di Impersonation Log audit page sebagai bagian dari UNION.
 *
 * Schema kontrak (sama disiplin dengan cpanel_sessions):
 *   - acted_by_user_id NOT NULL — admin yang trigger Connect, never null
 *   - target_user      = Teleport username (= Keycloak username)
 *   - node             = hostname dari /api/nodes
 *   - login            = OS user di node (root/ubuntu/...)
 *   - reason           = alasan akses, wajib min 10 char (validated di Livewire)
 *   - status: issued | failed
 *   - ticket_id        = UUID v7 dari sidecar untuk traceability
 *   - duration_seconds = optional, set kalau session close cleanly (untuk
 *     session yang putus mid-stream / browser close paksa, biarkan null)
 *
 * Tidak ada `launch_kind` enum (single flow saja) atau `rejected` status
 * (semua bisnis rule check terjadi di Livewire validation, sebelum hit
 * sidecar — jadi kalau sampai insert row, sudah pasti issued/failed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nawasara_teleport_sessions', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('acted_by_user_id');

            $table->string('target_user', 64)
                ->comment('Teleport username (= Keycloak username) yang di-impersonate');

            $table->string('node', 255)
                ->comment('Target SSH node hostname dari Teleport /api/nodes');

            $table->string('login', 64)->default('root')
                ->comment('OS login user di node (root/ubuntu/dst.)');

            $table->text('reason');

            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();

            $table->enum('status', ['issued', 'failed'])->default('issued');
            $table->text('error')->nullable();

            // Ticket UUID dari sidecar — single-use voucher yg di-issue ke
            // browser. Dipakai untuk traceability lintas Laravel + sidecar
            // log. Format UUID v7 (time-sortable).
            $table->string('ticket_id', 64)->nullable();

            // Session duration (saat ws close clean). Nullable karena untuk
            // session yang putus paksa, frontend mungkin tidak emit close
            // event yang sampai ke server.
            $table->unsignedInteger('duration_seconds')->nullable();

            $table->timestamps();

            $table->index(['acted_by_user_id', 'created_at']);
            $table->index(['target_user', 'created_at']);
            $table->index(['node', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nawasara_teleport_sessions');
    }
};
