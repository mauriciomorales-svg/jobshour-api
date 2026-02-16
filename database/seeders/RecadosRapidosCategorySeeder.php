<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RecadosRapidosCategorySeeder extends Seeder
{
    public function run(): void
    {
        // Verificar si ya existe
        $exists = DB::table('categories')->where('slug', 'recados-rapidos')->exists();
        
        if (!$exists) {
            DB::table('categories')->insert([
                'name' => 'Recados Rápidos',
                'slug' => 'recados-rapidos',
                'icon' => 'package',
                'color' => '#f59e0b',
                'description' => 'Entregas, mandados y servicios express',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
