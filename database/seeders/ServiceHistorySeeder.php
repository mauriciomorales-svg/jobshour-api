<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ServiceHistorySeeder extends Seeder
{
    public function run(): void
    {
        // Obtener todos los workers
        $workers = DB::table('workers')->join('users', 'workers.user_id', '=', 'users.id')
            ->select('workers.id', 'workers.title', 'users.name')
            ->get();

        if ($workers->isEmpty()) {
            echo "⚠️ No hay workers para generar historial\n";
            return;
        }

        $services = [
            ['name' => 'Reparación de grifería', 'category' => 'Gasfitería', 'min' => 25000, 'max' => 65000],
            ['name' => 'Instalación eléctrica', 'category' => 'Electricidad', 'min' => 35000, 'max' => 95000],
            ['name' => 'Pintura de interiores', 'category' => 'Pintura', 'min' => 45000, 'max' => 150000],
            ['name' => 'Carpintería a medida', 'category' => 'Carpintería', 'min' => 55000, 'max' => 180000],
            ['name' => 'Jardinería y poda', 'category' => 'Jardinería', 'min' => 20000, 'max' => 50000],
            ['name' => 'Limpieza profunda', 'category' => 'Limpieza', 'min' => 25000, 'max' => 60000],
            ['name' => 'Instalación de cerámica', 'category' => 'Albañilería', 'min' => 45000, 'max' => 120000],
            ['name' => 'Reparación de cerraduras', 'category' => 'Cerrajería', 'min' => 15000, 'max' => 45000],
            ['name' => 'Arreglo de ropa', 'category' => 'Costura', 'min' => 8000, 'max' => 25000],
            ['name' => 'Paseo de mascotas', 'category' => 'Mascotas', 'min' => 10000, 'max' => 20000],
        ];

        $clients = [
            'María González', 'Pedro Soto', 'Ana Martínez', 'Carlos Ruiz', 'Laura Díaz',
            'José Morales', 'Carmen Vega', 'Roberto Flores', 'Diana Paredes', 'Luis Tapia',
            'Patricia Herrera', 'Diego Fuentes', 'Camila Navarro', 'Felipe Contreras', 'Elena Rivas'
        ];

        $reviews = [
            'Excelente trabajo, muy puntual y profesional',
            'Rápido y eficiente, recomendado 100%',
            'Buen trabajo, quedó muy bien la reparación',
            'Muy detallista, excelente acabado',
            'Mi jardín quedó hermoso, muchas gracias!',
            'Resolvió el problema en minutos, experto real',
            'Buen servicio, precio justo',
            'Muy profesional, dejó todo limpio y ordenado',
            'Super recomendado, volveré a contratar',
            'Trabajo de calidad, atención excelente',
            'Puntual y responsable, cumplió con todo',
            'Gran trabajo, excedió mis expectativas',
        ];

        $now = Carbon::now();
        $totalInserted = 0;

        foreach ($workers as $worker) {
            echo "\n📝 Generando historial para: {$worker->name}\n";

            for ($i = 0; $i < 10; $i++) {
                $service = $services[array_rand($services)];
                $client = $clients[array_rand($clients)];
                $review = $reviews[array_rand($reviews)];
                
                // Generar fecha aleatoria entre hace 3 meses y hoy
                $daysAgo = rand(5, 90);
                $date = $now->copy()->subDays($daysAgo);
                
                // 80% completados, 15% cancelados, 5% pendientes
                $rand = rand(1, 100);
                if ($rand <= 80) {
                    $status = 'completed';
                    $amount = rand($service['min'], $service['max']);
                    $rating = rand(4, 5);
                } elseif ($rand <= 95) {
                    $status = 'cancelled';
                    $amount = 0;
                    $rating = 0;
                    $review = 'Cancelado por el cliente';
                } else {
                    $status = 'pending';
                    $amount = rand($service['min'], $service['max']);
                    $rating = 0;
                    $review = null;
                }

                DB::table('service_requests')->insert([
                    'client_id' => 1, // Default client
                    'worker_id' => $worker->id,
                    'category_id' => rand(1, 10),
                    'description' => $service['name'] . ' para ' . $client,
                    'status' => $status,
                    'urgency' => rand(1, 100) > 70 ? 'urgent' : 'normal',
                    'offered_price' => $amount,
                    'final_price' => $status === 'completed' ? $amount : null,
                    'accepted_at' => $status !== 'pending' ? $date : null,
                    'completed_at' => $status === 'completed' ? $date->copy()->addHours(rand(1, 4)) : null,
                    'expires_at' => $date->copy()->addDays(7),
                    'created_at' => $date,
                    'updated_at' => $now,
                ]);

                $statusEmoji = $status === 'completed' ? '✅' : ($status === 'cancelled' ? '❌' : '⏳');
                echo "  {$statusEmoji} {$service['name']} - {$client} - \${$amount}\n";
                $totalInserted++;
            }
        }

        echo "\n✅ Historial generado: {$totalInserted} servicios para {$workers->count()} workers\n";
    }
}
