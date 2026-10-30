<?php

namespace App\Application\UseCases;

use App\Domain\Entities\Cita;
use App\Domain\Repositories\CitaRepositoryInterface;
use InvalidArgumentException;

class CancelarCitaUseCase
{
    public function __construct(private CitaRepositoryInterface $repository) {}

    public function execute(string $citaId): array
    {
        $cita = $this->repository->findById($citaId);

        if (! $cita) {
            throw new InvalidArgumentException("La cita con ID '{$citaId}' no existe.");
        }

        $cita->cancelar();
        $this->repository->updateEstado($citaId, Cita::ESTADO_CANCELADA);

        return [
            'cita' => $cita->toArray(),
            'mensaje' => 'Cita cancelada con éxito.',
        ];
    }
}
