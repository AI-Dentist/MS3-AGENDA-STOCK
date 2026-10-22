<?php

use Illuminate\Support\Facades\Route;

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

use App\Http\Controllers\CitaController;
Route::post('/citas', [CitaController::class, 'store']);
