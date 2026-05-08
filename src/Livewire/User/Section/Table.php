<?php

namespace Nawasara\Teleport\Livewire\User\Section;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Nawasara\Teleport\Services\TeleportClient;
use Nawasara\Ui\Livewire\Concerns\HasBrowserToast;

/**
 * Read-only listing Teleport users — name, roles, traits, created_at.
 * Phase 1: client-side filter (dataset kecil, biasanya <50 users).
 */
class Table extends Component
{
    use HasBrowserToast;

    #[Url]
    public string $search = '';

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function allUsers(): array
    {
        return app(TeleportClient::class)->listUsers();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function users(): array
    {
        $rows = $this->allUsers;

        if ($this->search !== '') {
            $needle = mb_strtolower($this->search);
            $rows = array_filter($rows, function ($u) use ($needle) {
                $haystack = mb_strtolower(
                    ($u['name'] ?? '').' '.
                    implode(' ', $u['roles'] ?? [])
                );
                return str_contains($haystack, $needle);
            });
        }

        usort($rows, fn ($a, $b) => strcmp($a['name'] ?? '', $b['name'] ?? ''));

        return array_values($rows);
    }

    public function refresh(): void
    {
        app(TeleportClient::class)->flushCache('users');
        unset($this->allUsers, $this->users);
        $this->toastSuccess('Data users di-refresh dari Teleport.');
    }

    public function render()
    {
        return view('nawasara-teleport::livewire.pages.user.section.table');
    }
}
