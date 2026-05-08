<?php

$prefix = 'nawasara-teleport';

return [
    [
        'label' => 'Teleport',
        'icon' => 'lucide-server',
        'url' => '',
        'permission' => 'teleport.node.view',
        'submenu' => [
            [
                'label' => 'Nodes',
                'icon' => 'lucide-server-cog',
                'url' => url($prefix.'/nodes'),
                'permission' => 'teleport.node.view',
                'navigate' => true,
            ],
            [
                'label' => 'Users',
                'icon' => 'lucide-users',
                'url' => url($prefix.'/users'),
                'permission' => 'teleport.user.view',
                'navigate' => true,
            ],
            [
                'label' => 'Roles',
                'icon' => 'lucide-shield-check',
                'url' => url($prefix.'/roles'),
                'permission' => 'teleport.role.view',
                'navigate' => true,
            ],
        ],
    ],
];
