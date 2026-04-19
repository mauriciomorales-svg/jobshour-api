# Una pasada del scheduler (equivalente a un "tick" de cron). Útil para probar en local.
Set-Location $PSScriptRoot\..
& php artisan schedule:run --no-interaction
