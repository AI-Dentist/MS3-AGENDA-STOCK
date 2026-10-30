<?php

namespace App\Http\Controllers;

use App\Application\UseCases\ConsultarStockUseCase;
use App\Domain\Entities\Insumo;
use App\Domain\Entities\MovimientoStock;
use App\Domain\Repositories\InsumoRepositoryInterface;
use App\Domain\Repositories\MovimientoStockRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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

    public function registrarMovimiento(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'insumo_id' => 'required|string',
            'tipo_movimiento' => 'required|string|in:ENTRADA_COMPRA,SALIDA_PROCEDIMIENTO,AJUSTE',
            'cantidad' => 'required|integer|min:1',
            'motivo' => 'required|string',
            'usuario_id' => 'nullable|string',
        ]);

        $insumo = $this->insumoRepository->findById($validated['insumo_id']);
        if (! $insumo) {
            return response()->json([
                'success' => false,
                'error' => "Insumo no encontrado con ID: {$validated['insumo_id']}.",
                'code' => 'NOT_FOUND',
                'timestamp' => now()->toIso8601String(),
            ], 404);
        }

        $cantidad = (int) $validated['cantidad'];
        $tipo = $validated['tipo_movimiento'];
        $stockAnterior = $insumo->stockActual;

        if ($tipo === MovimientoStock::TIPO_ENTRADA_COMPRA) {
            $stockPosterior = $stockAnterior + $cantidad;
        } elseif ($tipo === MovimientoStock::TIPO_SALIDA_PROCEDIMIENTO) {
            if ($stockAnterior < $cantidad) {
                return response()->json([
                    'success' => false,
                    'error' => "Stock insuficiente para realizar la salida. Stock actual: {$stockAnterior}, Solicitado: {$cantidad}.",
                    'code' => 'INSUFFICIENT_STOCK',
                    'timestamp' => now()->toIso8601String(),
                ], 400);
            }
            $stockPosterior = $stockAnterior - $cantidad;
        } else { // AJUSTE
            // Si el ajuste es positivo o negativo
            $direccion = $request->input('direccion', 'INCREMENTO'); // INCREMENTO o DECREMENTO
            if (strtoupper($direccion) === 'DECREMENTO') {
                if ($stockAnterior < $cantidad) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Stock insuficiente para ajuste por decremento.',
                        'code' => 'INSUFFICIENT_STOCK',
                        'timestamp' => now()->toIso8601String(),
                    ], 400);
                }
                $stockPosterior = $stockAnterior - $cantidad;
            } else {
                $stockPosterior = $stockAnterior + $cantidad;
            }
        }

        // Actualizar Insumo
        $updatedInsumo = new Insumo(
            id: $insumo->id,
            codigo: $insumo->codigo,
            nombre: $insumo->nombre,
            stockActual: $stockPosterior,
            stockMinimo: $insumo->stockMinimo,
            unidadMedida: $insumo->unidadMedida
        );
        $this->insumoRepository->updateStock($insumo->id, $stockPosterior);

        // Registrar Movimiento
        $movimiento = new MovimientoStock(
            id: (string) Str::uuid(),
            insumoId: $insumo->id,
            tipoMovimiento: $tipo,
            cantidad: $cantidad,
            stockAnterior: $stockAnterior,
            stockPosterior: $stockPosterior,
            motivo: $validated['motivo'],
            usuarioId: $validated['usuario_id'] ?? $request->attributes->get('user_id') ?? 'sistema',
            fechaHora: now()->toIso8601String()
        );
        $this->movimientoRepository->save($movimiento);

        return response()->json([
            'success' => true,
            'data' => [
                'movimiento' => $movimiento->toArray(),
                'insumo' => $updatedInsumo->toArray(),
            ],
            'mensaje' => 'Movimiento de stock registrado y Kardex actualizado exitosamente.',
            'timestamp' => now()->toIso8601String(),
        ], 201);
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
