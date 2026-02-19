@echo off
echo Limpiando log de Laravel...
if exist "storage\logs\laravel.log" (
    type nul > "storage\logs\laravel.log"
    echo Log limpiado exitosamente
) else (
    echo El archivo de log no existe
)
pause
