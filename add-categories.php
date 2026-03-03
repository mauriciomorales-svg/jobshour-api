<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$new = [
    // Nuevas categorías adicionales (las 14 anteriores ya están en BD)
    ['slug'=>'lavado-planchado',    'display_name'=>'Lavado y Planchado',       'icon'=>'shirt',      'color'=>'#06B6D4', 'sort_order'=>21],
    ['slug'=>'mudanza-embalaje',    'display_name'=>'Embalaje y Mudanza',       'icon'=>'box',        'color'=>'#F59E0B', 'sort_order'=>22],
    ['slug'=>'agricultura-campo',   'display_name'=>'Agricultura y Campo',      'icon'=>'sprout',     'color'=>'#16A34A', 'sort_order'=>23],
    ['slug'=>'pesca-caza',          'display_name'=>'Pesca y Caza',             'icon'=>'fish',       'color'=>'#0284C7', 'sort_order'=>24],
    ['slug'=>'soldadura-metalurgia','display_name'=>'Soldadura y Metalurgia',   'icon'=>'flame',      'color'=>'#DC2626', 'sort_order'=>25],
    ['slug'=>'instalaciones',       'display_name'=>'Instalaciones (gas/agua)', 'icon'=>'pipette',    'color'=>'#7C3AED', 'sort_order'=>26],
    ['slug'=>'masajes-bienestar',   'display_name'=>'Masajes y Bienestar',      'icon'=>'hand',       'color'=>'#DB2777', 'sort_order'=>27],
    ['slug'=>'peluqueria-estetica', 'display_name'=>'Peluquería y Estética',    'icon'=>'scissors',   'color'=>'#9333EA', 'sort_order'=>28],
    ['slug'=>'contabilidad',        'display_name'=>'Contabilidad y Finanzas',  'icon'=>'calculator', 'color'=>'#0F766E', 'sort_order'=>29],
    ['slug'=>'transporte-escolar',  'display_name'=>'Transporte Escolar',       'icon'=>'bus',        'color'=>'#CA8A04', 'sort_order'=>30],
];

// Resetear secuencia al máximo ID actual
DB::statement("SELECT setval('categories_id_seq', (SELECT MAX(id) FROM categories) + 1)");

foreach ($new as $cat) {
    $exists = DB::table('categories')->where('slug', $cat['slug'])->exists();
    if (!$exists) {
        DB::statement("INSERT INTO categories (slug, display_name, icon, color, sort_order, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, true, NOW(), NOW())", [
            $cat['slug'], $cat['display_name'], $cat['icon'], $cat['color'], $cat['sort_order']
        ]);
        echo "Agregada: {$cat['display_name']}\n";
    } else {
        echo "Ya existe: {$cat['display_name']}\n";
    }
}
echo "Total: ".DB::table('categories')->count()." categorías\n";
