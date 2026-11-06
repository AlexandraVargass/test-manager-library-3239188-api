<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategorySeeder extends Seeder
{
    /**
     * Siembra obligatoria según data-model.md §9.
     * Los 5 UUIDs son inmutables y literales.
     */
    public function run(): void
    {
        $categories = [
            [
                'id' => '11111111-1111-4111-8111-111111111111',
                'name' => 'Novela',
            ],
            [
                'id' => '22222222-2222-4222-8222-222222222222',
                'name' => 'Ciencia',
            ],
            [
                'id' => '33333333-3333-4333-8333-333333333333',
                'name' => 'Tecnología',
            ],
            [
                'id' => '44444444-4444-4444-8444-444444444444',
                'name' => 'Historia',
            ],
            [
                'id' => '55555555-5555-4555-8555-555555555555',
                'name' => 'Infantil',
            ],
        ];

        foreach ($categories as $cat) {
            DB::table('library.category')->updateOrInsert(
                ['id' => $cat['id']],
                ['name' => $cat['name']]
            );
        }
    }
}
