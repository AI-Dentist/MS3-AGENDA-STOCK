<?php

namespace App\Domain\Repositories;

use App\Domain\Entities\Insumo;

interface InsumoRepositoryInterface
{
    /** @return Insumo[] */
    public function findAll(): array;
    public function findById(string $id): ?Insumo;
    public function save(Insumo $insumo): void;
}