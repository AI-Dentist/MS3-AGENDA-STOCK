<?php

namespace App\Domain\Repositories;

use App\Domain\Entities\Horario;

interface HorarioRepositoryInterface
{
    /** @return Horario[] */
    public function findByOdontologo(string $odontologoId): array;

    public function save(Horario $horario): void;

    /** @return Horario[] */
    public function findAll(): array;
}
