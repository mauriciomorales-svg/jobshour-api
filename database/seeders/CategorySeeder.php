<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        DB::table('categories')->truncate();

        $categories = [
            [
                'id' => 1,
                'slug' => 'fletes-mudanzas',
                'display_name' => 'Fletes y Mudanzas',
                'icon' => 'truck',
                'color' => '#FF5722',
                'sort_order' => 1,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'slug' => 'maestro-chasquilla',
                'display_name' => 'Maestro Chasquilla / Reparaciones',
                'icon' => 'hammer',
                'color' => '#795548',
                'sort_order' => 2,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 3,
                'slug' => 'aseo-limpieza',
                'display_name' => 'Aseo y Limpieza',
                'icon' => 'broom',
                'color' => '#03A9F4',
                'sort_order' => 3,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 4,
                'slug' => 'mecanica-gruas',
                'display_name' => 'Mecánica y Grúas',
                'icon' => 'wrench',
                'color' => '#607D8B',
                'sort_order' => 4,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 5,
                'slug' => 'jardineria-campo',
                'display_name' => 'Jardinería y Campo',
                'icon' => 'tree',
                'color' => '#4CAF50',
                'sort_order' => 5,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 6,
                'slug' => 'tramites-recados',
                'display_name' => 'Trámites y Recados',
                'icon' => 'motorcycle',
                'color' => '#FFC107',
                'sort_order' => 6,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        DB::table('categories')->insert($categories);
    }
}
