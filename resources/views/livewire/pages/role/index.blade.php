<div>
    <x-slot name="breadcrumb">
        <livewire:nawasara-ui.shared-components.breadcrumb
            :items="[['label' => 'Teleport', 'url' => '#'], ['label' => 'Roles']]" />
    </x-slot>

    <x-nawasara-ui::page.container>
        @livewire('nawasara-teleport.role.section.table')
    </x-nawasara-ui::page.container>
</div>
