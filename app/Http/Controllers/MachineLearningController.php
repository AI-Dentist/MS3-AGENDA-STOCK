<?php

namespace App\Http\Controllers;

use App\Application\UseCases\ClasificarStockUseCase;
use App\Application\UseCases\PredecirNoShowUseCase;
use App\Application\UseCases\SegmentarPacientesUseCase;
use App\Domain\Services\MlInferenceServiceInterface;
use App\Infrastructure\Services\MlSidecarUnavailableException;
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
            'cita_id' => 'sometimes|string',
            'paciente_id' => 'sometimes|string',
            'fecha_hora' => 'sometimes|string',
            'edad' => 'sometimes|integer|min:0|max:120',
            'dias_anticipacion' => 'sometimes|integer|min:0',
            'cancelaciones_previas' => 'sometimes|integer|min:0',
            'dia_semana' => 'sometimes|integer|between:1,7',
            'hora_cita' => 'sometimes|integer|between:7,22',
            'tipo_tratamiento_riesgo' => 'sometimes|integer|between:0,2',
            'duracion_minutos' => 'sometimes|integer|min:5|max:240',
            'duracion_estimada_min' => 'sometimes|integer|min:5|max:240',
            'tipo_tratamiento' => 'sometimes|string',
            'reservada_en' => 'sometimes|string',
        ]);

        try {
            $prediccion = $this->predecirNoShowUseCase->execute($request->all());

            return response()->json([
                'success' => true,
                'data' => $prediccion,
                'timestamp' => now()->toIso8601String(),
            ], 200);
        } catch (MlSidecarUnavailableException $e) {
            return response()->json([
                'success' => false,
                'code' => 'ML_SERVICE_UNAVAILABLE',
                'error' => 'El micro-sidecar de Machine Learning no está disponible: '.$e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ], 503);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'code' => 'ML_INFERENCE_ERROR',
                'error' => 'Error al ejecutar inferencia supervisada: '.$e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ], 500);
        }
    }

    public function clusters(): JsonResponse
    {
        try {
            $clusters = $this->mlService->getClusters();

            return response()->json([
                'success' => true,
                'data' => $clusters,
                'timestamp' => now()->toIso8601String(),
            ], 200);
        } catch (MlSidecarUnavailableException $e) {
            return response()->json([
                'success' => false,
                'code' => 'ML_SERVICE_UNAVAILABLE',
                'error' => 'El micro-sidecar de Machine Learning no está disponible: '.$e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ], 503);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'code' => 'ML_CLUSTERING_ERROR',
                'error' => 'Error al obtener clusters: '.$e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ], 500);
        }
    }

    public function clusterPatient(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'paciente_id' => 'sometimes|string',
            'total_citas' => 'required|integer|min:0',
            'tasa_asistencia' => 'required|numeric|between:0,1',
            'dias_promedio_entre_citas' => 'required|numeric|min:0',
            'gasto_total_usd' => 'sometimes|numeric|min:0',
            'gasto_total_estimado' => 'sometimes|numeric|min:0',
        ]);

        try {
            $segmentacion = $this->segmentarPacientesUseCase->execute($request->all());

            return response()->json([
                'success' => true,
                'data' => $segmentacion,
                'timestamp' => now()->toIso8601String(),
            ], 200);
        } catch (MlSidecarUnavailableException $e) {
            return response()->json([
                'success' => false,
                'code' => 'ML_SERVICE_UNAVAILABLE',
                'error' => 'El micro-sidecar de Machine Learning no está disponible: '.$e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ], 503);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'code' => 'ML_CLUSTERING_ERROR',
                'error' => 'Error al ejecutar clustering de pacientes: '.$e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ], 500);
        }
    }

    public function clusterStock(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'insumo_id' => 'sometimes|string',
            'consumo_mensual' => 'required|numeric|min:0',
            'costo_unitario' => 'required|numeric|min:0',
            'dias_reabastecimiento' => 'required|integer|min:0',
        ]);

        try {
            $clasificacion = $this->clasificarStockUseCase->execute($request->all());

            return response()->json([
                'success' => true,
                'data' => $clasificacion,
                'timestamp' => now()->toIso8601String(),
            ], 200);
        } catch (MlSidecarUnavailableException $e) {
            return response()->json([
                'success' => false,
                'code' => 'ML_SERVICE_UNAVAILABLE',
                'error' => 'El micro-sidecar de Machine Learning no está disponible: '.$e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ], 503);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'code' => 'ML_CLUSTERING_ERROR',
                'error' => 'Error al clasificar insumo de inventario: '.$e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ], 500);
        }
    }

    public function modelCard(): JsonResponse
    {
        try {
            $card = $this->mlService->getModelCard();

            return response()->json([
                'success' => true,
                'data' => $card,
                'timestamp' => now()->toIso8601String(),
            ], 200);
        } catch (MlSidecarUnavailableException $e) {
            return response()->json([
                'success' => false,
                'code' => 'ML_SERVICE_UNAVAILABLE',
                'error' => 'El micro-sidecar de Machine Learning no está disponible: '.$e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ], 503);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'code' => 'ML_MODEL_CARD_ERROR',
                'error' => 'Error al obtener model card: '.$e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ], 500);
        }
    }
}
