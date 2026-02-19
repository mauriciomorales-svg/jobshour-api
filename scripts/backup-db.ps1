# JobsHours Database Backup Script
# Ejecutar con: powershell -ExecutionPolicy Bypass -File scripts\backup-db.ps1
# Programar en Task Scheduler para backups diarios

$timestamp = Get-Date -Format "yyyy-MM-dd_HH-mm"
$backupDir = "C:\wamp64\www\BACKUPS\jobshour"
$backupFile = "$backupDir\jobshour_$timestamp.sql"

# Crear directorio si no existe
if (!(Test-Path $backupDir)) { New-Item -ItemType Directory -Path $backupDir -Force }

# Dump via Docker
docker exec jobshour-db pg_dump -U jobshour -d jobshour --no-owner --no-acl > $backupFile

if ($LASTEXITCODE -eq 0) {
    $size = (Get-Item $backupFile).Length / 1MB
    Write-Host "[OK] Backup creado: $backupFile ($([math]::Round($size, 2)) MB)"
    
    # Comprimir
    Compress-Archive -Path $backupFile -DestinationPath "$backupFile.zip" -Force
    Remove-Item $backupFile
    Write-Host "[OK] Comprimido: $backupFile.zip"
    
    # Limpiar backups antiguos (mantener ultimos 30)
    Get-ChildItem $backupDir -Filter "*.zip" | Sort-Object LastWriteTime -Descending | Select-Object -Skip 30 | Remove-Item -Force
    Write-Host "[OK] Limpieza completada (max 30 backups)"
} else {
    Write-Host "[ERROR] Fallo al crear backup"
    exit 1
}
