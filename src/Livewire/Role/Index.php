<?php

namespace Nawasara\Teleport\Livewire\Role;

use Livewire\Component;

class Index extends Component
{
    public function render()
    {
        return view('nawasara-teleport::livewire.pages.role.index')
            ->layout('nawasara-ui::components.layouts.app');
    }
}
