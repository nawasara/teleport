<?php

namespace Nawasara\Teleport\Livewire\Node;

use Livewire\Component;

/**
 * Page-level container untuk Teleport Nodes listing. State + table di
 * Section\Table component (mirror pattern keycloak/zoom).
 */
class Index extends Component
{
    public function render()
    {
        return view('nawasara-teleport::livewire.pages.node.index')
            ->layout('nawasara-ui::components.layouts.app');
    }
}
