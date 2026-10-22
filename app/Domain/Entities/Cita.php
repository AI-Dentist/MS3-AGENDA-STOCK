<?php

namespace App\Domain\Entities;

class Cita
{
    public function __construct(
        public readonly string $id,
        public readonly string $idempotencyKey,
        public readonly string $odontologoId,
        public readonly string $consultorioId,
        public readonly string $pacienteId,
        public readonly string $fechaHora,
        public string $estado = 'RESERVADA'
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'idempotency_key' => $this->idempotencyKey,
            'odontologo_id' => $this->odontologoId,
            'consultorio_id' => $this->consultorioId,
            'paciente_id' => $this->pacienteId,
            'fecha_hora' => $this->fechaHora,
            'estado' => $this->estado,
        ];
    }
}