<?php

namespace App\Domain\Entities;

class Horario
{
    public function __construct(
        public readonly string $id,
        public readonly string $odontologoId,
        public readonly string $especialidad,
        public readonly string $consultorioId,
        public readonly string $fecha,
        public readonly string $horaInicio,
        public readonly string $horaFin,
        public readonly bool $disponible = true
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'odontologo_id' => $this->odontologoId,
            'especialidad' => $this->especialidad,
            'consultorio_id' => $this->consultorioId,
            'fecha' => $this->fecha,
            'hora_inicio' => $this->horaInicio,
            'hora_fin' => $this->horaFin,
            'disponible' => $this->disponible,
        ];
    }
}
