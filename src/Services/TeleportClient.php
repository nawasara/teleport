<?php

namespace Nawasara\Teleport\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Nawasara\Vault\Facades\Vault;

/**
 * HTTP wrapper untuk call sidecar Go (`nawasara-teleport-bridge`).
 *
 * Sidecar listen di URL yang di-config Vault group `teleport`. Auth via
 * bearer HMAC token computed dari shared secret. Request balikin JSON
 * yang langsung di-decode + return ke caller (Livewire / repository).
 *
 * Pattern mirror dengan KeycloakClient/ZoomClient/WhmClient di package
 * lain — same shape (testConnection, isConfigured, dst.) supaya Vault
 * "Test Connection" button bisa register ke sini sama caranya.
 *
 * Cache:
 *   List endpoints (nodes/users/roles) di-cache TTL pendek (default 60s
 *   per config nawasara-teleport.cache_ttl) supaya page load cepat dan
 *   reduce load ke sidecar/Teleport. Health check tidak di-cache (penting
 *   untuk Vault test connection real-time).
 *
 * Errors:
 *   Method-method list return [] kalau bridge unreachable / unauth /
 *   error response — fail-safe pattern (page tetap render dengan empty
 *   state instead of 500). testConnection() return shape detailed
 *   {success, message} untuk feedback ke admin di Vault UI.
 */
class TeleportClient
{
    /**
     * Shape response standar dari Vault `test_connection` callback.
     *
     * @var string
     */
    public const VAULT_GROUP = 'teleport';

    /**
     * Cek apakah credential lengkap di Vault (bridge_url + bridge_secret).
     * Dipakai oleh page Livewire / sync job untuk decide apakah bisa
     * call sidecar atau tampilkan empty state "Vault belum di-config".
     */
    public function isConfigured(): bool
    {
        $url = (string) Vault::get(self::VAULT_GROUP, 'bridge_url');
        $secret = (string) Vault::get(self::VAULT_GROUP, 'bridge_secret');

        return $url !== '' && $secret !== '';
    }

    /**
     * Bridge URL dari Vault. Trim trailing slash supaya konkat /api/...
     * gak double slash.
     */
    protected function bridgeUrl(): string
    {
        return rtrim((string) Vault::get(self::VAULT_GROUP, 'bridge_url'), '/');
    }

    /**
     * Bridge HMAC secret dari Vault.
     */
    protected function bridgeSecret(): string
    {
        return (string) Vault::get(self::VAULT_GROUP, 'bridge_secret');
    }

    /**
     * Compute bearer token = hmac_sha256(secret, ""). Match sidecar
     * verification logic di internal/auth/hmac.go.
     *
     * Empty message di sini sengaja — bearer token fix per-instance
     * sidecar, bukan per-request. Kalau di future butuh per-request
     * signing (mis. include path + nonce), update logic di sini DAN
     * di sidecar verifier.
     */
    protected function computeToken(): string
    {
        return hash_hmac('sha256', '', $this->bridgeSecret());
    }

    /**
     * Build PendingRequest dengan auth header, JSON accept, timeout config.
     * Setiap method di kelas ini panggil $this->api()->get(...) supaya
     * config konsisten.
     */
    protected function api(): PendingRequest
    {
        return Http::baseUrl($this->bridgeUrl())
            ->withToken($this->computeToken())
            ->acceptJson()
            ->timeout((int) config('nawasara-teleport.http_timeout', 30));
    }

