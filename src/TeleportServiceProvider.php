<?php

namespace Nawasara\Teleport;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Nawasara\Teleport\Services\TeleportClient;
use Symfony\Component\Finder\Finder;

class TeleportServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'nawasara-teleport');

        // Anonymous Blade components — kalau nanti ada di resources/views/components.
        // Phase 1 belum ada, tapi register up-front supaya consumer view bisa
        // langsung pakai <x-nawasara-teleport::xxx> tanpa modify ServiceProvider lagi.
        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'nawasara-teleport');

        $this->registerLivewire();
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nawasara-teleport.php', 'nawasara-teleport');

        // Singleton untuk TeleportClient — stateful (cache token computed dari
        // BRIDGE_SECRET di Vault), share cross-request supaya HTTP/2 connection
        // ke sidecar bisa di-reuse oleh Guzzle internal pool.
        $this->app->singleton(TeleportClient::class, fn () => new TeleportClient());
    }

    /**
     * Auto-discover Livewire components di src/Livewire dan register dengan
     * naming convention `nawasara-teleport.{path-kebab-case}`.
     *
     * Mirror pattern keycloak/zoom/whm. Contoh mapping:
     *   src/Livewire/Node/Index.php           → nawasara-teleport.node.index
     *   src/Livewire/Node/Section/Table.php   → nawasara-teleport.node.section.table
     */
    public function registerLivewire(): void
    {
        $namespace = 'Nawasara\\Teleport\\Livewire';
        $basePath = __DIR__.'/Livewire';

        if (! is_dir($basePath)) {
            return;
        }

        $finder = new Finder();
        $finder->files()->in($basePath)->name('*.php');

        foreach ($finder as $file) {
            $relativePath = str_replace('/', '\\', $file->getRelativePathname());
            $class = $namespace.'\\'.Str::beforeLast($relativePath, '.php');

            if (class_exists($class)) {
                $alias = 'nawasara-teleport.'.
                    Str::of($relativePath)
                        ->replace('.php', '')
                        ->replace('\\', '.')
                        ->replace('/', '.')
                        ->explode('.')
                        ->map(fn ($segment) => Str::kebab($segment))
                        ->join('.');

                Livewire::component($alias, $class);
            }
        }
    }
}
