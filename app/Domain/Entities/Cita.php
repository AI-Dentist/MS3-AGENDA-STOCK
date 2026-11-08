<?php

namespace App\Domain\Entities;

use InvalidArgumentException;

class Cita
{
    public const ESTADO_RESERVADA = 'RESERVADA';

    public const ESTADO_EN_ATENCION = 'EN_ATENCION';

    public const ESTADO_COMPLETADA = 'COMPLETADA';

    public const ESTADO_CANCELADA = 'CANCELADA';

    public const ESTADO_REPROGRAMADA = 'REPROGRAMADA';

    public const ESTADO_NO_ASISTIO = 'NO_ASISTIO';

    public const ESTADOS_VALIDOS = [
        self::ESTADO_RESERVADA,
        self::ESTADO_EN_ATENCION,
        self::ESTADO_COMPLETADA,
        self::ESTADO_CANCELADA,
        self::ESTADO_REPROGRAMADA,
        self::ESTADO_NO_ASISTIO,
    ];

    public function __construct(
        public readonly string $id,
        public readonly string $idempotencyKey,
        public readonly string $odontologoId,
        public readonly string $consultorioId,
        public readonly string $pacienteId,
        public string $fechaHora,
        public string $estado = self::ESTADO_RESERVADA,
        // Optional fields for ML enrichment (S10 / L12)
        public readonly ?int $duracionEstimadaMin = null,
        public readonly ?string $tipoTratamiento = null,
        public readonly ?string $reservadaEn = null,
    ) {
        if (! in_array($this->estado, self::ESTADOS_VALIDOS, true)) {
            throw new InvalidArgumentException("Estado de cita no válido: {$this->estado}");
        }
    }

    public function marcarEnAtencion(): void
    {
        if (in_array($this->estado, [self::ESTADO_CANCELADA, self::ESTADO_COMPLETADA, self::ESTADO_NO_ASISTIO], true)) {
            throw new InvalidArgumentException("No se puede iniciar atención de una cita en estado {$this->estado}.");
        }
        $this->estado = self::ESTADO_EN_ATENCION;
    }

    public function completar(): void
    {
        if ($this->estado === self::ESTADO_CANCELADA) {
            throw new InvalidArgumentException('No se puede completar una cita previamente cancelada.');
        }
        if ($this->estado === self::ESTADO_COMPLETADA) {
            throw new InvalidArgumentException('La cita ya se encuentra completada.');
        }
        $this->estado = self::ESTADO_COMPLETADA;
    }

    public function marcarNoAsistio(): void
    {
        if (in_array($this->estado, [self::ESTADO_CANCELADA, self::ESTADO_COMPLETADA], true)) {
            throw new InvalidArgumentException("No se puede marcar inasistencia para una cita en estado {$this->estado}.");
        }
        $this->estado = self::ESTADO_NO_ASISTIO;
    }

    public function cancelar(): void
    {
        if ($this->estado === self::ESTADO_COMPLETADA) {
            throw new InvalidArgumentException('No se puede cancelar una cita que ya fue completada.');
        }
        $this->estado = self::ESTADO_CANCELADA;
    }

    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'idempotency_key' => $this->idempotencyKey,
            'odontologo_id' => $this->odontologoId,
            'consultorio_id' => $this->consultorioId,
            'paciente_id' => $this->pacienteId,
            'fecha_hora' => $this->fechaHora,
            'estado' => $this->estado,
        ];

        if ($this->duracionEstimadaMin !== null) {
            $data['duracion_estimada_min'] = $this->duracionEstimadaMin;
        }
        if ($this->tipoTratamiento !== null) {
            $data['tipo_tratamiento'] = $this->tipoTratamiento;
        }
        if ($this->reservadaEn !== null) {
            $data['reservada_en'] = $this->reservadaEn;
        }

        return $data;
    }
}
