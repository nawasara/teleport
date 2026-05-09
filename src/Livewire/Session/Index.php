<?php

namespace Nawasara\Teleport\Livewire\Session;

use Livewire\Component;

/**
 * Page-level container untuk Teleport SSH Sessions audit log. Sengaja
 * minimal (mirror Node\Index + ImpersonationLog\Index) — semua state +
 * filter + table di Section\Table component supaya page reload-free
 * saat filter ganti.
 */
class Index extends Component
{
    public function render()
    {
        return view('nawasara-teleport::livewire.pages.session.index')
            ->layout('nawasara-ui::components.layouts.app');
    }
}
