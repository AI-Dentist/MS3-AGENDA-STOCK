<?php

use App\Http\Controllers\CitaController;
use App\Http\Controllers\HorarioController;
use App\Http\Controllers\KpiController;
use App\Http\Controllers\MachineLearningController;
use App\Http\Controllers\StockController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/health', function () {
    return response()->json([
        'success' => true,
        'status' => 'UP',
        'microservicio' => 'MS3 - Agenda & Stock & Machine Learning',
        'timestamp' => now()->toIso8601String(),
    ]);
});

Route::prefix('api/agendamiento')->middleware('auth.keycloak')->group(function () {
    // 1. Horarios de Médicos y Disponibilidad
    Route::get('/horarios', [HorarioController::class, 'index']);
    Route::post('/horarios', [HorarioController::class, 'store'])->middleware('auth.keycloak:ADMIN');

    // 2. Agenda y Citas Odontológicas (CRUD Completo y Ciclo Clínico)
    Route::middleware('auth.keycloak:ADMIN,RECEPCIONISTA,ODONTOLOGO')->group(function () {
        Route::get('/citas', [CitaController::class, 'index']);
        Route::get('/citas/{id}', [CitaController::class, 'show']);
        Route::post('/citas', [CitaController::class, 'store']);
        Route::patch('/citas/{id}/cancelar', [CitaController::class, 'cancelar']);
        Route::put('/citas/{id}/reprogramar', [CitaController::class, 'reprogramar']);
        Route::patch('/citas/{id}/completar', [CitaController::class, 'completar']);
        Route::patch('/citas/{id}/no-asistio', [CitaController::class, 'noAsistio']);
    });

    // 3. Inventario, Kardex y Control de Stock
    Route::middleware('auth.keycloak:ADMIN,ODONTOLOGO,ASISTENTE,RECEPCIONISTA')->group(function () {
        Route::get('/stock', [StockController::class, 'index']);
        Route::post('/stock/movimientos', [StockController::class, 'registrarMovimiento']);
        Route::get('/stock/{id}/kardex', [StockController::class, 'kardex']);
    });

    // 4. Módulo de Machine Learning (Supervisado y No Supervisado)
    Route::prefix('ml')->group(function () {
        Route::get('/health', [MachineLearningController::class, 'health']);
        Route::post('/predict-noshow', [MachineLearningController::class, 'predictNoShow']);
        Route::post('/predict-no-show', [MachineLearningController::class, 'predictNoShow']);
        Route::get('/clusters', [MachineLearningController::class, 'clusters']);
        Route::get('/model-card', [MachineLearningController::class, 'modelCard']);
        Route::get('/model_card', [MachineLearningController::class, 'modelCard']);
        Route::post('/cluster-paciente', [MachineLearningController::class, 'clusterPatient']);
        Route::post('/cluster-stock', [MachineLearningController::class, 'clusterStock']);
    });

    // 5. KPIs Ejecutivos (S3 & S6) - Restringido a Administradores
    Route::get('/kpis', [KpiController::class, 'index'])->middleware('auth.keycloak:ADMIN');
});
