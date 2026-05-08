<div>
    <x-slot name="breadcrumb">
        <livewire:nawasara-ui.shared-components.breadcrumb
            :items="[['label' => 'Teleport', 'url' => '#'], ['label' => 'Nodes']]" />
    </x-slot>

    <x-nawasara-ui::page.container>
        @livewire('nawasara-teleport.node.section.table')
    </x-nawasara-ui::page.container>
</div>
