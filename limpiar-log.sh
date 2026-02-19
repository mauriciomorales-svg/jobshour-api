#!/bin/bash
echo "Limpiando log de Laravel..."
if [ -f "storage/logs/laravel.log" ]; then
    > storage/logs/laravel.log
    echo "Log limpiado exitosamente"
else
    echo "El archivo de log no existe"
fi
