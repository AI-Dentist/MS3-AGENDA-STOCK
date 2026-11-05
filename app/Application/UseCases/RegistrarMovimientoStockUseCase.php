<?php

namespace App\Application\UseCases;

use App\Domain\Entities\Insumo;
use App\Domain\Entities\MovimientoStock;
use App\Domain\Exceptions\StockMovementException;
use App\Domain\Repositories\StockMovementWriterInterface;
use Illuminate\Support\Str;
use Throwable;

class RegistrarMovimientoStockUseCase
{
    public function __construct(private StockMovementWriterInterface $writer) {}

    public function execute(array $data, ?string $key, ?string $actor): array
    {
        try {
            return $this->register($data, $key, $actor);
        } catch (StockMovementException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw StockMovementException::unavailable($e);
        }
    }

    private function register(array $data, ?string $key, ?string $actor): array
    {
        if ($key !== null && ($actor === null || $actor === '' || $actor === 'unknown-user')) {
            throw new StockMovementException('UNAUTHORIZED', 'Se requiere un actor autenticado para utilizar idempotencia.', 401);
        }
        $type = $data['tipo_movimiento'];
        $quantity = (int) $data['cantidad'];
        $decrement = $type === MovimientoStock::TIPO_SALIDA_PROCEDIMIENTO
            || ($type === MovimientoStock::TIPO_AJUSTE && strtoupper($data['direccion'] ?? 'INCREMENTO') === 'DECREMENTO');
        // Vincular la operación semántica y el actor verificado, no solo usuario_id del cuerpo.
        $hash = hash('sha256', json_encode([
            'version' => 1, 'actor' => $actor, 'insumo_id' => $data['insumo_id'],
            'tipo_movimiento' => $type, 'cantidad' => $quantity, 'motivo' => $data['motivo'],
            'usuario_id' => $data['usuario_id'] ?? null, 'direccion' => $decrement ? 'DECREMENTO' : 'INCREMENTO',
        ], JSON_THROW_ON_ERROR));
        $id = $key === null ? (string) Str::uuid()
            : 'stock-idem-v1-'.hash('sha256', json_encode([$actor, $key], JSON_THROW_ON_ERROR));
        if ($key !== null && ($outcome = $this->confirmed($id, $hash)) !== null) {
            return $outcome;
        }

        $insumo = $this->writer->findInsumo($data['insumo_id']);
        // Otro intento con esta clave pudo confirmar entre las dos lecturas.
        if (($insumo === null || ($decrement && $insumo->stockActual < $quantity))
            && $key !== null && ($outcome = $this->confirmed($id, $hash)) !== null) {
            return $outcome;
        }
        if ($insumo === null) {
            throw new StockMovementException('NOT_FOUND', "Insumo no encontrado con ID: {$data['insumo_id']}.", 404);
        }
        if ($decrement && $insumo->stockActual < $quantity) {
            $message = $type === MovimientoStock::TIPO_AJUSTE ? 'Stock insuficiente para ajuste por decremento.'
                : "Stock insuficiente para realizar la salida. Stock actual: {$insumo->stockActual}, Solicitado: {$quantity}.";
            throw new StockMovementException('INSUFFICIENT_STOCK', $message, 400);
        }
        $next = $decrement ? $insumo->stockActual - $quantity : $insumo->stockActual + $quantity;
        $updated = new Insumo(
            id: $insumo->id, codigo: $insumo->codigo, nombre: $insumo->nombre,
            stockActual: $next, stockMinimo: $insumo->stockMinimo, unidadMedida: $insumo->unidadMedida
        );
        $timestamp = now()->toIso8601String();
        $movement = new MovimientoStock(
            id: $id, insumoId: $insumo->id, tipoMovimiento: $type, cantidad: $quantity,
            stockAnterior: $insumo->stockActual, stockPosterior: $next, motivo: $data['motivo'],
            usuarioId: $key !== null ? $actor : ($data['usuario_id'] ?? $actor ?? 'sistema'), fechaHora: $timestamp
        );
        $body = [
            'success' => true,
            'data' => ['movimiento' => $movement->toArray(), 'insumo' => $updated->toArray()],
            'mensaje' => 'Movimiento de stock registrado y Kardex actualizado exitosamente.',
            'timestamp' => $timestamp,
        ];
        try {
            $this->writer->commit($insumo, $movement, $hash, $body);
        } catch (Throwable $e) {
            // También cubre dos solicitudes concurrentes y respuesta perdida tras commit.
            // Una lectura consistente puede confirmar; jamás reintentamos la escritura.
            $outcome = $this->confirmed($id, $hash);
            if ($outcome !== null) {
                return $outcome;
            }
            throw StockMovementException::unavailable($e);
        }

        return ['status' => 201, 'body' => $body];
    }

    private function confirmed(string $id, string $hash): ?array
    {
        $outcome = $this->writer->findOutcome($id);
        if ($outcome !== null && ! hash_equals($outcome['payload_hash'], $hash)) {
            throw new StockMovementException(
                'IDEMPOTENCY_CONFLICT', 'La clave de idempotencia ya fue utilizada con otro movimiento.', 409
            );
        }

        return $outcome;
    }
}
