<?php

namespace App\Application\UseCases;

use App\Domain\Entities\Cita;
use App\Domain\Repositories\CitaRepositoryInterface;
use Illuminate\Support\Str;

class AgendarCitaUseCase
{
    public function __construct(private CitaRepositoryInterface $repository) {}

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

        // Regla 2: Si no existe, crear la cita
        $nuevaCita = new Cita(
            id: (string) Str::uuid(),
            idempotencyKey: $idempotencyKey,
            odontologoId: $data['odontologo_id'],
            consultorioId: $data['consultorio_id'],
            pacienteId: $data['paciente_id'],
            fechaHora: $data['fecha_hora'],
            estado: 'RESERVADA'
        );

        $this->repository->save($nuevaCita);

        return [
            'cita' => $nuevaCita->toArray(),
            'idempotente' => false,
            'mensaje' => 'Cita reservada con éxito.',
        ];
    }
}