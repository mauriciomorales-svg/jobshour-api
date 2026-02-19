<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Category;

// Verificar categorías existentes
$existing = Category::pluck('display_name')->toArray();
echo "Categorías existentes:\n";
foreach ($existing as $name) {
    echo "  - $name\n";
}

// 5 nuevas categorías
$newCategories = [
    ['display_name' => 'Jardinería y Paisajismo', 'slug' => 'jardineria', 'icon' => 'leaf', 'color' => '#22c55e', 'is_active' => true, 'sort_order' => 10],
    ['display_name' => 'Reparaciones Electrodomésticos', 'slug' => 'electrodomesticos', 'icon' => 'zap', 'color' => '#f59e0b', 'is_active' => true, 'sort_order' => 11],
    ['display_name' => 'Pintura y Decoración', 'slug' => 'pintura', 'icon' => 'paintbrush', 'color' => '#8b5cf6', 'is_active' => true, 'sort_order' => 12],
    ['display_name' => 'Mudanzas y Transporte', 'slug' => 'mudanzas', 'icon' => 'truck', 'color' => '#3b82f6', 'is_active' => true, 'sort_order' => 13],
    ['display_name' => 'Cuidado de Mascotas', 'slug' => 'mascotas', 'icon' => 'paw-print', 'color' => '#ec4899', 'is_active' => true, 'sort_order' => 14],
];

echo "\nAgregando nuevas categorías...\n";

$added = 0;
foreach ($newCategories as $cat) {
    try {
        // Verificar si existe por slug (que tiene unique constraint)
        $existingCat = Category::where('slug', $cat['slug'])->first();
        if ($existingCat) {
            echo "  ⚠️ {$cat['display_name']} ya existe (slug: {$cat['slug']})\n";
            continue;
        }
        Category::create($cat);
        echo "  ✅ {$cat['display_name']}\n";
        $added++;
    } catch (Exception $e) {
        echo "  ⚠️ {$cat['display_name']} - " . $e->getMessage() . "\n";
    }
}

echo "\n{$added} categorías agregadas.\n";
