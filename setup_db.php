<?php
try {
    $pdo = new PDO('pgsql:host=localhost;port=5432;dbname=jobshour', 'postgres', 'postgres');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create users table
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id SERIAL PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        email VARCHAR(255) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        nickname VARCHAR(50) NULL,
        avatar_url VARCHAR(255) NULL,
        phone VARCHAR(20) NULL,
        is_active BOOLEAN DEFAULT true,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "Tabla users creada\n";
    
    // Create workers table (sin PostGIS, usando lat/lng simples)
    $pdo->exec("CREATE TABLE IF NOT EXISTS workers (
        id SERIAL PRIMARY KEY,
        user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
        title VARCHAR(255) NULL,
        bio TEXT NULL,
        skills JSON NULL,
        hourly_rate DECIMAL(10,2) NULL,
        availability_status VARCHAR(20) DEFAULT 'offline' CHECK (availability_status IN ('offline', 'available', 'busy', 'active', 'intermediate', 'inactive')),
        last_seen_at TIMESTAMP NULL,
        lat DECIMAL(10, 8) NULL,
        lng DECIMAL(11, 8) NULL,
        location_accuracy DECIMAL(8,2) NULL,
        total_jobs_completed INTEGER DEFAULT 0,
        rating DECIMAL(2,1) DEFAULT 0.0,
        rating_count INTEGER DEFAULT 0,
        is_verified BOOLEAN DEFAULT false,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Create index for lat/lng
    $pdo->exec("CREATE INDEX IF NOT EXISTS workers_location_idx ON workers(lat, lng)");
    echo "Tabla workers creada\n";
    
    // Create categories table
    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
        id SERIAL PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        slug VARCHAR(255) UNIQUE NOT NULL,
        icon VARCHAR(50) NULL,
        color VARCHAR(7) DEFAULT '#2563EB',
        active_count INTEGER DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "Tabla categories creada\n";
    
    // Create nudges table
    $pdo->exec("CREATE TABLE IF NOT EXISTS nudges (
        id SERIAL PRIMARY KEY,
        message TEXT NOT NULL,
        type VARCHAR(20) DEFAULT 'top',
        weight INTEGER DEFAULT 50,
        is_active BOOLEAN DEFAULT true,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "Tabla nudges creada\n";
    
    // Insert sample categories
    $categories = [
        ['Plomería', 'plomeria', '🔧', '#3B82F6'],
        ['Electricidad', 'electricidad', '⚡', '#F59E0B'],
        ['Carpintería', 'carpinteria', '🪚', '#10B981'],
        ['Pintura', 'pintura', '🎨', '#8B5CF6'],
        ['Jardinería', 'jardineria', '🌱', '#22C55E'],
        ['Limpieza', 'limpieza', '🧹', '#EC4899'],
        ['Albañilería', 'albanileria', '🧱', '#F97316'],
        ['Cerrajería', 'cerrajeria', '🔐', '#6366F1']
    ];
    
    $stmt = $pdo->prepare("INSERT INTO categories (name, slug, icon, color) VALUES (?, ?, ?, ?) ON CONFLICT (slug) DO NOTHING");
    foreach ($categories as $cat) {
        $stmt->execute($cat);
    }
    echo "Categorías insertadas\n";
    
    // Insert sample users/workers
    $nicknames = ['ElMaestro', 'LaChispa', 'ElPintor', 'BrilloTotal', 'ManosDeMadera', 'LaVecinaPro', 'CerrajeroTop', 'HiloDeOro'];
    $names = ['Juan Pérez', 'María García', 'Pedro López', 'Ana Martínez', 'Carlos Rodríguez', 'Laura Sánchez', 'Diego Morales', 'Sofia Torres'];
    
    for ($i = 0; $i < 8; $i++) {
        // Insert user
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password, nickname) VALUES (?, ?, ?, ?) ON CONFLICT (email) DO NOTHING RETURNING id");
        $stmt->execute([$names[$i], 'worker' . $i . '@test.com', 'password123', $nicknames[$i]]);
        $userId = $stmt->fetchColumn();
        
        if ($userId) {
            // Insert worker with location near Renaico
            $lat = -37.6672 + (rand(-100, 100) / 1000);
            $lng = -72.5730 + (rand(-100, 100) / 1000);
            $price = rand(15000, 50000);
            $statuses = ['active', 'active', 'active', 'intermediate', 'inactive'];
            $status = $statuses[array_rand($statuses)];
            
            $stmt = $pdo->prepare("INSERT INTO workers (user_id, title, hourly_rate, availability_status, is_verified, rating, rating_count, lat, lng) 
                VALUES (?, ?, ?, ?, true, ?, ?, ?, ?)");
            $stmt->execute([
                $userId, 
                'Trabajador ' . $nicknames[$i],
                $price,
                $status,
                rand(30, 50) / 10,
                rand(5, 50),
                $lat,
                $lng
            ]);
        }
    }
    echo "Trabajadores insertados\n";
    
    // Insert nudges
    $nudges = [
        ['Conecta con trabajadores disponibles ahora', 'top', 60],
        ['Tu próximo servicio está a un clic', 'top', 60],
        ['Trabajadores verificados cerca de ti', 'top', 60],
        ['¿Necesitas ayuda? Hay expertos activos', 'refuerzo', 40],
        ['Encuentra trabajo local hoy', 'refuerzo', 40],
    ];
    
    $stmt = $pdo->prepare("INSERT INTO nudges (message, type, weight) VALUES (?, ?, ?)");
    foreach ($nudges as $nudge) {
        $stmt->execute($nudge);
    }
    echo "Nudges insertados\n";
    
    // Show counts
    $workersCount = $pdo->query("SELECT COUNT(*) FROM workers")->fetchColumn();
    $usersCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $catCount = $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
    
    echo "\n=== RESUMEN ===\n";
    echo "Usuarios: $usersCount\n";
    echo "Trabajadores: $workersCount\n";
    echo "Categorías: $catCount\n";
    echo "\n✅ Base de datos lista. Recarga la página en localhost:3002\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
