<?php

namespace App\Application\UseCases;

use App\Domain\Entities\Cita;
use App\Domain\Repositories\CitaRepositoryInterface;
use App\Domain\Services\CitaFeatureExtractor;
use App\Domain\Services\MlInferenceServiceInterface;

class PredecirNoShowUseCase
{
    private CitaFeatureExtractor $featureExtractor;

    public function __construct(
        private MlInferenceServiceInterface $mlService,
        private ?CitaRepositoryInterface $citaRepository = null,
        ?CitaFeatureExtractor $featureExtractor = null
    ) {
        $this->featureExtractor = $featureExtractor ?? new CitaFeatureExtractor();
    }

    public function execute(array $data): array
    {
        $allCitas = ($this->citaRepository !== null) ? $this->citaRepository->findAll() : null;
        $extracted = $this->featureExtractor->extract($data, $allCitas);
        $payload = $extracted['payload'];
        $imputadas = $extracted['featuresImputadas'];

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
            'datosEntrenamientoSinteticos' => true,
            'datosSinteticos' => true,
            // Aliases de compatibilidad heredada
            'probabilidad_inasistencia' => $prob,
            'nivel_riesgo' => $nivelRiesgo,
            'recomendacion_operativa' => $accionRecomendada,
        ]);
    }
}
