<?php

namespace Nawasara\Teleport\Livewire\Role\Section;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Nawasara\Teleport\Services\TeleportClient;
use Nawasara\Ui\Livewire\Concerns\HasBrowserToast;

/**
 * Read-only listing Teleport roles dengan allow/deny conditions.
 * Detail role expand di modal — auditor experience yg bisa lihat
 * RBAC config tanpa SSH ke Teleport server.
 */
class Table extends Component
{
    use HasBrowserToast;

    #[Url]
    public string $search = '';

    public ?string $detailKey = null;

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function allRoles(): array
    {
        return app(TeleportClient::class)->listRoles();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function roles(): array
    {
        $rows = $this->allRoles;

        if ($this->search !== '') {
            $needle = mb_strtolower($this->search);
            $rows = array_filter($rows, function ($r) use ($needle) {
                return str_contains(mb_strtolower($r['name'] ?? ''), $needle);
            });
        }

        usort($rows, fn ($a, $b) => strcmp($a['name'] ?? '', $b['name'] ?? ''));
        return array_values($rows);
    }

    #[Computed]
    public function detail(): ?array
    {
        if (! $this->detailKey) return null;
        foreach ($this->allRoles as $r) {
            if (($r['name'] ?? '') === $this->detailKey) return $r;
        }
        return null;
    }

    public function openDetail(string $name): void
    {
        $this->detailKey = $name;
        $this->dispatch('modal-open:teleport-role-detail');
    }

    public function closeDetail(): void
    {
        $this->dispatch('modal-close:teleport-role-detail');
        $this->detailKey = null;
    }

    public function refresh(): void
    {
        app(TeleportClient::class)->flushCache('roles');
        unset($this->allRoles, $this->roles);
        $this->toastSuccess('Data roles di-refresh dari Teleport.');
    }

    public function render()
    {
        return view('nawasara-teleport::livewire.pages.role.section.table');
    }
}
