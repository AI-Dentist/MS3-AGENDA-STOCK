<?php

namespace App\Application\UseCases;

use App\Domain\Repositories\HorarioRepositoryInterface;

class ConsultarHorariosUseCase
{
    public function __construct(private HorarioRepositoryInterface $repository) {}

    public function execute(?string $odontologoId = null): array
    {
        $horarios = $odontologoId
            ? $this->repository->findByOdontologo($odontologoId)
            : $this->repository->findAll();

        return array_map(fn ($horario) => $horario->toArray(), $horarios);
    }
}
