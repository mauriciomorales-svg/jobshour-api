# Script para limpiar logs de Laravel
# Ejecutar desde PowerShell: .\clear-logs.ps1

$logPath = "storage\logs\laravel.log"

if (Test-Path $logPath) {
    Clear-Content $logPath
    Write-Host "✅ Logs de Laravel limpiados" -ForegroundColor Green
} else {
    Write-Host "⚠️ Archivo de log no encontrado: $logPath" -ForegroundColor Yellow
}

# También limpiar otros logs si existen
$otherLogs = @(
    "storage\logs\*.log"
)

Get-ChildItem -Path "storage\logs" -Filter "*.log" -ErrorAction SilentlyContinue | ForEach-Object {
    Clear-Content $_.FullName
    Write-Host "✅ Limpiado: $($_.Name)" -ForegroundColor Green
}

Write-Host "`n📋 Logs limpiados correctamente" -ForegroundColor Cyan
