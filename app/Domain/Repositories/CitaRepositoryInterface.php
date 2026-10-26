<?php

namespace App\Domain\Repositories;

use App\Domain\Entities\Cita;

interface CitaRepositoryInterface
{
    public function findByIdempotencyKey(string $key): ?Cita;
    public function save(Cita $cita): void;
    public function findAll(): array;
}