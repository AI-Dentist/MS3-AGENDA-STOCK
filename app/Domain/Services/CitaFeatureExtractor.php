<?php

namespace App\Domain\Services;

use App\Domain\Entities\Cita;
use DateTimeInterface;
use DateTimeImmutable;
use DateTime;
use Throwable;

class CitaFeatureExtractor
{
    /**
     * Extrae y normaliza las features requeridas para la inferencia de No-Show (S6/S7).
     *
     * @param Cita|array $cita Cita objetivo (entidad o array representativo)
     * @param array<Cita|array>|null $historialCitas Conjunto de citas para derivar historial de cancelaciones
     * @return array{payload: array<string, mixed>, featuresImputadas: array<string>}
     */
    public function extract(Cita|array $cita, ?array $historialCitas = null): array
    {
        $imputadas = [];

        // 1. Identificadores
        $citaId = $cita instanceof Cita ? $cita->id : ($cita['id'] ?? $cita['cita_id'] ?? 'unknown');
        $pacienteId = $cita instanceof Cita ? $cita->pacienteId : ($cita['paciente_id'] ?? null);

        // 2. Fecha y hora de la cita
        $targetDt = $this->parseDateTime(
            $cita instanceof Cita ? $cita->fechaHora : ($cita['fecha_hora'] ?? null)
        );

        if ($targetDt !== null) {
            $diaSemana = (int) $targetDt->format('N');
            $horaCita = (int) $targetDt->format('G');
            $bandaHoraria = $horaCita >= 13 ? 1 : 0;
        } else {
            $diaSemana = 1;
            $horaCita = 10;
            $bandaHoraria = 0;
            $imputadas[] = 'fecha_hora';
        }

        // 3. Edad (S7: siempre imputada ya que MS3 no almacena fecha de nacimiento)
        $edad = 35;
        $imputadas[] = 'edad';

        // 4. Días de anticipación (S6: días enteros entre reservada_en y fecha_hora)
        $reservadaDt = $this->parseDateTime(
            $cita instanceof Cita ? $cita->reservadaEn : ($cita['reservada_en'] ?? $cita['reservadaEn'] ?? null)
        );

        if ($reservadaDt !== null && $targetDt !== null) {
            $diff = (int) $reservadaDt->diff($targetDt)->days;
            $diasAnticipacion = max(0, $diff);
        } elseif (is_array($cita) && isset($cita['dias_anticipacion'])) {
            $diasAnticipacion = (int) $cita['dias_anticipacion'];
        } else {
            $diasAnticipacion = 7;
            $imputadas[] = 'anticipacionReserva';
        }

        // 5. Duración estimada en minutos
        $duracion = null;
        if ($cita instanceof Cita) {
            $duracion = $cita->duracionEstimadaMin;
        } elseif (is_array($cita)) {
            $duracion = $cita['duracion_estimada_min'] ?? $cita['duracionEstimadaMin'] ?? $cita['duracion_minutos'] ?? null;
        }

        if ($duracion !== null && (int) $duracion > 0) {
            $duracionMinutos = (int) $duracion;
        } else {
            $duracionMinutos = 30;
            $imputadas[] = 'duracion_minutos';
        }

        // 6. Tipo de tratamiento y nivel de riesgo asociado
        $tipoTratamiento = null;
        if ($cita instanceof Cita) {
            $tipoTratamiento = $cita->tipoTratamiento;
        } elseif (is_array($cita)) {
            $tipoTratamiento = $cita['tipo_tratamiento'] ?? $cita['tipoTratamiento'] ?? null;
        }

        if ($tipoTratamiento !== null && trim($tipoTratamiento) !== '') {
            $tipoUpper = strtoupper(trim((string) $tipoTratamiento));
            if (in_array($tipoUpper, ['CIRUGIA', 'ENDODONCIA', 'IMPLANTE'], true)) {
                $riesgoTratamiento = 2;
            } elseif (in_array($tipoUpper, ['ORTODONCIA', 'RESTAURACION', 'PROFILAXIS'], true)) {
                $riesgoTratamiento = 1;
            } else {
                $riesgoTratamiento = 0;
            }
        } elseif (is_array($cita) && isset($cita['tipo_tratamiento_riesgo'])) {
            $riesgoTratamiento = (int) $cita['tipo_tratamiento_riesgo'];
        } else {
            $riesgoTratamiento = 1;
            $imputadas[] = 'tipo_tratamiento_riesgo';
        }

        // 7. Cancelaciones previas (S6: canceladas o no asistió con fecha_hora ANTERIOR a la cita)
        if (is_array($cita) && isset($cita['cancelaciones_previas']) && $historialCitas === null) {
            $cancelacionesPrevias = (int) $cita['cancelaciones_previas'];
        } elseif ($pacienteId !== null && $historialCitas !== null && $targetDt !== null) {
            $cancelacionesPrevias = 0;
            $targetTimestamp = $targetDt->getTimestamp();

            foreach ($historialCitas as $h) {
                $hPacienteId = $h instanceof Cita ? $h->pacienteId : ($h['paciente_id'] ?? null);
                if ($hPacienteId !== $pacienteId) {
                    continue;
                }

                $hEstado = $h instanceof Cita ? $h->estado : ($h['estado'] ?? null);
                if ($hEstado !== Cita::ESTADO_CANCELADA && $hEstado !== Cita::ESTADO_NO_ASISTIO) {
                    continue;
                }

                $hFechaDt = $this->parseDateTime(
                    $h instanceof Cita ? $h->fechaHora : ($h['fecha_hora'] ?? null)
                );

                if ($hFechaDt !== null && $hFechaDt->getTimestamp() < $targetTimestamp) {
                    $cancelacionesPrevias++;
                }
            }
        } else {
            $cancelacionesPrevias = 0;
            if ($historialCitas === null && (!is_array($cita) || !isset($cita['cancelaciones_previas']))) {
                $imputadas[] = 'cancelaciones_previas';
            }
        }

        return [
            'payload' => [
                'cita_id' => (string) $citaId,
                'paciente_id' => (string) ($pacienteId ?? 'pac-unknown'),
                'edad' => $edad,
                'dias_anticipacion' => $diasAnticipacion,
                'cancelaciones_previas' => $cancelacionesPrevias,
                'dia_semana' => $diaSemana,
                'hora_cita' => $horaCita,
                'banda_horaria' => $bandaHoraria,
                'duracion_minutos' => $duracionMinutos,
                'tipo_tratamiento_riesgo' => $riesgoTratamiento,
            ],
            'featuresImputadas' => array_values(array_unique($imputadas)),
        ];
    }

    private function parseDateTime(mixed $date): ?DateTimeInterface
    {
        if ($date instanceof DateTimeInterface) {
            return $date;
        }

        if (is_string($date) && trim($date) !== '') {
            try {
                return new DateTimeImmutable($date);
            } catch (Throwable $e) {
                return null;
            }
        }

        return null;
    }
}
