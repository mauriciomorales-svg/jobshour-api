<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ExperienceSuggestionsSeeder extends Seeder
{
    public function run(): void
    {
        $suggestions = [
            ['title' => 'Electricista residencial', 'category' => 'Electricidad', 'icon' => '⚡'],
            ['title' => 'Electricista industrial', 'category' => 'Electricidad', 'icon' => '⚡'],
            ['title' => 'Instalación de paneles solares', 'category' => 'Electricidad', 'icon' => '⚡'],
            ['title' => 'Gasfiter domiciliario', 'category' => 'Gasfitería', 'icon' => '🔧'],
            ['title' => 'Instalación de cañerías', 'category' => 'Gasfitería', 'icon' => '🔧'],
            ['title' => 'Reparación de calefones', 'category' => 'Gasfitería', 'icon' => '🔧'],
            ['title' => 'Pintor de casas', 'category' => 'Pintura', 'icon' => '🎨'],
            ['title' => 'Pintor de edificios', 'category' => 'Pintura', 'icon' => '🎨'],
            ['title' => 'Empapelado y decoración', 'category' => 'Pintura', 'icon' => '🎨'],
            ['title' => 'Carpintero de muebles', 'category' => 'Carpintería', 'icon' => '🪵'],
            ['title' => 'Carpintero de obra', 'category' => 'Carpintería', 'icon' => '🪵'],
            ['title' => 'Restauración de muebles', 'category' => 'Carpintería', 'icon' => '🪵'],
            ['title' => 'Jardinero de mantención', 'category' => 'Jardinería', 'icon' => '🌿'],
            ['title' => 'Diseño de jardines', 'category' => 'Jardinería', 'icon' => '🌿'],
            ['title' => 'Poda de árboles', 'category' => 'Jardinería', 'icon' => '🌿'],
            ['title' => 'Aseo de casas', 'category' => 'Aseo', 'icon' => '🧹'],
            ['title' => 'Aseo de oficinas', 'category' => 'Aseo', 'icon' => '🧹'],
            ['title' => 'Limpieza profunda', 'category' => 'Aseo', 'icon' => '🧹'],
            ['title' => 'Cerrajero 24 horas', 'category' => 'Cerrajería', 'icon' => '🔑'],
            ['title' => 'Instalación de cerraduras', 'category' => 'Cerrajería', 'icon' => '🔑'],
            ['title' => 'Duplicado de llaves', 'category' => 'Cerrajería', 'icon' => '🔑'],
            ['title' => 'Albañil de construcción', 'category' => 'Construcción', 'icon' => '🧱'],
            ['title' => 'Albañil de remodelación', 'category' => 'Construcción', 'icon' => '🧱'],
            ['title' => 'Maestro de obra', 'category' => 'Construcción', 'icon' => '🧱'],
            ['title' => 'Costurera de ropa', 'category' => 'Costura', 'icon' => '🧵'],
            ['title' => 'Arreglos de ropa', 'category' => 'Costura', 'icon' => '🧵'],
            ['title' => 'Confección de cortinas', 'category' => 'Costura', 'icon' => '🧵'],
            ['title' => 'Paseador de perros', 'category' => 'Mascotas', 'icon' => '🐾'],
            ['title' => 'Cuidador de mascotas', 'category' => 'Mascotas', 'icon' => '🐾'],
            ['title' => 'Peluquería canina', 'category' => 'Mascotas', 'icon' => '🐾'],
            ['title' => 'Conductor de traslados', 'category' => 'Movilidad', 'icon' => '🚗'],
            ['title' => 'Conductor de delivery', 'category' => 'Movilidad', 'icon' => '🚗'],
            ['title' => 'Conductor de mudanzas', 'category' => 'Movilidad', 'icon' => '🚗'],
            ['title' => 'Mandados y compras', 'category' => 'Mandados', 'icon' => '🛍️'],
            ['title' => 'Trámites y gestiones', 'category' => 'Mandados', 'icon' => '🛍️'],
            ['title' => 'Envío de paquetes', 'category' => 'Mandados', 'icon' => '📦'],
            ['title' => 'Chef de eventos', 'category' => 'Cocina', 'icon' => '🍳'],
            ['title' => 'Cocinero de comida casera', 'category' => 'Cocina', 'icon' => '🍳'],
            ['title' => 'Repostería y pasteles', 'category' => 'Cocina', 'icon' => '🍳'],
            ['title' => 'Técnico en computación', 'category' => 'Tecnología', 'icon' => '💻'],
            ['title' => 'Reparación de celulares', 'category' => 'Tecnología', 'icon' => '📱'],
            ['title' => 'Instalación de redes', 'category' => 'Tecnología', 'icon' => '💻'],
            ['title' => 'Mecánico automotriz', 'category' => 'Mecánica', 'icon' => '🔧'],
            ['title' => 'Mecánico de motos', 'category' => 'Mecánica', 'icon' => '🏍️'],
            ['title' => 'Mecánico de bicicletas', 'category' => 'Mecánica', 'icon' => '🚲'],
            ['title' => 'Soldador', 'category' => 'Soldadura', 'icon' => '🔥'],
            ['title' => 'Herrería y portones', 'category' => 'Herrería', 'icon' => '⚒️'],
            ['title' => 'Vidriería', 'category' => 'Vidriería', 'icon' => '🪟'],
            ['title' => 'Instalación de pisos', 'category' => 'Pisos', 'icon' => '🏠'],
            ['title' => 'Instalación de cerámica', 'category' => 'Cerámica', 'icon' => '🏠'],
        ];

        foreach ($suggestions as $suggestion) {
            DB::table('experience_suggestions')->insert([
                'title' => $suggestion['title'],
                'category' => $suggestion['category'],
                'icon' => $suggestion['icon'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
