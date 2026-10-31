<?php

namespace App\Application\UseCases;

use App\Application\Ports\Ms1HorarioReferenceValidatorPort;
use App\Domain\Entities\Horario;
use App\Domain\Repositories\HorarioRepositoryInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CrearHorarioUseCase
{
    public function __construct(
        private HorarioRepositoryInterface $repository,
        private Ms1HorarioReferenceValidatorPort $referenceValidator
    ) {}

    /**
     * Crea y persiste un nuevo horario tras validar referencias activas en MS1 con el token del llamador.
     *
     * @param  array  $data  Campos del horario (odontologo_id, consultorio_id, especialidad, fecha, hora_inicio, hora_fin, disponible)
     * @param  string  $callerBearerToken  Token Bearer del usuario ADMIN autenticado
     * @return array Resumen con el horario creado en formato array y mensaje de éxito
     */
    public function execute(array $data, string $callerBearerToken): array
    {
        $odontologoId = trim($data['odontologo_id'] ?? '');
        $consultorioId = trim($data['consultorio_id'] ?? '');
        $especialidad = trim($data['especialidad'] ?? '');
        $fecha = trim($data['fecha'] ?? '');
        $horaInicio = trim($data['hora_inicio'] ?? '');
        $horaFin = trim($data['hora_fin'] ?? '');
        $disponible = isset($data['disponible']) ? (bool) $data['disponible'] : true;

        if (empty($odontologoId) || empty($consultorioId) || empty($especialidad) || empty($fecha) || empty($horaInicio) || empty($horaFin)) {
            throw new InvalidArgumentException('Todos los campos son obligatorios: odontologo_id, consultorio_id, especialidad, fecha, hora_inicio, hora_fin.');
        }

        // Validar formato de fecha (Y-m-d)
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $fecha);
        if (! $d || $d->format('Y-m-d') !== $fecha) {
            throw new InvalidArgumentException("Formato de fecha inválido ('{$fecha}'). Se espera formato YYYY-MM-DD.");
        }

        // Validar rango horario: hora_inicio < hora_fin
        if (strtotime($horaInicio) >= strtotime($horaFin)) {
            throw new InvalidArgumentException("Rango horario inválido: la hora_inicio ('{$horaInicio}') debe ser anterior a la hora_fin ('{$horaFin}').");
        }

        // Validar referencias canónicas y activas en MS1
        $this->referenceValidator->validateReferences($odontologoId, $consultorioId, $callerBearerToken);

        // Generar identificador de servidor único sin sobreescribir registros existentes
        $nuevoId = 'hor-'.Str::uuid()->toString();

        $horario = new Horario(
            id: $nuevoId,
            odontologoId: $odontologoId,
            especialidad: $especialidad,
            consultorioId: $consultorioId,
            fecha: $fecha,
            horaInicio: $horaInicio,
            horaFin: $horaFin,
            disponible: $disponible
        );

        $this->repository->save($horario);

        return [
            'horario' => $horario->toArray(),
            'mensaje' => 'Horario registrado con éxito.',
        ];
    }
}
