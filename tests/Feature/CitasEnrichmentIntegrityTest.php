<?php

namespace Tests\Feature;

use App\Domain\Entities\Cita;
use App\Domain\Repositories\CitaRepositoryInterface;
use App\Domain\Services\MlInferenceServiceInterface;
use App\Infrastructure\Services\MlSidecarUnavailableException;
use Mockery;
use Tests\TestCase;

class CitasEnrichmentIntegrityTest extends TestCase
{
    public function test_citas_index_enriches_with_features_imputadas_and_datos_entrenamiento_sinteticos(): void
    {
        $mockCitas = [
            new Cita(
                id: 'cita-s6-1',
                idempotencyKey: 'idem-1',
                odontologoId: 'odo-1',
                consultorioId: 'con-1',
                pacienteId: 'pac-s6',
                fechaHora: '2026-11-20 10:00:00',
                estado: Cita::ESTADO_RESERVADA,
                duracionEstimadaMin: 40,
                tipoTratamiento: 'CIRUGIA',
                reservadaEn: '2026-11-15 08:00:00'
            ),
        ];

        $mockRepo = Mockery::mock(CitaRepositoryInterface::class);
        $mockRepo->shouldReceive('findAll')->andReturn($mockCitas);
        $this->app->instance(CitaRepositoryInterface::class, $mockRepo);

        $mockMl = Mockery::mock(MlInferenceServiceInterface::class);
        $mockMl->shouldReceive('checkHealth')->andReturn(['status' => 'UP']);
        $mockMl->shouldReceive('predictNoShowBatch')
            ->once()
            ->with(Mockery::on(function ($batch) {
                // S6: verificar que el batch derivo dias_anticipacion = 5 (2026-11-20 vs 2026-11-15)
                // y duracion_minutos = 40
                $item = $batch[0];
                return $item['cita_id'] === 'cita-s6-1'
                    && $item['dias_anticipacion'] === 5
                    && $item['duracion_minutos'] === 40
                    && $item['tipo_tratamiento_riesgo'] === 2; // CIRUGIA -> 2
            }))
            ->andReturn([
                'predicciones' => [
                    [
                        'cita_id' => 'cita-s6-1',
                        'probabilidadNoShow' => 0.15,
                        'nivelRiesgo' => 'BAJO',
                        'accionRecomendada' => 'Horario seguro',
                        'featuresImputadas' => ['edad'],
                    ],
                ],
            ]);
        $this->app->instance(MlInferenceServiceInterface::class, $mockMl);

        $response = $this->getJson('/api/agendamiento/citas');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.citas.0.id', 'cita-s6-1')
            ->assertJsonPath('data.citas.0.probabilidadNoShow', 0.15)
            ->assertJsonPath('data.citas.0.nivelRiesgo', 'BAJO')
            ->assertJsonPath('data.citas.0.datosEntrenamientoSinteticos', true);

        $imputadas = $response->json('data.citas.0.featuresImputadas');
        $this->assertIsArray($imputadas);
        $this->assertContains('edad', $imputadas);
    }

    public function test_citas_index_retains_features_imputadas_and_datos_sinteticos_when_ml_sidecar_down(): void
    {
        $mockCitas = [
            new Cita(
                id: 'cita-s6-down',
                idempotencyKey: 'idem-down',
                odontologoId: 'odo-1',
                consultorioId: 'con-1',
                pacienteId: 'pac-down',
                fechaHora: '2026-11-20 10:00:00',
                estado: Cita::ESTADO_RESERVADA
            ),
        ];

        $mockRepo = Mockery::mock(CitaRepositoryInterface::class);
        $mockRepo->shouldReceive('findAll')->andReturn($mockCitas);
        $this->app->instance(CitaRepositoryInterface::class, $mockRepo);

        $mockMl = Mockery::mock(MlInferenceServiceInterface::class);
        $mockMl->shouldReceive('checkHealth')->andThrow(new MlSidecarUnavailableException('Sidecar offline'));
        $this->app->instance(MlInferenceServiceInterface::class, $mockMl);

        $response = $this->getJson('/api/agendamiento/citas');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.mlDisponible', false)
            ->assertJsonPath('data.citas.0.probabilidadNoShow', null)
            ->assertJsonPath('data.citas.0.datosEntrenamientoSinteticos', true);

        $imputadas = $response->json('data.citas.0.featuresImputadas');
        $this->assertIsArray($imputadas);
        $this->assertContains('edad', $imputadas);
        $this->assertContains('anticipacionReserva', $imputadas);
    }

    public function test_predict_noshow_returns_features_imputadas_and_datos_entrenamiento_sinteticos(): void
    {
        $mockMl = Mockery::mock(MlInferenceServiceInterface::class);
        $mockMl->shouldReceive('predictNoShow')
            ->once()
            ->andReturn([
                'probabilidadNoShow' => 0.45,
                'nivelRiesgo' => 'MEDIO',
                'accionRecomendada' => 'Enviar recordatorio por WhatsApp 48h antes',
                'featuresImputadas' => ['edad'],
            ]);
        $this->app->instance(MlInferenceServiceInterface::class, $mockMl);

        $response = $this->postJson('/api/agendamiento/ml/predict-noshow', [
            'fecha_hora' => '2026-11-20 15:00:00',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.datosEntrenamientoSinteticos', true)
            ->assertJsonPath('data.nivelRiesgo', 'MEDIO');

        $imputadas = $response->json('data.featuresImputadas');
        $this->assertIsArray($imputadas);
        $this->assertContains('edad', $imputadas);
    }
}