    /**
     * Test koneksi ke sidecar — return shape standar Vault `{success, message}`.
     * Dipanggil dari Vault credential UI saat admin klik "Test Connection".
     *
     * Sengaja TIDAK pakai cache (kita mau real-time status). Sengaja dispatch
     * ke /api/health (light endpoint, tidak hit Teleport API kalau sidecar
     * cuma cek connection state).
     *
     * Diagnostics yang di-emit:
     *   - bridge_url kosong → "Vault group teleport belum di-config"
     *   - HTTP exception   → "Tidak bisa hubungi sidecar di {url}: {msg}"
     *   - HTTP 401         → "BRIDGE_SECRET tidak match (Vault vs sidecar .env)"
     *   - HTTP 503         → "Sidecar tidak bisa connect ke Teleport: {error}"
     *   - HTTP 200         → "OK. Cluster: {name}"
     */
    public function testConnection(?string $instance = null): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'message' => 'Field Teleport belum lengkap di Vault (bridge_url + bridge_secret).'];
        }

        try {
            $response = $this->api()->get('/api/health');
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Tidak bisa hubungi sidecar di '.$this->bridgeUrl().': '.$e->getMessage(),
            ];
        }

        if ($response->status() === 401) {
            return [
                'success' => false,
                'message' => 'Auth ditolak sidecar. Pastikan BRIDGE_SECRET di Vault === nilai di .env sidecar.',
            ];
        }

        if ($response->status() === 503) {
            $err = $response->json('error', 'unknown');
            return [
                'success' => false,
                'message' => 'Sidecar gagal connect ke Teleport: '.$err,
            ];
        }

        if (! $response->successful()) {
            return [
                'success' => false,
                'message' => 'Sidecar HTTP '.$response->status().': '.$response->body(),
            ];
        }

        $body = $response->json();
        $cluster = $body['cluster_name'] ?? 'unknown';
        return [
            'success' => true,
            'message' => "Connect ke Teleport berhasil. Cluster: {$cluster}.",
        ];
    }

    /**
     * Health endpoint — return raw response untuk page header / status badge.
     * Tidak di-cache (cheap call, important to be live).
     *
     * @return array{cluster_name?: string, server_time?: string, status?: string, error?: string}
     */
    public function health(): array
    {
        if (! $this->isConfigured()) {
            return ['error' => 'not configured'];
        }

        try {
            $response = $this->api()->get('/api/health');
            if ($response->successful()) {
                return $response->json();
            }
            return ['error' => $response->json('error', 'HTTP '.$response->status())];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * List SSH nodes registered di Teleport. Cache pendek per config TTL.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listNodes(): array
    {
        return $this->cachedList('nodes');
    }

    /**
     * List Teleport users.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listUsers(): array
    {
        return $this->cachedList('users');
    }

    /**
     * List Teleport roles dengan allow/deny conditions.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listRoles(): array
    {
        return $this->cachedList('roles');
    }

    /**
     * Generic helper untuk list endpoint. Cache key di-prefix supaya nanti
     * mudah `Cache::tags(...)` flush bareng saat user klik refresh.
     *
     * Endpoint shape: /api/{resource} → array of objects.
     */
    protected function cachedList(string $resource): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $ttl = (int) config('nawasara-teleport.cache_ttl', 60);
        $key = "nawasara_teleport:list:{$resource}";

        $fetch = function () use ($resource): array {
            try {
                $response = $this->api()->get("/api/{$resource}");
                if ($response->successful()) {
                    return $response->json() ?? [];
                }
                logger()->warning('[teleport-bridge] list '.$resource.' failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return [];
            } catch (\Throwable $e) {
                logger()->warning('[teleport-bridge] list '.$resource.' exception', [
                    'message' => $e->getMessage(),
                ]);
                return [];
            }
        };

        if ($ttl <= 0) {
            return $fetch();
        }

        return Cache::remember($key, $ttl, $fetch);
    }

    /**
     * Force cache flush — dipanggil saat user klik tombol refresh di page
     * Livewire. Flush per resource granular kalau caller specify, atau
     * flush all kalau dipanggil tanpa arg.
     *
     * @param  string|null  $resource  'nodes' | 'users' | 'roles' | null=all
     */
    public function flushCache(?string $resource = null): void
    {
        if ($resource !== null) {
            Cache::forget("nawasara_teleport:list:{$resource}");
            return;
        }
        foreach (['nodes', 'users', 'roles'] as $r) {
            Cache::forget("nawasara_teleport:list:{$r}");
        }
    }
}
