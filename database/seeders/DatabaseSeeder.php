<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Roles
        $roles = [
            ['name' => 'Super Admin', 'slug' => 'super-admin'],
            ['name' => 'Kepala OPD', 'slug' => 'kepala-opd'],
            ['name' => 'Staff', 'slug' => 'staff'],
            ['name' => 'Bupati', 'slug' => 'bupati'],
        ];

        foreach ($roles as $role) {
            \App\Models\Role::firstOrCreate(
                ['slug' => $role['slug']],
                ['name' => $role['name']]
            );
        }

        $superAdminRole = \App\Models\Role::where('slug', 'super-admin')->first();
        $bupatiRole = \App\Models\Role::where('slug', 'bupati')->first();

        // make developer user
        if (!User::where('email', 'developer@sppd.com')->exists()) {
            $user = User::create([
                'name' => 'Developer',
                'email' => 'developer@sppd.com',
                'username' => 'developer',
                'image' => '/storage/images/users/default.png',
                'role_id' => $superAdminRole->id,
                'instance_id' => null,
                'jabatan' => null,
                'no_hp' => null,
                'password' => bcrypt('arungboto'),
            ]);
        }

        // Bupati employee & user
        // Bupati TIDAK memiliki OPD — Bupati memimpin seluruh OPD
        // instance_id = null, semesta_id = 4842
        if (!User::where('username', '1000')->exists()) {
            $bupatiEmployee = \App\Models\Employee::firstOrCreate(
                ['nip' => '1000'],
                [
                    'semesta_id' => 4842,
                    'nama_lengkap' => 'Panca Wijaya Akbar, S.H.',
                    'nip' => '1000',
                    'jenis_pegawai' => 'bupati',
                    'instance_id' => null, // Bupati tidak punya OPD
                    'jabatan' => 'Bupati Ogan Ilir',
                    'kepala_skpd' => false, // Bupati bukan kepala OPD, memimpin semua OPD
                ]
            );

            User::create([
                'name' => 'Bupati Ogan Ilir',
                'email' => 'bupati@sppd.com',
                'username' => '1000',
                'image' => '/storage/images/users/default.png',
                'role_id' => $bupatiRole->id, // Role khusus Bupati
                'instance_id' => null, // Bupati tidak punya OPD
                'employee_id' => $bupatiEmployee->id,
                'jabatan' => 'Bupati Ogan Ilir',
                'no_hp' => null,
                'password' => bcrypt('bupati1000'),
            ]);
        }

        // Run seeders
        $this->call([
            InstanceSeeder::class,
            KlasifikasiSeeder::class,
            KategoriSuratSeeder::class,
            RegionSeeder::class,
        ]);
    }
}
