<?php

namespace App\Domain\Repositories;

use App\Domain\Entities\Cita;

interface CitaRepositoryInterface
{
    public function findById(string $id): ?Cita;

    public function findByIdempotencyKey(string $key): ?Cita;

    public function existeColision(string $odontologoId, string $consultorioId, string $fechaHora, ?string $excluirCitaId = null): bool;

    public function save(Cita $cita): void;

    public function updateEstado(string $id, string $nuevoEstado): void;

    public function findAll(): array;
}
