<?php

namespace App\Application\UseCases;

use App\Domain\Entities\Cita;
use App\Domain\Exceptions\CitaCollisionException;
use App\Domain\Repositories\CitaRepositoryInterface;
use App\Domain\Repositories\HorarioRepositoryInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AgendarCitaUseCase
{
    public function __construct(
        private CitaRepositoryInterface $repository,
        private ?HorarioRepositoryInterface $horarioRepository = null
    ) {}

    public function execute(array $data, string $idempotencyKey): array
    {
        // Regla 1: Si la clave ya fue procesada, devolver la misma cita (Idempotencia)
        $citaExistente = $this->repository->findByIdempotencyKey($idempotencyKey);
        if ($citaExistente) {
            return [
                'cita' => $citaExistente->toArray(),
                'idempotente' => true,
                'mensaje' => 'Cita recuperada por clave de idempotencia existente.',
            ];
        }

        // Regla 2: Validar que la cita caiga dentro de un turno disponible del odontólogo y consultorio
        if ($this->horarioRepository !== null) {
            $horarios = $this->horarioRepository->findByOdontologo($data['odontologo_id']);
            if (! empty($horarios)) {
                $normalizado = str_replace('T', ' ', $data['fecha_hora']);
                $fechaCita = substr($normalizado, 0, 10);
                $horaCita = substr($normalizado, 11, 5);

                $turnoValido = false;
                foreach ($horarios as $h) {
                    if (
                        $h->fecha === $fechaCita
                        && $h->consultorioId === $data['consultorio_id']
                        && $h->disponible
                        && $horaCita >= $h->horaInicio
                        && $horaCita < $h->horaFin
                    ) {
                        $turnoValido = true;
                        break;
                    }
                }

                if (! $turnoValido) {
                    throw new InvalidArgumentException(
                        "El horario solicitado ({$data['fecha_hora']}) no se encuentra dentro de un turno disponible para el odontólogo en el consultorio {$data['consultorio_id']}."
                    );
                }
            }
        }

        // Regla 3: Verificar colisión horaria (evitar doble reserva para mismo odontólogo o consultorio)
        if ($this->repository->existeColision($data['odontologo_id'], $data['consultorio_id'], $data['fecha_hora'])) {
            throw new CitaCollisionException(
                "Conflicto de horario: El odontólogo o consultorio ya cuenta con una cita activa para {$data['fecha_hora']}."
            );
        }

        // Regla 4: Si no existe colisión, crear y persistir la cita
        $nuevaCita = new Cita(
            id: (string) Str::uuid(),
            idempotencyKey: $idempotencyKey,
            odontologoId: $data['odontologo_id'],
            consultorioId: $data['consultorio_id'],
            pacienteId: $data['paciente_id'],
            fechaHora: $data['fecha_hora'],
            estado: Cita::ESTADO_RESERVADA
        );

        $this->repository->save($nuevaCita);

        return [
            'cita' => $nuevaCita->toArray(),
            'idempotente' => false,
            'mensaje' => 'Cita reservada con éxito.',
        ];
    }
}
