<?php
try {
    $pdo = new PDO('pgsql:host=localhost;port=5432;dbname=jobshour', 'postgres', 'postgres');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check if workers table exists
    $stmt = $pdo->query("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'workers')");
    $tableExists = $stmt->fetchColumn();
    
    if (!$tableExists) {
        echo "Tabla 'workers' NO existe. Necesitas ejecutar migraciones.\n";
        exit(1);
    }
    
    // Count workers
    $stmt = $pdo->query("SELECT COUNT(*) FROM workers");
    $count = $stmt->fetchColumn();
    echo "Trabajadores en BD: " . $count . "\n";
    
    if ($count == 0) {
        echo "La tabla existe pero está vacía. Necesitas ejecutar seeders.\n";
    }
} catch (PDOException $e) {
    echo "Error BD: " . $e->getMessage() . "\n";
}
