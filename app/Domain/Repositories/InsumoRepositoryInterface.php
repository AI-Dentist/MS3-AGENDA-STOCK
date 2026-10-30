<?php

namespace App\Domain\Repositories;

use App\Domain\Entities\Insumo;

interface InsumoRepositoryInterface
{
    /** @return Insumo[] */
    public function findAll(): array;

    public function findById(string $id): ?Insumo;

    public function findByCodigo(string $codigo): ?Insumo;

    public function save(Insumo $insumo): void;

    public function updateStock(string $id, int $nuevoStock): void;
}
