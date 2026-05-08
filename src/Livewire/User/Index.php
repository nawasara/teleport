<?php

namespace Nawasara\Teleport\Livewire\User;

use Livewire\Component;

class Index extends Component
{
    public function render()
    {
        return view('nawasara-teleport::livewire.pages.user.index')
            ->layout('nawasara-ui::components.layouts.app');
    }
}
