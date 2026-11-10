<?php

namespace App\Http\Controllers;

use App\Application\UseCases\ConsultarKpisUseCase;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class KpiController extends Controller
{
    public function __construct(
        private ConsultarKpisUseCase $consultarKpisUseCase
    ) {}

    public function index(Request $request): JsonResponse
    {
        $desde = $request->query('desde');
        $hasta = $request->query('hasta');

        // 1. Ambos parámetros son estrictamente requeridos
        if (empty($desde) || empty($hasta) || ! is_string($desde) || ! is_string($hasta)) {
            return response()->json([
                'success' => false,
                'code' => 'INVALID_PERIOD',
                'error' => "Los parámetros 'desde' y 'hasta' son obligatorios con formato YYYY-MM-DD.",
                'timestamp' => now()->toIso8601String(),
            ], 422);
        }

        // 2. Validación de formato YYYY-MM-DD y fechas de calendario reales
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
            return response()->json([
                'success' => false,
                'code' => 'INVALID_PERIOD',
                'error' => "Formato de fecha inválido. Utilice YYYY-MM-DD.",
                'timestamp' => now()->toIso8601String(),
            ], 422);
        }

        $tz = new DateTimeZone('America/La_Paz');
        $dtDesde = DateTimeImmutable::createFromFormat('!Y-m-d', $desde, $tz);
        $dtHasta = DateTimeImmutable::createFromFormat('!Y-m-d', $hasta, $tz);

        if (! $dtDesde || $dtDesde->format('Y-m-d') !== $desde || ! $dtHasta || $dtHasta->format('Y-m-d') !== $hasta) {
            return response()->json([
                'success' => false,
                'code' => 'INVALID_PERIOD',
                'error' => "Fecha de calendario inválida para 'desde' o 'hasta'.",
                'timestamp' => now()->toIso8601String(),
            ], 422);
        }

        // 3. Validación de orden cronológico: desde <= hasta
        if ($dtDesde > $dtHasta) {
            return response()->json([
                'success' => false,
                'code' => 'INVALID_PERIOD',
                'error' => "La fecha 'desde' no puede ser posterior a 'hasta'.",
                'timestamp' => now()->toIso8601String(),
            ], 422);
        }

        // 4. Validación de longitud de período (máximo 366 días inclusive)
        $diasInclusive = (int) $dtDesde->diff($dtHasta)->days + 1;
        if ($diasInclusive > 366) {
            return response()->json([
                'success' => false,
                'code' => 'PERIOD_TOO_LONG',
                'error' => 'El período no puede ser superior a 366 días.',
                'timestamp' => now()->toIso8601String(),
            ], 422);
        }

        try {
            $data = $this->consultarKpisUseCase->execute($desde, $hasta);

            return response()->json([
                'success' => true,
                'data' => $data,
                'timestamp' => now()->toIso8601String(),
            ], 200);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'code' => 'KPIS_CALCULATION_ERROR',
                'error' => 'Error al calcular los KPIs ejecutivos: '.$e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ], 500);
        }
    }
}
