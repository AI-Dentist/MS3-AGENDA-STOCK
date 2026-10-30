<?php

namespace App\Http\Controllers;

use App\Application\UseCases\ClasificarStockUseCase;
use App\Application\UseCases\PredecirNoShowUseCase;
use App\Application\UseCases\SegmentarPacientesUseCase;
use App\Domain\Services\MlInferenceServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class MachineLearningController extends Controller
{
    public function __construct(
        private PredecirNoShowUseCase $predecirNoShowUseCase,
        private SegmentarPacientesUseCase $segmentarPacientesUseCase,
        private ClasificarStockUseCase $clasificarStockUseCase,
        private MlInferenceServiceInterface $mlService
    ) {}

    public function health(): JsonResponse
    {
        $health = $this->mlService->checkHealth();
        $statusCode = ($health['status'] ?? '') === 'UP' ? 200 : 503;

        return response()->json([
            'success' => $statusCode === 200,
            'data' => $health,
            'timestamp' => now()->toIso8601String(),
        ], $statusCode);
    }

    public function predictNoShow(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'edad' => 'required|integer|min:0|max:120',
            'dias_anticipacion' => 'required|integer|min:0',
            'cancelaciones_previas' => 'required|integer|min:0',
            'dia_semana' => 'required|integer|between:1,7',
            'hora_cita' => 'required|integer|between:7,22',
            'tipo_tratamiento_riesgo' => 'required|integer|between:0,2',
        ]);

        try {
            $prediccion = $this->predecirNoShowUseCase->execute($validated);

            return response()->json([
                'success' => true,
                'data' => $prediccion,
                'timestamp' => now()->toIso8601String(),
            ], 200);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => 'Error al ejecutar inferencia supervisada: '.$e->getMessage(),
                'code' => 'ML_INFERENCE_ERROR',
                'timestamp' => now()->toIso8601String(),
            ], 500);
        }
    }

    public function clusterPatient(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'total_citas' => 'required|integer|min:0',
            'tasa_asistencia' => 'required|numeric|between:0,1',
            'dias_promedio_entre_citas' => 'required|numeric|min:0',
            'gasto_total_usd' => 'required|numeric|min:0',
        ]);

        try {
            $segmentacion = $this->segmentarPacientesUseCase->execute($validated);

            return response()->json([
                'success' => true,
                'data' => $segmentacion,
                'timestamp' => now()->toIso8601String(),
            ], 200);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => 'Error al ejecutar clustering de pacientes: '.$e->getMessage(),
                'code' => 'ML_CLUSTERING_ERROR',
                'timestamp' => now()->toIso8601String(),
            ], 500);
        }
    }

    public function clusterStock(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'consumo_mensual' => 'required|numeric|min:0',
            'costo_unitario' => 'required|numeric|min:0',
            'dias_reabastecimiento' => 'required|integer|min:0',
        ]);

        try {
            $clasificacion = $this->clasificarStockUseCase->execute($validated);

            return response()->json([
                'success' => true,
                'data' => $clasificacion,
                'timestamp' => now()->toIso8601String(),
            ], 200);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => 'Error al clasificar insumo: '.$e->getMessage(),
                'code' => 'ML_STOCK_CLUSTERING_ERROR',
                'timestamp' => now()->toIso8601String(),
            ], 500);
        }
    }
}
