<?php

/**
 * nawasara-teleport — package config.
 *
 * Sebagian besar runtime config untuk integrasi Teleport (URL bridge, secret,
 * proxy address) di-store di Vault group `teleport` BUKAN di-file ini —
 * supaya admin bisa rotate tanpa redeploy + tidak commit credentials.
 *
 * File ini menampung config yang TIDAK rahasia + tidak sering berubah:
 * timeout HTTP, cache TTL, dll.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | HTTP timeout untuk call ke sidecar (dalam detik)
    |--------------------------------------------------------------------------
    |
    | Sidecar query ke Teleport gRPC — list resources di tenant besar bisa
    | makan waktu beberapa detik. Default 30 detik = headroom cukup untuk
    | cluster 100+ nodes tanpa timeout user-facing.
    */
    'http_timeout' => env('TELEPORT_HTTP_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Cache TTL untuk response sidecar (dalam detik)
    |--------------------------------------------------------------------------
    |
    | Phase 1: list endpoints kena cache server-side untuk reduce load
    | Teleport dan speed up page load. 60 detik default = balance antara
    | freshness (Teleport state berubah lambat untuk node list) dan
    | performance.
    |
    | Set 0 untuk disable cache (every page load hit sidecar fresh).
    */
    'cache_ttl' => env('TELEPORT_CACHE_TTL', 60),

    /*
    |--------------------------------------------------------------------------
    | Default login user untuk SSH session (Phase 4+)
    |--------------------------------------------------------------------------
    |
    | Saat user klik [Connect] di node, sidecar mint cert dengan login user
    | ini. Bisa di-override per-request dari Livewire form.
    | Di Vault group teleport bisa ditambah field `default_login` untuk
    | override per instance — tapi belum dibutuhkan di Phase 1.
    */
    'default_login' => env('TELEPORT_DEFAULT_LOGIN', 'root'),
];
