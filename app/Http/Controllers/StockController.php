<?php

namespace App\Http\Controllers;

use App\Application\UseCases\ConsultarStockUseCase;
use App\Application\UseCases\RegistrarMovimientoStockUseCase;
use App\Domain\Exceptions\StockMovementException;
use App\Domain\Repositories\InsumoRepositoryInterface;
use App\Domain\Repositories\MovimientoStockRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockController extends Controller
{
    public function __construct(
        private InsumoRepositoryInterface $insumoRepository,
        private MovimientoStockRepositoryInterface $movimientoRepository
    ) {}

    public function index(ConsultarStockUseCase $useCase): JsonResponse
    {
        $insumos = $useCase->execute();
        $alertas = array_filter($insumos, fn ($item) => $item['alerta_reposicion'] === true);

        return response()->json([
            'success' => true,
            'data' => [
                'insumos' => array_values($insumos),
                'alertas_reposicion' => array_values($alertas),
                'total' => count($insumos),
            ],
            'insumos' => array_values($insumos),
            'alertas_reposicion' => array_values($alertas),
            'total' => count($insumos),
            'timestamp' => now()->toIso8601String(),
        ], 200);
    }

    public function registrarMovimiento(Request $request, RegistrarMovimientoStockUseCase $useCase): JsonResponse
    {
        $key1 = $request->header('Idempotency-Key');
        $key2 = $request->header('X-Idempotency-Key');
        if ($key1 !== null && $key2 !== null && $key1 !== $key2) {
            return response()->json([
                'success' => false,
                'code' => 'CONTRADICTORY_IDEMPOTENCY_KEYS',
                'error' => 'Los encabezados Idempotency-Key y X-Idempotency-Key contienen valores contradictorios.',
                'timestamp' => now()->toIso8601String(),
            ], 400);
        }

        $rules = [
            'insumo_id' => 'required|string',
            'tipo_movimiento' => 'required|string|in:ENTRADA_COMPRA,SALIDA_PROCEDIMIENTO,AJUSTE',
            'cantidad' => 'required|integer|min:1',
            'motivo' => 'required|string',
            'usuario_id' => 'nullable|string',
        ];
        if ($request->input('tipo_movimiento') === 'AJUSTE' && $request->has('direccion')) {
            $rules['direccion'] = 'string';
        }

        $validated = $request->validate($rules);
        if ($request->has('direccion') && is_string($request->input('direccion'))) {
            $validated['direccion'] = $request->input('direccion');
        }

        try {
            $result = $useCase->execute(
                $validated,
                $key1 ?? $key2,
                $request->attributes->get('user_id')
            );

            return response()->json($result['body'], $result['status']);
        } catch (StockMovementException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'code' => $e->errorCode,
                'timestamp' => now()->toIso8601String(),
            ], $e->status);
        }
    }

    public function kardex(string $id): JsonResponse
    {
        $insumo = $this->insumoRepository->findById($id);
        if (! $insumo) {
            return response()->json([
                'success' => false,
                'error' => "Insumo no encontrado con ID: {$id}.",
                'code' => 'NOT_FOUND',
                'timestamp' => now()->toIso8601String(),
            ], 404);
        }

        $movimientos = $this->movimientoRepository->findByInsumoId($id);
        $movimientosArray = array_map(fn ($m) => $m->toArray(), $movimientos);

        return response()->json([
            'success' => true,
            'data' => [
                'insumo' => $insumo->toArray(),
                'movimientos' => $movimientosArray,
                'total_movimientos' => count($movimientosArray),
            ],
            'timestamp' => now()->toIso8601String(),
        ], 200);
    }
}
