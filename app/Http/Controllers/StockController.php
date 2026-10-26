<?php

namespace App\Http\Controllers;

use App\Application\UseCases\ConsultarStockUseCase;
use Illuminate\Http\JsonResponse;

class StockController extends Controller
{
    public function index(ConsultarStockUseCase $useCase): JsonResponse
    {
        $insumos = $useCase->execute();
        $alertas = array_filter($insumos, fn($item) => $item['alerta_reposicion'] === true);

        return response()->json([
            'insumos' => array_values($insumos),
            'alertas_reposicion' => array_values($alertas),
            'total' => count($insumos)
        ], 200);
    }
}