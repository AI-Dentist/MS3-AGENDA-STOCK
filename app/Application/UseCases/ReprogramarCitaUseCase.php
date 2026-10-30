<?php

namespace App\Application\UseCases;

use App\Domain\Entities\Cita;
use App\Domain\Exceptions\CitaCollisionException;
use App\Domain\Repositories\CitaRepositoryInterface;
use InvalidArgumentException;

class ReprogramarCitaUseCase
{
    public function __construct(private CitaRepositoryInterface $repository) {}

    public function execute(string $citaId, string $nuevaFechaHora): array
    {
        $cita = $this->repository->findById($citaId);

        if (! $cita) {
            throw new InvalidArgumentException("La cita con ID '{$citaId}' no existe.");
        }

        if ($cita->estado === Cita::ESTADO_CANCELADA) {
            throw new InvalidArgumentException('No se puede reprogramar una cita cancelada.');
        }

        if ($cita->estado === Cita::ESTADO_COMPLETADA) {
            throw new InvalidArgumentException('No se puede reprogramar una cita que ya fue completada.');
        }

        // Validar colisión en la nueva fecha y hora
        if ($this->repository->existeColision($cita->odontologoId, $cita->consultorioId, $nuevaFechaHora, $citaId)) {
            throw new CitaCollisionException(
                "Conflicto de horario: El odontólogo o consultorio ya cuenta con una cita activa para {$nuevaFechaHora}."
            );
        }

        $citaActualizada = new Cita(
            id: $cita->id,
            idempotencyKey: $cita->idempotencyKey,
            odontologoId: $cita->odontologoId,
            consultorioId: $cita->consultorioId,
            pacienteId: $cita->pacienteId,
            fechaHora: $nuevaFechaHora,
            estado: Cita::ESTADO_REPROGRAMADA
        );

        $this->repository->save($citaActualizada);

        return [
            'cita' => $citaActualizada->toArray(),
            'mensaje' => 'Cita reprogramada con éxito.',
        ];
    }
}
