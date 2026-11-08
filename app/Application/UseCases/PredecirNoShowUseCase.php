<?php

namespace App\Application\UseCases;

use App\Domain\Entities\Cita;
use App\Domain\Repositories\CitaRepositoryInterface;
use App\Domain\Services\MlInferenceServiceInterface;
use DateTime;
use Throwable;

class PredecirNoShowUseCase
{
    public function __construct(
        private MlInferenceServiceInterface $mlService,
        private ?CitaRepositoryInterface $citaRepository = null
    ) {}

    public function execute(array $data): array
    {
        $imputadas = [];

        // 1. Hora y día de semana (derivables de fecha_hora si viene dada)
        if (isset($data['fecha_hora'])) {
            try {
                $dt = new DateTime($data['fecha_hora']);
                $diaSemana = (int) $dt->format('N');
                $horaCita = (int) $dt->format('G');
            } catch (Throwable $e) {
                $diaSemana = 1;
                $horaCita = 10;
            }
        } else {
            $diaSemana = isset($data['dia_semana']) ? (int) $data['dia_semana'] : 1;
            $horaCita = isset($data['hora_cita']) ? (int) $data['hora_cita'] : 10;
        }

        // 2. Banda horaria (0 = Mañana < 13:00, 1 = Tarde >= 13:00)
        $bandaHoraria = isset($data['banda_horaria']) ? (int) $data['banda_horaria'] : ($horaCita >= 13 ? 1 : 0);

        // 3. Duración estimada
        if (isset($data['duracion_minutos'])) {
            $duracion = (int) $data['duracion_minutos'];
        } elseif (isset($data['duracion_estimada_min'])) {
            $duracion = (int) $data['duracion_estimada_min'];
        } else {
            $duracion = 30;
            $imputadas[] = 'duracion_minutos (default: 30)';
        }

        // 4. Días de anticipación
        if (isset($data['dias_anticipacion'])) {
            $diasAnticipacion = (int) $data['dias_anticipacion'];
        } elseif (isset($data['reservada_en'], $data['fecha_hora'])) {
            try {
                $f1 = new DateTime($data['reservada_en']);
                $f2 = new DateTime($data['fecha_hora']);
                $diasAnticipacion = max(0, (int) $f1->diff($f2)->days);
            } catch (Throwable $e) {
                $diasAnticipacion = 7;
                $imputadas[] = 'dias_anticipacion (default: 7)';
            }
        } else {
            $diasAnticipacion = 7;
            $imputadas[] = 'dias_anticipacion (default: 7)';
        }

        // 5. Cancelaciones previas del paciente
        if (isset($data['cancelaciones_previas'])) {
            $cancelacionesPrevias = (int) $data['cancelaciones_previas'];
        } elseif (isset($data['paciente_id']) && $this->citaRepository !== null) {
            $allCitas = $this->citaRepository->findAll();
            $cancelacionesPrevias = 0;
            foreach ($allCitas as $cp) {
                if ($cp->pacienteId === $data['paciente_id'] && ($cp->estado === Cita::ESTADO_CANCELADA || $cp->estado === Cita::ESTADO_NO_ASISTIO)) {
                    $cancelacionesPrevias++;
                }
            }
        } else {
            $cancelacionesPrevias = 0;
            $imputadas[] = 'cancelaciones_previas (default: 0)';
        }

        // 6. Edad
        if (isset($data['edad'])) {
            $edad = (int) $data['edad'];
        } else {
            $edad = 35;
            $imputadas[] = 'edad (default: 35)';
        }

        // 7. Tipo de tratamiento / riesgo
        if (isset($data['tipo_tratamiento_riesgo'])) {
            $riesgoTratamiento = (int) $data['tipo_tratamiento_riesgo'];
        } elseif (isset($data['tipo_tratamiento'])) {
            $tipoUpper = strtoupper((string) $data['tipo_tratamiento']);
            if (in_array($tipoUpper, ['CIRUGIA', 'ENDODONCIA', 'IMPLANTE'], true)) {
                $riesgoTratamiento = 2;
            } elseif (in_array($tipoUpper, ['ORTODONCIA', 'RESTAURACION', 'PROFILAXIS'], true)) {
                $riesgoTratamiento = 1;
            } else {
                $riesgoTratamiento = 0;
            }
        } else {
            $riesgoTratamiento = 1;
            $imputadas[] = 'tipo_tratamiento_riesgo (default: 1)';
        }

        $payload = [
            'edad' => $edad,
            'dias_anticipacion' => $diasAnticipacion,
            'cancelaciones_previas' => $cancelacionesPrevias,
            'dia_semana' => $diaSemana,
            'hora_cita' => $horaCita,
            'banda_horaria' => $bandaHoraria,
            'duracion_minutos' => $duracion,
            'tipo_tratamiento_riesgo' => $riesgoTratamiento,
        ];

        $rawResult = $this->mlService->predictNoShow($payload);

        // Extraer y normalizar campos según especificaciones S10:
        // probabilidadNoShow, nivelRiesgo, accionRecomendada
        $prob = isset($rawResult['probabilidadNoShow'])
            ? (float) $rawResult['probabilidadNoShow']
            : (float) ($rawResult['probabilidad_inasistencia'] ?? 0.5);

        // Umbrales exactos S10:
        // BAJO: prob < 0.35
        // MEDIO: 0.35 <= prob <= 0.65 (0.65 => MEDIO)
        // ALTO: prob > 0.65
        if ($prob > 0.65) {
            $nivelRiesgo = 'ALTO';
            $accionRecomendada = 'Confirmar por teléfono/WhatsApp 24h antes';
        } elseif ($prob >= 0.35) {
            $nivelRiesgo = 'MEDIO';
            $accionRecomendada = 'Enviar recordatorio por WhatsApp 48h antes';
        } else {
            $nivelRiesgo = 'BAJO';
            $accionRecomendada = 'Horario seguro';
        }

        $mergedImputadas = array_values(array_unique(array_merge(
            $imputadas,
            $rawResult['featuresImputadas'] ?? []
        )));

        return array_merge($rawResult, [
            'probabilidadNoShow' => $prob,
            'nivelRiesgo' => $nivelRiesgo,
            'accionRecomendada' => $accionRecomendada,
            'featuresImputadas' => $mergedImputadas,
            // Aliases de compatibilidad heredada
            'probabilidad_inasistencia' => $prob,
            'nivel_riesgo' => $nivelRiesgo,
            'recomendacion_operativa' => $accionRecomendada,
            'datosSinteticos' => true,
        ]);
    }
}
