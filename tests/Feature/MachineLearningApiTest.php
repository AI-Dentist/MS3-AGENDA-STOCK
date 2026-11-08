<?php

namespace Tests\Feature;

use App\Domain\Services\MlInferenceServiceInterface;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature tests for ML endpoints (S10 no-show, S11 clusters).
 *
 * Uses a mock of MlInferenceServiceInterface so threshold-boundary tests
 * are deterministic (the real sidecar is not running in CI).
 */
class MachineLearningApiTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a mock that returns a specific probabilidadNoShow for predictNoShow
     * and a canned clusters payload for getClusters.
     */
    private function mockMlService(float $prob, array $clusterPayload = []): MlInferenceServiceInterface
    {
        $mock = $this->createMock(MlInferenceServiceInterface::class);

        $mock->method('predictNoShow')->willReturn([
            'probabilidadNoShow' => $prob,
            'probabilidad_inasistencia' => $prob,
            'prediccion_inasistencia' => $prob >= 0.5,
            'nivel_riesgo' => $prob > 0.65 ? 'ALTO' : ($prob >= 0.35 ? 'MEDIO' : 'BAJO'),
            'recomendacion_operativa' => 'placeholder',
            'modelo' => 'RandomForestClassifier-v2.0',
            'featuresImputadas' => [],
        ]);

        $mock->method('predictNoShowBatch')->willReturn([
            'predicciones' => [],
        ]);

        $defaultClusters = [
            'pacientes' => [
                'centroides' => [
                    ['cluster' => 'Frecuente y Puntual', 'valores' => []],
                    ['cluster' => 'Riesgo de Abandono / Inconstante', 'valores' => []],
                    ['cluster' => 'Atención Esporádica / Urgencias', 'valores' => []],
                ],
                'distribucion' => ['Frecuente y Puntual' => 10, 'Riesgo de Abandono / Inconstante' => 5, 'Atención Esporádica / Urgencias' => 3],
                'asignaciones' => [],
            ],
            'insumos' => [
                'centroides' => [
                    ['cluster' => 'Alta Rotación Crítica', 'valores' => []],
                    ['cluster' => 'Consumo Estándar', 'valores' => []],
                    ['cluster' => 'Baja Rotación', 'valores' => []],
                ],
                'distribucion' => ['Alta Rotación Crítica' => 5, 'Consumo Estándar' => 3, 'Baja Rotación' => 2],
                'asignaciones' => [],
            ],
            'modeloVersion' => 'K-Means-v2.0',
            'datosSinteticos' => true,
        ];

        $mock->method('getClusters')->willReturn(array_merge($defaultClusters, $clusterPayload));
        $mock->method('checkHealth')->willReturn(['status' => 'UP']);

        return $mock;
    }

    /** Bind a mock ML service into the container for a single test. */
    private function withMockMl(float $prob = 0.5): void
    {
        $this->app->instance(MlInferenceServiceInterface::class, $this->mockMlService($prob));
    }

    // -------------------------------------------------------------------------
    // S10 – predict-noshow route and alias
    // -------------------------------------------------------------------------

    public function test_predict_noshow_primary_route_exists(): void
    {
        $this->withMockMl(0.5);

        $response = $this->postJson('/api/agendamiento/ml/predict-noshow', [
            'cita_id' => 'cita-001',
            'paciente_id' => 'pac-001',
            'fecha_hora' => '2027-01-15 10:00:00',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_predict_noshow_alias_route_works(): void
    {
        $this->withMockMl(0.5);

        $response = $this->postJson('/api/agendamiento/ml/predict-no-show', [
            'cita_id' => 'cita-001',
            'paciente_id' => 'pac-001',
            'fecha_hora' => '2027-01-15 10:00:00',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_predict_noshow_returns_camelcase_fields(): void
    {
        $this->withMockMl(0.5);

        $response = $this->postJson('/api/agendamiento/ml/predict-noshow', [
            'cita_id' => 'cita-001',
            'paciente_id' => 'pac-001',
            'fecha_hora' => '2027-01-15 14:00:00',
        ]);

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertArrayHasKey('probabilidadNoShow', $data, 'Must have probabilidadNoShow field');
        $this->assertArrayHasKey('nivelRiesgo', $data, 'Must have nivelRiesgo field');
        $this->assertArrayHasKey('accionRecomendada', $data, 'Must have accionRecomendada field');
        $this->assertArrayHasKey('featuresImputadas', $data, 'Must have featuresImputadas field');
    }

    // -------------------------------------------------------------------------
    // S10 – threshold boundary tests (PHP-layer mapping)
    // -------------------------------------------------------------------------

    public function test_threshold_bajo_at_0_3499(): void
    {
        $this->withMockMl(0.3499);

        $response = $this->postJson('/api/agendamiento/ml/predict-noshow', [
            'cita_id' => 'cita-001',
            'paciente_id' => 'pac-001',
            'fecha_hora' => '2027-01-15 10:00:00',
        ]);

        $response->assertStatus(200)
            ->assertJson(['data' => ['nivelRiesgo' => 'BAJO']]);
    }

    public function test_threshold_medio_at_0_35(): void
    {
        $this->withMockMl(0.35);

        $response = $this->postJson('/api/agendamiento/ml/predict-noshow', [
            'cita_id' => 'cita-001',
            'paciente_id' => 'pac-001',
            'fecha_hora' => '2027-01-15 10:00:00',
        ]);

        $response->assertStatus(200)
            ->assertJson(['data' => ['nivelRiesgo' => 'MEDIO']]);
    }

    public function test_threshold_medio_at_0_65(): void
    {
        $this->withMockMl(0.65);

        $response = $this->postJson('/api/agendamiento/ml/predict-noshow', [
            'cita_id' => 'cita-001',
            'paciente_id' => 'pac-001',
            'fecha_hora' => '2027-01-15 10:00:00',
        ]);

        $response->assertStatus(200)
            ->assertJson(['data' => ['nivelRiesgo' => 'MEDIO']]);
    }

    public function test_threshold_alto_at_0_6501(): void
    {
        $this->withMockMl(0.6501);

        $response = $this->postJson('/api/agendamiento/ml/predict-noshow', [
            'cita_id' => 'cita-001',
            'paciente_id' => 'pac-001',
            'fecha_hora' => '2027-01-15 10:00:00',
        ]);

        $response->assertStatus(200)
            ->assertJson(['data' => ['nivelRiesgo' => 'ALTO']]);
    }

    public function test_threshold_alto_accion_recomendada(): void
    {
        $this->withMockMl(0.80);

        $response = $this->postJson('/api/agendamiento/ml/predict-noshow', [
            'cita_id' => 'cita-001',
            'paciente_id' => 'pac-001',
            'fecha_hora' => '2027-01-15 10:00:00',
        ]);

        $response->assertStatus(200);
        $accion = $response->json('data.accionRecomendada');
        $this->assertStringContainsString('teléfono', $accion, 'ALTO must mention teléfono/WhatsApp');
        $this->assertStringContainsString('24h', $accion, 'ALTO must mention 24h');
    }

    public function test_threshold_bajo_accion_recomendada(): void
    {
        $this->withMockMl(0.10);

        $response = $this->postJson('/api/agendamiento/ml/predict-noshow', [
            'cita_id' => 'cita-001',
            'paciente_id' => 'pac-001',
            'fecha_hora' => '2027-01-15 10:00:00',
        ]);

        $response->assertStatus(200);
        $accion = $response->json('data.accionRecomendada');
        $this->assertStringContainsString('seguro', strtolower($accion), 'BAJO must mention horario seguro');
    }

    // -------------------------------------------------------------------------
    // S10 – sidecar down -> 503 for ML endpoints
    // -------------------------------------------------------------------------

    public function test_predict_noshow_returns_503_when_sidecar_down(): void
    {
        $mock = $this->createMock(MlInferenceServiceInterface::class);
        $mock->method('predictNoShow')->willThrowException(
            new \App\Infrastructure\Services\MlSidecarUnavailableException('Sidecar no disponible')
        );
        $this->app->instance(MlInferenceServiceInterface::class, $mock);

        $response = $this->postJson('/api/agendamiento/ml/predict-noshow', [
            'cita_id' => 'cita-001',
            'paciente_id' => 'pac-001',
            'fecha_hora' => '2027-01-15 10:00:00',
        ]);

        $response->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'ML_SERVICE_UNAVAILABLE');
    }

    // -------------------------------------------------------------------------
    // S10 – GET citas enriched with ML fields
    // -------------------------------------------------------------------------

    public function test_get_citas_enriched_with_ml_fields_when_sidecar_up(): void
    {
        $this->withMockMl(0.72);

        // Create a cita first
        $key = (string) Str::uuid();
        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/agendamiento/citas', [
                'odontologo_id' => 'odo-ml-test',
                'consultorio_id' => 'con-ml-test',
                'paciente_id' => 'pac-ml-test',
                'fecha_hora' => '2027-02-01 09:00:00',
            ]);

        $response = $this->getJson('/api/agendamiento/citas');

        $response->assertStatus(200);
        $citas = $response->json('data.citas');
        $this->assertNotEmpty($citas);

        $cita = $citas[0];
        $this->assertArrayHasKey('probabilidadNoShow', $cita);
        $this->assertArrayHasKey('nivelRiesgo', $cita);
        $this->assertArrayHasKey('accionRecomendada', $cita);
        $this->assertArrayHasKey('mlDisponible', $response->json('data'));
        $this->assertTrue($response->json('data.mlDisponible'));
    }

    public function test_get_citas_returns_200_with_null_ml_when_sidecar_down(): void
    {
        $mock = $this->createMock(MlInferenceServiceInterface::class);
        $mock->method('predictNoShowBatch')->willThrowException(
            new \App\Infrastructure\Services\MlSidecarUnavailableException('Sidecar down')
        );
        $mock->method('checkHealth')->willReturn(['status' => 'DOWN']);
        $this->app->instance(MlInferenceServiceInterface::class, $mock);

        // Create a cita to ensure there is data
        $key = (string) Str::uuid();
        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/agendamiento/citas', [
                'odontologo_id' => 'odo-down-test',
                'consultorio_id' => 'con-down-test',
                'paciente_id' => 'pac-down-test',
                'fecha_hora' => '2027-02-02 10:00:00',
            ]);

        $response = $this->getJson('/api/agendamiento/citas');

        $response->assertStatus(200);
        $this->assertFalse($response->json('data.mlDisponible'));

        $citas = $response->json('data.citas');
        if (!empty($citas)) {
            $cita = $citas[0];
            $this->assertNull($cita['probabilidadNoShow'] ?? null);
            $this->assertNull($cita['nivelRiesgo'] ?? null);
        }
    }

    // -------------------------------------------------------------------------
    // S11 – clusters endpoint shape and exact names
    // -------------------------------------------------------------------------

    public function test_get_clusters_returns_exact_patient_cluster_names(): void
    {
        $this->withMockMl(0.5);

        $response = $this->getJson('/api/agendamiento/ml/clusters');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $pacientes = $response->json('data.pacientes');
        $this->assertNotNull($pacientes, 'Must have pacientes section');

        $centroides = $pacientes['centroides'];
        $clusterNames = array_column($centroides, 'cluster');
        $this->assertContains('Frecuente y Puntual', $clusterNames);
        $this->assertContains('Riesgo de Abandono / Inconstante', $clusterNames);
        $this->assertContains('Atención Esporádica / Urgencias', $clusterNames);
    }

    public function test_get_clusters_returns_exact_stock_cluster_names(): void
    {
        $this->withMockMl(0.5);

        $response = $this->getJson('/api/agendamiento/ml/clusters');

        $response->assertStatus(200);

        $insumos = $response->json('data.insumos');
        $this->assertNotNull($insumos, 'Must have insumos section');

        $centroides = $insumos['centroides'];
        $clusterNames = array_column($centroides, 'cluster');
        $this->assertContains('Alta Rotación Crítica', $clusterNames);
        $this->assertContains('Consumo Estándar', $clusterNames);
        $this->assertContains('Baja Rotación', $clusterNames);
    }

    public function test_get_clusters_has_distribucion_and_asignaciones(): void
    {
        $this->withMockMl(0.5);

        $response = $this->getJson('/api/agendamiento/ml/clusters');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertArrayHasKey('distribucion', $data['pacientes']);
        $this->assertArrayHasKey('asignaciones', $data['pacientes']);
        $this->assertArrayHasKey('distribucion', $data['insumos']);
        $this->assertArrayHasKey('asignaciones', $data['insumos']);
    }

    public function test_get_clusters_returns_503_when_sidecar_down(): void
    {
        $mock = $this->createMock(MlInferenceServiceInterface::class);
        $mock->method('getClusters')->willThrowException(
            new \App\Infrastructure\Services\MlSidecarUnavailableException('Sidecar down')
        );
        $this->app->instance(MlInferenceServiceInterface::class, $mock);

        $response = $this->getJson('/api/agendamiento/ml/clusters');

        $response->assertStatus(503)
            ->assertJsonPath('code', 'ML_SERVICE_UNAVAILABLE');
    }
}
