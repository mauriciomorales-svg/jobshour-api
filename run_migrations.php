<?php
require 'vendor/autoload.php';

// Include necessary service providers for migrations
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Database\MigrationServiceProvider;

$app = require 'bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

// Register database and migration service providers
$app->register(DatabaseServiceProvider::class);
$app->register(MigrationServiceProvider::class);

try {
    // Run migrations
    $migrator = $app->make('migrator');
    $migrator->run(database_path('migrations'));
    echo "Migraciones ejecutadas exitosamente\n";
    
    // Show migrated tables
    $tables = $app['db']->select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
    echo "\nTablas creadas:\n";
    foreach ($tables as $table) {
        echo " - " . $table->tablename . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}
