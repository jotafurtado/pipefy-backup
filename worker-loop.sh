#!/usr/bin/env bash
# Loop de reciclagem do worker de fila para Linux / macOS / Docker.
# Reinicia o queue:work a cada 500 jobs ou 30 min, evitando acumulo de memoria.
# Uso: ./worker-loop.sh <nome-do-worker>
WORKER_NAME="${1:-worker}"

while true; do
    php -d memory_limit=512M artisan queue:work redis --name="$WORKER_NAME" --timeout=0 --sleep=3 --tries=3 --memory=512 --max-jobs=500 --max-time=1800
    sleep 5
done
