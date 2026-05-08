<?php

namespace Nawasara\Teleport\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit log untuk admin Teleport SSH session launch (Phase 4).
 *
 * Kontrak schema (sister-table dari WebmailSession + CpanelSession):
 *   - acted_by_user_id NOT NULL — admin yang trigger Connect
 *   - target_user                 = Teleport username (= Keycloak username)
 *   - node                        = hostname target SSH node
 *   - login                       = OS user di node (root/ubuntu/dst.)
 *   - reason                      = alasan akses (validated min 10 char)
 *   - status: issued | failed
 *   - ticket_id                   = UUID v7 dari sidecar untuk traceability
 *
 * Single flow (impersonation only) — no launch_kind enum, no rejected status.
 */
class TeleportSession extends Model
{
    public const STATUS_ISSUED = 'issued';
    public const STATUS_FAILED = 'failed';

    protected $table = 'nawasara_teleport_sessions';

    protected $fillable = [
        'acted_by_user_id',
        'target_user',
        'node',
        'login',
        'reason',
        'ip',
        'user_agent',
        'status',
        'error',
        'ticket_id',
        'duration_seconds',
    ];

    public function actor(): BelongsTo
    {
        $userModel = config('auth.providers.users.model');
        return $this->belongsTo($userModel, 'acted_by_user_id');
    }

    public function scopeActedBy(Builder $query, int $adminUserId): Builder
    {
        return $query->where('acted_by_user_id', $adminUserId);
    }

    public function scopeForNode(Builder $query, string $node): Builder
    {
        return $query->where('node', $node);
    }

    public function scopeIssued(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ISSUED);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }
}
