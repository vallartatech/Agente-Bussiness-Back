#!/bin/bash

# Limpiar caché para evitar errores 500 por config obsoleta
echo "Limpiando caché de configuración..."
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

php artisan reverb:start --host=0.0.0.0 --port=6001 &
REVERB_PID=$!
echo "Started Reverb WebSocket on port 6001 (PID $REVERB_PID)"

PORT_TO_USE="${PORT:-8080}"
echo "Starting Laravel HTTP server on port $PORT_TO_USE"
exec php artisan serve --host=0.0.0.0 --port=$PORT_TO_USE
