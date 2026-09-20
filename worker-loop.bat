@echo off
REM Loop de reciclagem do worker de fila (Windows, sem Supervisor/Horizon).
REM Reinicia o queue:work a cada 500 jobs ou 30 min, antes que a memoria acumule.
REM Uso: worker-loop.bat <nome-do-worker>
set WORKER_NAME=%1
if "%WORKER_NAME%"=="" set WORKER_NAME=worker
:loop
php -d memory_limit=512M artisan queue:work redis --name=%WORKER_NAME% --timeout=0 --sleep=3 --tries=3 --memory=512 --max-jobs=500 --max-time=1800
timeout /t 5 /nobreak >nul
goto loop
