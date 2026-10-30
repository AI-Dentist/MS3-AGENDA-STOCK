<?php

namespace App\Domain\Entities;

use InvalidArgumentException;

class MovimientoStock
{
    public const TIPO_ENTRADA_COMPRA = 'ENTRADA_COMPRA';

    public const TIPO_SALIDA_PROCEDIMIENTO = 'SALIDA_PROCEDIMIENTO';

    public const TIPO_AJUSTE = 'AJUSTE';

    public function __construct(
        public readonly string $id,
        public readonly string $insumoId,
        public readonly string $tipoMovimiento,
        public readonly int $cantidad,
        public readonly int $stockAnterior,
        public readonly int $stockPosterior,
        public readonly string $motivo,
        public readonly ?string $usuarioId = null,
        public readonly ?string $fechaHora = null
    ) {
        $tiposValidos = [
            self::TIPO_ENTRADA_COMPRA,
            self::TIPO_SALIDA_PROCEDIMIENTO,
            self::TIPO_AJUSTE,
        ];

        if (! in_array($this->tipoMovimiento, $tiposValidos, true)) {
            throw new InvalidArgumentException("Tipo de movimiento inválido: {$this->tipoMovimiento}. Permitidos: ".implode(', ', $tiposValidos));
        }

        if ($this->cantidad <= 0) {
            throw new InvalidArgumentException('La cantidad del movimiento debe ser mayor a cero.');
        }
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'insumo_id' => $this->insumoId,
            'tipo_movimiento' => $this->tipoMovimiento,
            'cantidad' => $this->cantidad,
            'stock_anterior' => $this->stockAnterior,
            'stock_posterior' => $this->stockPosterior,
            'motivo' => $this->motivo,
            'usuario_id' => $this->usuarioId ?? 'sistema',
            'fecha_hora' => $this->fechaHora ?? now()->toIso8601String(),
        ];
    }
}
