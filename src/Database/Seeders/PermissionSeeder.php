<?php

namespace Nawasara\Teleport\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Phase 1: read-only listings
            'teleport.node.view',
            'teleport.user.view',
            'teleport.role.view',

            // Phase 4+: SSH terminal launch — admin impersonate Keycloak user
            // saat connect. Granular permission supaya bisa di-grant hanya
            // ke user yang actually butuh SSH access (mis. ops engineer),
            // bukan default ke semua admin.
            'teleport.ssh.connect',

            // Phase 4+: Read-only audit log (siapa admin SSH ke node mana,
            // kapan, alasannya). Terpisah dari teleport.ssh.connect supaya
            // bisa di-grant ke compliance reviewer tanpa kasih akses execute.
            'teleport.session.view',

            // Phase 2+: write operations (kalau eventually expose ke Laravel)
            // 'teleport.user.manage',
            // 'teleport.role.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        // Auto-grant ke role developer untuk smooth dev experience.
        // Production: admin manual assign permission lewat Setting → Roles.
        $role = Role::where('name', 'developer')->first();
        if ($role) {
            $role->givePermissionTo($permissions);
        }
    }
}
