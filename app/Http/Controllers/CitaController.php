<?php

namespace App\Http\Controllers;

use App\Application\UseCases\AgendarCitaUseCase;
use App\Application\UseCases\CancelarCitaUseCase;
use App\Application\UseCases\CompletarCitaYDescontarStockUseCase;
use App\Application\UseCases\ReprogramarCitaUseCase;
use App\Domain\Entities\Cita;
use App\Domain\Exceptions\CitaCollisionException;
use App\Domain\Repositories\CitaRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

class CitaController extends Controller
{
    public function __construct(
        private AgendarCitaUseCase $agendarCitaUseCase,
        private CancelarCitaUseCase $cancelarCitaUseCase,
        private ReprogramarCitaUseCase $reprogramarCitaUseCase,
        private CitaRepositoryInterface $citaRepository
    ) {}

    public function index(): JsonResponse
    {
        $citas = $this->citaRepository->findAll();
        $citasArray = array_map(fn ($cita) => $cita->toArray(), $citas);

        return response()->json([
            'success' => true,
            'data' => [
                'citas' => $citasArray,
                'total' => count($citasArray),
            ],
            'citas' => $citasArray,
            'total' => count($citasArray),
            'timestamp' => now()->toIso8601String(),
        ], 200);
    }

    public function store(Request $request): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');

        if (! $idempotencyKey) {
            return response()->json([
                'success' => false,
                'error' => 'El encabezado Idempotency-Key es obligatorio.',
                'code' => 'MISSING_IDEMPOTENCY_KEY',
                'timestamp' => now()->toIso8601String(),
            ], 400);
        }

        $validated = $request->validate([
            'odontologo_id' => 'required|string',
            'consultorio_id' => 'required|string',
            'paciente_id' => 'required|string',
            'fecha_hora' => 'required|string',
        ]);

        try {
            $resultado = $this->agendarCitaUseCase->execute($validated, $idempotencyKey);

            return response()->json([
                'success' => true,
                'data' => $resultado['cita'],
                'idempotente' => $resultado['idempotente'],
                'mensaje' => $resultado['mensaje'],
                'timestamp' => now()->toIso8601String(),
            ], $resultado['idempotente'] ? 200 : 201);
        } catch (CitaCollisionException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'code' => 'SCHEDULE_COLLISION',
                'timestamp' => now()->toIso8601String(),
            ], 409);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'code' => 'INVALID_APPOINTMENT_DATA',
                'timestamp' => now()->toIso8601String(),
            ], 422);
        }
    }

    public function cancelar(string $id): JsonResponse
    {
        try {
            $resultado = $this->cancelarCitaUseCase->execute($id);

            return response()->json([
                'success' => true,
                'data' => $resultado['cita'],
                'mensaje' => $resultado['mensaje'],
                'timestamp' => now()->toIso8601String(),
            ], 200);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'code' => 'NOT_FOUND',
                'timestamp' => now()->toIso8601String(),
            ], 404);
        }
    }

    public function reprogramar(string $id, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nueva_fecha_hora' => 'required|string',
        ]);

        try {
            $resultado = $this->reprogramarCitaUseCase->execute($id, $validated['nueva_fecha_hora']);

            return response()->json([
                'success' => true,
                'data' => $resultado['cita'],
                'mensaje' => $resultado['mensaje'],
                'timestamp' => now()->toIso8601String(),
            ], 200);
        } catch (CitaCollisionException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'code' => 'SCHEDULE_COLLISION',
                'timestamp' => now()->toIso8601String(),
            ], 409);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'code' => 'INVALID_OPERATION',
                'timestamp' => now()->toIso8601String(),
            ], 400);
        }
    }

    public function completar(string $id, Request $request, CompletarCitaYDescontarStockUseCase $useCase): JsonResponse
    {
        $insumos = $request->input('insumos', []);
        $motivo = $request->input('motivo');
        $usuarioId = $request->attributes->get('user_id') ?? $request->header('X-User-Id');

        try {
            $resultado = $useCase->execute($id, $insumos, $usuarioId, $motivo);

            return response()->json([
                'success' => true,
                'data' => $resultado['cita'],
                'insumos_descontados' => $resultado['insumos_descontados'],
                'movimientos' => $resultado['movimientos'],
                'mensaje' => $resultado['mensaje'],
                'timestamp' => now()->toIso8601String(),
            ], 200);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'code' => 'COMPLETION_ERROR',
                'timestamp' => now()->toIso8601String(),
            ], 400);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => 'Error al completar cita y descontar inventario: '.$e->getMessage(),
                'code' => 'INTERNAL_TRANSACTION_ERROR',
                'timestamp' => now()->toIso8601String(),
            ], 500);
        }
    }

    public function noAsistio(string $id): JsonResponse
    {
        $cita = $this->citaRepository->findById($id);
        if (! $cita) {
            return response()->json([
                'success' => false,
                'error' => "Cita no encontrada con ID: {$id}.",
                'code' => 'NOT_FOUND',
                'timestamp' => now()->toIso8601String(),
            ], 404);
        }

        try {
            $cita->marcarNoAsistio();
            $this->citaRepository->updateEstado($id, Cita::ESTADO_NO_ASISTIO);

            return response()->json([
                'success' => true,
                'data' => $cita->toArray(),
                'mensaje' => 'Cita marcada como NO ASISTIÓ exitosamente.',
                'timestamp' => now()->toIso8601String(),
            ], 200);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'code' => 'INVALID_STATE_TRANSITION',
                'timestamp' => now()->toIso8601String(),
            ], 400);
        }
    }
}
