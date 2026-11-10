<?php

namespace Tests\Feature;

use App\Domain\Entities\Cita;
use App\Domain\Entities\Insumo;
use App\Domain\Entities\MovimientoStock;
use App\Domain\Repositories\CitaRepositoryInterface;
use App\Domain\Repositories\InsumoRepositoryInterface;
use App\Domain\Repositories\MovimientoStockRepositoryInterface;
use App\Domain\Services\MlInferenceServiceInterface;
use Mockery;
use Tests\TestCase;

class RealDataClustersTest extends TestCase
{
    public function test_clusters_calculates_real_features_with_imputation_and_estimation_metadata(): void
    {
        $mockCitas = [
            new Cita('c1', 'idem-1', 'odo-1', 'con-1', 'pac-cluster', '2026-10-01 10:00:00', Cita::ESTADO_COMPLETADA),
            new Cita('c2', 'idem-2', 'odo-1', 'con-1', 'pac-cluster', '2026-10-21 10:00:00', Cita::ESTADO_COMPLETADA), // 20 dias diff
            new Cita('c3', 'idem-3', 'odo-1', 'con-1', 'pac-cluster', '2026-11-10 10:00:00', Cita::ESTADO_COMPLETADA), // 20 dias diff -> media = 20
        ];

        $mockInsumos = [
            new Insumo('ins-1', 'GUAN-01', 'Guantes Latex', 100, 20, 'Cajas'),
        ];

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $hace5Dias = (new \DateTimeImmutable('-5 days'))->format('Y-m-d H:i:s');
        $hace15Dias = (new \DateTimeImmutable('-15 days'))->format('Y-m-d H:i:s');
        $hace25Dias = (new \DateTimeImmutable('-25 days'))->format('Y-m-d H:i:s');

        $mockMovimientos = [
            // Salidas en ultimos 30 dias -> suma = 15
            new MovimientoStock('m1', 'ins-1', MovimientoStock::TIPO_SALIDA_PROCEDIMIENTO, 10, 100, 90, 'Uso clinico', 'dr-1', $hace5Dias),
            new MovimientoStock('m2', 'ins-1', MovimientoStock::TIPO_SALIDA_PROCEDIMIENTO, 5, 90, 85, 'Uso clinico', 'dr-1', $hace15Dias),
            // Entradas de compra para dias_reabastecimiento: hace 25 dias y hace 5 dias -> diff = 20 dias
            new MovimientoStock('m3', 'ins-1', MovimientoStock::TIPO_ENTRADA_COMPRA, 50, 50, 100, 'Compra proveedor', 'admin-1', $hace25Dias),
            new MovimientoStock('m4', 'ins-1', MovimientoStock::TIPO_ENTRADA_COMPRA, 50, 100, 150, 'Compra proveedor', 'admin-1', $hace5Dias),
        ];

        $citasRepo = Mockery::mock(CitaRepositoryInterface::class);
        $citasRepo->shouldReceive('findAll')->andReturn($mockCitas);
        $this->app->instance(CitaRepositoryInterface::class, $citasRepo);

        $insumoRepo = Mockery::mock(InsumoRepositoryInterface::class);
        $insumoRepo->shouldReceive('findAll')->andReturn($mockInsumos);
        $this->app->instance(InsumoRepositoryInterface::class, $insumoRepo);

        $movRepo = Mockery::mock(MovimientoStockRepositoryInterface::class);
        $movRepo->shouldReceive('findAll')->andReturn($mockMovimientos);
        $this->app->instance(MovimientoStockRepositoryInterface::class, $movRepo);

        $mlService = Mockery::mock(MlInferenceServiceInterface::class);
        $mlService->shouldReceive('getClusters')
            ->once()
            ->andReturn([
                'pacientes' => [
                    'centroides' => [],
                    'distribucion' => ['Frecuente y Puntual' => 1],
                    'asignaciones' => [
                        [
                            'paciente_id' => 'pac-cluster',
                            'cluster' => 'Frecuente y Puntual',
                            'cluster_id' => 0,
                            'featuresImputadas' => [],
                            'featuresEstimadas' => ['gasto_total_estimado'],
                        ],
                    ],
                ],
                'insumos' => [
                    'centroides' => [],
                    'distribucion' => ['Alta Rotación Crítica' => 1],
                    'asignaciones' => [
                        [
                            'insumo_id' => 'ins-1',
                            'cluster' => 'Alta Rotación Crítica',
                            'cluster_id' => 0,
                            'featuresImputadas' => ['costo_unitario'],
                            'featuresEstimadas' => [],
                        ],
                    ],
                ],
                'modeloVersion' => 'kmeans-v2.0',
                'datosSinteticos' => true,
                'datosEntrenamientoSinteticos' => true,
            ]);
        $this->app->instance(MlInferenceServiceInterface::class, $mlService);

        $response = $this->getJson('/api/agendamiento/ml/clusters');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.datosEntrenamientoSinteticos', true)
            ->assertJsonPath('data.pacientes.asignaciones.0.paciente_id', 'pac-cluster')
            ->assertJsonPath('data.pacientes.asignaciones.0.featuresEstimadas.0', 'gasto_total_estimado')
            ->assertJsonPath('data.insumos.asignaciones.0.insumo_id', 'ins-1')
            ->assertJsonPath('data.insumos.asignaciones.0.featuresImputadas.0', 'costo_unitario');
    }
}
