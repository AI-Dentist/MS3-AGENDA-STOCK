<?php

namespace App\Domain\Repositories;

use App\Domain\Entities\MovimientoStock;

interface MovimientoStockRepositoryInterface
{
    public function save(MovimientoStock $movimiento): void;

    /**
     * @return MovimientoStock[]
     */
    public function findByInsumoId(string $insumoId): array;

    /**
     * @return MovimientoStock[]
     */
    public function findAll(): array;
}
