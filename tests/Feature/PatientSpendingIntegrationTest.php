<?php

namespace Tests\Feature;

use App\Domain\Entities\Cita;
use App\Domain\Entities\Insumo;
use App\Domain\Entities\MovimientoStock;
use App\Domain\Repositories\CitaRepositoryInterface;
use App\Domain\Repositories\InsumoRepositoryInterface;
use App\Domain\Repositories\MovimientoStockRepositoryInterface;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class PatientSpendingIntegrationTest extends TestCase
{
    private string $patientUuid = 'c1a2b3c4-0000-0000-0000-000000000001';
    private string $devSecret = 'ms3-agenda-stock-local-jwt-secret-key-2026';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ms1.graphql_url' => 'http://127.0.0.1:8081/graphql']);
        config(['services.ms1.timeout_ms' => 2000]);

        $mockCitas = [
            new Cita('c1', 'idem-1', 'odo-1', 'con-1', $this->patientUuid, '2026-10-01 10:00:00', Cita::ESTADO_COMPLETADA),
            new Cita('c2', 'idem-2', 'odo-1', 'con-1', $this->patientUuid, '2026-10-21 10:00:00', Cita::ESTADO_COMPLETADA),
            new Cita('c3', 'idem-3', 'odo-1', 'con-1', $this->patientUuid, '2026-11-10 10:00:00', Cita::ESTADO_COMPLETADA),
        ];

        $mockInsumos = [
            new Insumo('ins-1', 'GUAN-01', 'Guantes Latex', 100, 20, 'Cajas'),
        ];

        $citasRepo = Mockery::mock(CitaRepositoryInterface::class);
        $citasRepo->shouldReceive('findAll')->andReturn($mockCitas);
        $this->app->instance(CitaRepositoryInterface::class, $citasRepo);

        $insumoRepo = Mockery::mock(InsumoRepositoryInterface::class);
        $insumoRepo->shouldReceive('findAll')->andReturn($mockInsumos);
        $this->app->instance(InsumoRepositoryInterface::class, $insumoRepo);

        $movRepo = Mockery::mock(MovimientoStockRepositoryInterface::class);
        $movRepo->shouldReceive('findAll')->andReturn([]);
        $this->app->instance(MovimientoStockRepositoryInterface::class, $movRepo);
    }

    private function createValidToken(): string
    {
        $payload = [
            'iss' => 'http://localhost:8180/realms/dental-platform',
            'aud' => 'ms3-agenda-stock',
            'sub' => 'usr-12345',
            'preferred_username' => 'admin.test',
            'email' => 'admin@dental.com',
            'realm_access' => [
                'roles' => ['ADMIN'],
            ],
            'exp' => time() + 3600,
        ];

        return JWT::encode($payload, $this->devSecret, 'HS256');
    }

    public function test_clusters_with_ms1_verified_cash_spending(): void
    {
        Http::fake([
            'http://127.0.0.1:8081/graphql' => Http::response([
                'data' => [
                    'obtenerGastoPagadoPaciente' => [
                        'pacienteId' => $this->patientUuid,
                        'moneda' => 'BOB',
                        'completo' => true,
                        'totalPagadoBs' => 240.00,
                    ],
                ],
            ], 200),
            'http://127.0.0.1:8005/batch-cluster' => function ($request) {
                $payload = json_decode($request->body(), true);
                $pacienteItem = $payload['pacientes'][0];
                return Http::response([
                    'pacientes' => [
                        'centroides' => [],
                        'distribucion' => ['Frecuente y Puntual' => 1],
                        'asignaciones' => [
                            [
                                'paciente_id' => $pacienteItem['paciente_id'],
                                'cluster' => 'Frecuente y Puntual',
                                'cluster_id' => 0,
                                'features' => $pacienteItem,
                                'featuresImputadas' => $pacienteItem['featuresImputadas'],
                                'featuresEstimadas' => $pacienteItem['featuresEstimadas'],
                                'gasto_total_real' => $pacienteItem['gasto_total_real'],
                                'gasto_total_estimado' => $pacienteItem['gasto_total_estimado'],
                                'gasto_moneda' => $pacienteItem['gasto_moneda'],
                                'gasto_origen' => $pacienteItem['gasto_origen'],
                                'gasto_real_observado' => $pacienteItem['gasto_real_observado'],
                            ],
                        ],
                    ],
                    'insumos' => [
                        'centroides' => [],
                        'distribucion' => ['Alta Rotación Crítica' => 1],
                        'asignaciones' => [],
                    ],
                    'modeloVersion' => 'kmeans-v2.0',
                    'datosSinteticos' => true,
                    'datosEntrenamientoSinteticos' => true,
                ], 200);
            },
        ]);

        $token = $this->createValidToken();
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/agendamiento/ml/clusters');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.pacientes.asignaciones.0.paciente_id', $this->patientUuid)
            ->assertJsonPath('data.pacientes.asignaciones.0.gasto_total_real', 240)
            ->assertJsonPath('data.pacientes.asignaciones.0.gasto_total_estimado', 180)
            ->assertJsonPath('data.pacientes.asignaciones.0.gasto_origen', 'real_ms1_pagado')
            ->assertJsonPath('data.pacientes.asignaciones.0.gasto_real_observado', true)
            ->assertJsonPath('data.pacientes.asignaciones.0.featuresEstimadas.0', 'gasto_total_estimado');
    }

    public function test_clusters_with_ms1_empty_history_returns_observed_zero(): void
    {
        Http::fake([
            'http://127.0.0.1:8081/graphql' => Http::response([
                'data' => [
                    'obtenerGastoPagadoPaciente' => [
                        'pacienteId' => $this->patientUuid,
                        'moneda' => 'BOB',
                        'completo' => true,
                        'totalPagadoBs' => 0.00,
                    ],
                ],
            ], 200),
            'http://127.0.0.1:8005/batch-cluster' => function ($request) {
                $payload = json_decode($request->body(), true);
                $pacienteItem = $payload['pacientes'][0];
                return Http::response([
                    'pacientes' => [
                        'centroides' => [],
                        'distribucion' => ['Frecuente y Puntual' => 1],
                        'asignaciones' => [
                            [
                                'paciente_id' => $pacienteItem['paciente_id'],
                                'cluster' => 'Frecuente y Puntual',
                                'cluster_id' => 0,
                                'features' => $pacienteItem,
                                'featuresImputadas' => $pacienteItem['featuresImputadas'],
                                'featuresEstimadas' => $pacienteItem['featuresEstimadas'],
                                'gasto_total_real' => $pacienteItem['gasto_total_real'],
                                'gasto_total_estimado' => $pacienteItem['gasto_total_estimado'],
                                'gasto_moneda' => $pacienteItem['gasto_moneda'],
                                'gasto_origen' => $pacienteItem['gasto_origen'],
                                'gasto_real_observado' => $pacienteItem['gasto_real_observado'],
                            ],
                        ],
                    ],
                    'insumos' => [
                        'centroides' => [],
                        'distribucion' => ['Alta Rotación Crítica' => 1],
                        'asignaciones' => [],
                    ],
                    'modeloVersion' => 'kmeans-v2.0',
                    'datosSinteticos' => true,
                    'datosEntrenamientoSinteticos' => true,
                ], 200);
            },
        ]);

        $token = $this->createValidToken();
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/agendamiento/ml/clusters');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.pacientes.asignaciones.0.gasto_total_real', 0)
            ->assertJsonPath('data.pacientes.asignaciones.0.gasto_origen', 'real_ms1_pagado')
            ->assertJsonPath('data.pacientes.asignaciones.0.gasto_real_observado', true);
    }

    public function test_clusters_with_ms1_incomplete_history_fails_closed_to_estimated(): void
    {
        Http::fake([
            'http://127.0.0.1:8081/graphql' => Http::response([
                'data' => [
                    'obtenerGastoPagadoPaciente' => [
                        'pacienteId' => $this->patientUuid,
                        'moneda' => 'BOB',
                        'completo' => false,
                        'totalPagadoBs' => null,
                    ],
                ],
            ], 200),
            'http://127.0.0.1:8005/batch-cluster' => function ($request) {
                $payload = json_decode($request->body(), true);
                $pacienteItem = $payload['pacientes'][0];
                return Http::response([
                    'pacientes' => [
                        'centroides' => [],
                        'distribucion' => ['Frecuente y Puntual' => 1],
                        'asignaciones' => [
                            [
                                'paciente_id' => $pacienteItem['paciente_id'],
                                'cluster' => 'Frecuente y Puntual',
                                'cluster_id' => 0,
                                'features' => $pacienteItem,
                                'featuresImputadas' => $pacienteItem['featuresImputadas'],
                                'featuresEstimadas' => $pacienteItem['featuresEstimadas'],
                                'gasto_total_real' => $pacienteItem['gasto_total_real'],
                                'gasto_total_estimado' => $pacienteItem['gasto_total_estimado'],
                                'gasto_moneda' => $pacienteItem['gasto_moneda'],
                                'gasto_origen' => $pacienteItem['gasto_origen'],
                                'gasto_real_observado' => $pacienteItem['gasto_real_observado'],
                            ],
                        ],
                    ],
                    'insumos' => [
                        'centroides' => [],
                        'distribucion' => ['Alta Rotación Crítica' => 1],
                        'asignaciones' => [],
                    ],
                    'modeloVersion' => 'kmeans-v2.0',
                    'datosSinteticos' => true,
                    'datosEntrenamientoSinteticos' => true,
                ], 200);
            },
        ]);

        $token = $this->createValidToken();
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/agendamiento/ml/clusters');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.pacientes.asignaciones.0.gasto_total_real', null)
            ->assertJsonPath('data.pacientes.asignaciones.0.gasto_origen', 'estimado_fallback')
            ->assertJsonPath('data.pacientes.asignaciones.0.gasto_real_observado', false)
            ->assertJsonPath('data.pacientes.asignaciones.0.featuresEstimadas.0', 'gasto_total_estimado');
    }

    public function test_clusters_with_ms1_401_fails_closed_to_estimated(): void
    {
        Http::fake([
            'http://127.0.0.1:8081/graphql' => Http::response(['error' => 'Unauthorized'], 401),
            'http://127.0.0.1:8005/batch-cluster' => function ($request) {
                $payload = json_decode($request->body(), true);
                $pacienteItem = $payload['pacientes'][0];
                return Http::response([
                    'pacientes' => [
                        'centroides' => [],
                        'distribucion' => ['Frecuente y Puntual' => 1],
                        'asignaciones' => [
                            [
                                'paciente_id' => $pacienteItem['paciente_id'],
                                'cluster' => 'Frecuente y Puntual',
                                'cluster_id' => 0,
                                'features' => $pacienteItem,
                                'featuresImputadas' => $pacienteItem['featuresImputadas'],
                                'featuresEstimadas' => $pacienteItem['featuresEstimadas'],
                                'gasto_total_real' => $pacienteItem['gasto_total_real'],
                                'gasto_total_estimado' => $pacienteItem['gasto_total_estimado'],
                                'gasto_moneda' => $pacienteItem['gasto_moneda'],
                                'gasto_origen' => $pacienteItem['gasto_origen'],
                                'gasto_real_observado' => $pacienteItem['gasto_real_observado'],
                            ],
                        ],
                    ],
                    'insumos' => [
                        'centroides' => [],
                        'distribucion' => ['Alta Rotación Crítica' => 1],
                        'asignaciones' => [],
                    ],
                    'modeloVersion' => 'kmeans-v2.0',
                    'datosSinteticos' => true,
                    'datosEntrenamientoSinteticos' => true,
                ], 200);
            },
        ]);

        $token = $this->createValidToken();
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/agendamiento/ml/clusters');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.pacientes.asignaciones.0.gasto_total_real', null)
            ->assertJsonPath('data.pacientes.asignaciones.0.gasto_origen', 'estimado_fallback')
            ->assertJsonPath('data.pacientes.asignaciones.0.gasto_real_observado', false)
            ->assertJsonPath('data.pacientes.asignaciones.0.featuresEstimadas.0', 'gasto_total_estimado');
    }

    public function test_clusters_without_bearer_token_fails_closed_to_estimated(): void
    {
        Http::fake([
            'http://127.0.0.1:8005/batch-cluster' => function ($request) {
                $payload = json_decode($request->body(), true);
                $pacienteItem = $payload['pacientes'][0];
                return Http::response([
                    'pacientes' => [
                        'centroides' => [],
                        'distribucion' => ['Frecuente y Puntual' => 1],
                        'asignaciones' => [
                            [
                                'paciente_id' => $pacienteItem['paciente_id'],
                                'cluster' => 'Frecuente y Puntual',
                                'cluster_id' => 0,
                                'features' => $pacienteItem,
                                'featuresImputadas' => $pacienteItem['featuresImputadas'],
                                'featuresEstimadas' => $pacienteItem['featuresEstimadas'],
                                'gasto_total_real' => $pacienteItem['gasto_total_real'],
                                'gasto_total_estimado' => $pacienteItem['gasto_total_estimado'],
                                'gasto_moneda' => $pacienteItem['gasto_moneda'],
                                'gasto_origen' => $pacienteItem['gasto_origen'],
                                'gasto_real_observado' => $pacienteItem['gasto_real_observado'],
                            ],
                        ],
                    ],
                    'insumos' => [
                        'centroides' => [],
                        'distribucion' => ['Alta Rotación Crítica' => 1],
                        'asignaciones' => [],
                    ],
                    'modeloVersion' => 'kmeans-v2.0',
                    'datosSinteticos' => true,
                    'datosEntrenamientoSinteticos' => true,
                ], 200);
            },
        ]);

        $response = $this->getJson('/api/agendamiento/ml/clusters');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.pacientes.asignaciones.0.gasto_total_real', null)
            ->assertJsonPath('data.pacientes.asignaciones.0.gasto_origen', 'estimado_fallback')
            ->assertJsonPath('data.pacientes.asignaciones.0.gasto_real_observado', false)
            ->assertJsonPath('data.pacientes.asignaciones.0.featuresEstimadas.0', 'gasto_total_estimado');
    }
}
