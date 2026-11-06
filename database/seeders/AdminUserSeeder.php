<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    /**
     * El admin inicial lo provisiona el entorno según spec.md §1 y data-model.md §9.
     * Nunca se siembra directamente con credenciales fijas en código.
     */
    public function run(): void
    {
        $username = strtolower(trim(env('ADMIN_USERNAME', 'admin')));
        $password = env('ADMIN_PASSWORD', 'admin123456');

        $existing = DB::table('library.user')->where('username', $username)->first();

        if (!$existing) {
            DB::table('library.user')->insert([
                'id' => (string) Str::uuid(),
                'username' => $username,
                'password_hash' => Hash::make($password),
                'role' => 'admin',
            ]);
        }
    }
}
