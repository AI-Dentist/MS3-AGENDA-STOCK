<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CitaController;
use App\Http\Controllers\HorarioController;
use App\Http\Controllers\StockController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/health', function () {
    return response()->json([
        'status' => 'UP',
        'microservicio' => 'MS3 - Agenda & Stock',
        'timestamp' => now()
    ]);
});

Route::prefix('api/agendamiento')->group(function () {
    // 1. Horarios de Médicos
    Route::get('/horarios', [HorarioController::class, 'index']);

    // 2. Agenda y Citas
    Route::get('/citas', [CitaController::class, 'index']);
    Route::post('/citas', [CitaController::class, 'store']);

    // 3. Inventario y Stock
    Route::get('/stock', [StockController::class, 'index']);
});