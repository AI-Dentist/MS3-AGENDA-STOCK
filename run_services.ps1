# Script de inicio local para MS3 (Laravel Backend + FastAPI ML Sidecar)

Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "Iniciando MS3: Agenda, Stock & Machine Learning Sidecar" -ForegroundColor Cyan
Write-Host "==========================================================" -ForegroundColor Cyan

# 1. Asegurar modelos de ML entrenados
if (-not (Test-Path "ml_service\models\no_show_pipeline.joblib")) {
    Write-Host "[1/3] Entrenando modelos de Machine Learning..." -ForegroundColor Yellow
    python ml_service\train_models.py
}

# 2. Iniciar el Sidecar de FastAPI en background o nueva ventana
Write-Host "[2/3] Iniciando Micro-Sidecar FastAPI en http://127.0.0.1:8001 ..." -ForegroundColor Green
$mlProcess = Start-Process python -ArgumentList "-m uvicorn ml_service.main:app --port 8001 --host 127.0.0.1" -PassThru

# 3. Iniciar el servidor Laravel
Write-Host "[3/3] Iniciando Laravel Backend en http://127.0.0.1:8000 ..." -ForegroundColor Green
Write-Host "Presiona Ctrl+C para detener." -ForegroundColor Gray

try {
    php artisan serve --port 8000
} finally {
    if ($mlProcess -and -not $mlProcess.HasExited) {
        Write-Host "Deteniendo FastAPI Sidecar (PID: $($mlProcess.Id))..." -ForegroundColor Yellow
        Stop-Process -Id $mlProcess.Id -Force
    }
}
