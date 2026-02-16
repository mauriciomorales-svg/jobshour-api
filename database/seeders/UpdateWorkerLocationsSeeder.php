<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UpdateWorkerLocationsSeeder extends Seeder
{
    public function run(): void
    {
        // Distribución optimizada: Equilibrio Este-Oeste sin saturación lineal
        $locations = [
            // Noroeste - Área verde/calles superiores (Patricia arriba, Roberto a la derecha)
            ['email' => 'juan.perez@jobshour.cl', 'lat' => -37.6708, 'lng' => -72.5945],
            ['email' => 'patricia.herrera@jobshour.cl', 'lat' => -37.6685, 'lng' => -72.5955],  // Más arriba (noroeste)
            ['email' => 'roberto.munoz@jobshour.cl', 'lat' => -37.6698, 'lng' => -72.5920],  // Desplazado a la derecha, cerca línea férrea
            
            // Noreste - Población Oklahoma (Marta desplazada a la derecha)
            ['email' => 'marta.soto@jobshour.cl', 'lat' => -37.6705, 'lng' => -72.5830],
            ['email' => 'carlos.torres@jobshour.cl', 'lat' => -37.6712, 'lng' => -72.5870],
            
            // Suroeste - Enlace Renaico (Felipe desplazado al suroeste)
            ['email' => 'elena.rivas@jobshour.cl', 'lat' => -37.6745, 'lng' => -72.5930],
            ['email' => 'diego.fuentes@jobshour.cl', 'lat' => -37.6765, 'lng' => -72.5910],  // Avenida Lavanderos (zig-zag)
            ['email' => 'felipe.contreras@jobshour.cl', 'lat' => -37.6775, 'lng' => -72.5940],  // Enlace Renaico (suroeste)
            
            // Sureste - Calle Estadio/Los Olmos (Andrea desplazada al sureste)
            ['email' => 'andrea.lopez@jobshour.cl', 'lat' => -37.6740, 'lng' => -72.5820],
            
            // Extremo Este - Cornelio Olsen (Camila desplazada al extremo derecho)
            ['email' => 'camila.navarro@jobshour.cl', 'lat' => -37.6755, 'lng' => -72.5790],
        ];

        foreach ($locations as $loc) {
            $user = DB::table('users')->where('email', $loc['email'])->first();
            if ($user) {
                DB::statement(
                    "UPDATE workers SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE user_id = ?",
                    [$loc['lng'], $loc['lat'], $user->id]
                );
                echo "✓ Actualizado: {$user->name} -> ({$loc['lat']}, {$loc['lng']})\n";
            }
        }

        echo "\n✅ Ubicaciones de workers actualizadas a coordenadas reales de calles de Renaico\n";
    }
}
