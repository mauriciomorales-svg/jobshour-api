# start-queue-worker.ps1
# Inicia el queue worker de Laravel para procesar jobs async (broadcasts, notificaciones, etc.)
# Ejecutar en una terminal separada: .\start-queue-worker.ps1

Write-Host "🚀 Iniciando Queue Worker - JobsHour API" -ForegroundColor Green
Write-Host "   Procesa: broadcasts, FCM push, emails" -ForegroundColor Gray
Write-Host "   Ctrl+C para detener`n" -ForegroundColor Gray

php artisan queue:work --tries=3 --timeout=60 --sleep=3 --max-jobs=500
