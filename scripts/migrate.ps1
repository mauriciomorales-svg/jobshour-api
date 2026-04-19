# Ejecutar desde la raíz del API: .\scripts\migrate.ps1
Set-Location $PSScriptRoot\..
& php artisan migrate --force --no-interaction
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
Write-Host "OK: php artisan migrate --force"
