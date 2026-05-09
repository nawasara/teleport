<div>
    <x-slot name="breadcrumb">
        <livewire:nawasara-ui.shared-components.breadcrumb
            :items="[['label' => 'Teleport', 'url' => '#'], ['label' => 'SSH Sessions']]" />
    </x-slot>

    {{-- Page title moved into Section\Table supaya bisa share row dengan
         time-window selector yang stateful — pattern same dengan
         ImpersonationLog. Index cuma host breadcrumb + container. --}}
    <x-nawasara-ui::page.container>
        @livewire('nawasara-teleport.session.section.table')
    </x-nawasara-ui::page.container>
</div>
