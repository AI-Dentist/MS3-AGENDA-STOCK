<?php

namespace App\Domain\Repositories;

use App\Domain\Entities\Insumo;
use App\Domain\Entities\MovimientoStock;

interface StockMovementWriterInterface
{
    /** Lectura persistente y consistente, sin fallback a memoria. */
    public function findInsumo(string $id): ?Insumo;

    /** @return array{payload_hash: string, status: int, body: array}|null */
    public function findOutcome(string $movementId): ?array;

    /** Stock CAS + Kardex + resultado durable, en una única transacción. */
    public function commit(Insumo $previous, MovimientoStock $movement, string $payloadHash, array $body): void;
}
