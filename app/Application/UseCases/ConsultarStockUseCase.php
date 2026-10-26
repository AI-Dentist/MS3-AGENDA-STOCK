<?php

namespace App\Application\UseCases;

use App\Domain\Repositories\InsumoRepositoryInterface;

class ConsultarStockUseCase
{
    public function __construct(private InsumoRepositoryInterface $repository) {}

    public function execute(): array
    {
        $insumos = $this->repository->findAll();

        return array_map(fn($insumo) => $insumo->toArray(), $insumos);
    }
}