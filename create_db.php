<?php
try {
    $pdo = new PDO('pgsql:host=localhost;port=5432;dbname=postgres', 'postgres', 'postgres');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE DATABASE jobshour');
    echo "Base de datos 'jobshour' creada exitosamente\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'already exists') !== false) {
        echo "La base de datos ya existe\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
