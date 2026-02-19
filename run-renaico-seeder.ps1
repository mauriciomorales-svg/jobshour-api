# Script para ejecutar el seeder de Renaico
Write-Host "🧹 Ejecutando seeder de Renaico..." -ForegroundColor Cyan

cd c:\wamp64\www\jobshour-api

# Limpiar cache
php artisan cache:clear
php artisan config:clear

# Ejecutar seeder
php artisan db:seed --class=RenaicoTestSeeder

Write-Host ""
Write-Host "✅ Seeder completado!" -ForegroundColor Green
Write-Host ""
Write-Host "🔑 Credenciales de prueba:" -ForegroundColor Yellow
Write-Host "   Mauricio: mauricio.morales@usach.cl / password123"
Write-Host "   Isabel: comercialisabel2020@gmail.com / password123"
Write-Host ""
