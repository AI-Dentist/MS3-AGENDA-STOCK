<?php

namespace Tests\Feature;

use App\Domain\Services\MlInferenceServiceInterface;
use App\Infrastructure\Services\MlSidecarUnavailableException;
use Illuminate\Support\Env;
use Mockery;
use Tests\TestCase;

class ModelCardApiTest extends TestCase
{
    public function test_get_model_card_returns_200_with_metrics_and_synthetic_flag(): void
    {
        $mockMl = Mockery::mock(MlInferenceServiceInterface::class);
        $mockMl->shouldReceive('getModelCard')
            ->once()
            ->andReturn([
                'version' => '1.0.0',
                'datosSinteticos' => true,
                'datosEntrenamientoSinteticos' => true,
                'modelos' => [
                    'no_show_prediction' => [
                        'tipo' => 'RandomForestClassifier',
                        'metricas' => [
                            'accuracy' => 0.69,
                            'f1_score' => 0.61,
                        ],
                    ],
                    'patient_clustering' => [
                        'tipo' => 'KMeans',
                        'metricas' => [
                            'silhouette_score' => 0.50,
                        ],
                    ],
                ],
            ]);

        $this->app->instance(MlInferenceServiceInterface::class, $mockMl);

        $response = $this->getJson('/api/agendamiento/ml/model-card');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.datosSinteticos', true)
            ->assertJsonPath('data.datosEntrenamientoSinteticos', true)
            ->assertJsonPath('data.modelos.no_show_prediction.tipo', 'RandomForestClassifier')
            ->assertJsonPath('data.modelos.patient_clustering.tipo', 'KMeans');
    }

    public function test_get_model_card_alias_returns_200(): void
    {
        $mockMl = Mockery::mock(MlInferenceServiceInterface::class);
        $mockMl->shouldReceive('getModelCard')
            ->once()
            ->andReturn(['version' => '1.0.0', 'datosSinteticos' => true]);

        $this->app->instance(MlInferenceServiceInterface::class, $mockMl);

        $response = $this->getJson('/api/agendamiento/ml/model_card');
        $response->assertStatus(200)->assertJsonPath('success', true);
    }

    public function test_get_model_card_returns_503_when_ml_sidecar_is_down(): void
    {
        $mockMl = Mockery::mock(MlInferenceServiceInterface::class);
        $mockMl->shouldReceive('getModelCard')
            ->once()
            ->andThrow(new MlSidecarUnavailableException('FastAPI connection refused'));

        $this->app->instance(MlInferenceServiceInterface::class, $mockMl);

        $response = $this->getJson('/api/agendamiento/ml/model-card');

        $response->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'ML_SERVICE_UNAVAILABLE');
    }

    public function test_get_model_card_requires_authentication_when_enforced(): void
    {
        Env::getRepository()->set('ENFORCE_KEYCLOAK_IN_TESTS', 'true');

        $response = $this->getJson('/api/agendamiento/ml/model-card');

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'UNAUTHORIZED');

        Env::getRepository()->clear('ENFORCE_KEYCLOAK_IN_TESTS');
    }
}
