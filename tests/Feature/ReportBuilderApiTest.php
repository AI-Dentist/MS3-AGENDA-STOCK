<?php

namespace Tests\Feature;

use App\Domain\Entities\Cita;
use App\Domain\Entities\Insumo;
use App\Domain\Entities\MovimientoStock;
use App\Domain\Repositories\CitaRepositoryInterface;
use App\Domain\Repositories\InsumoRepositoryInterface;
use App\Domain\Repositories\MovimientoStockRepositoryInterface;
use App\Domain\Repositories\StockMovementWriterInterface;
use App\Domain\Services\MlInferenceServiceInterface;
use App\Http\Middleware\KeycloakAuthMiddleware;
use App\Infrastructure\Services\MlSidecarUnavailableException;
use Firebase\JWT\JWT;
use Illuminate\Support\Env;
use Mockery;
use Tests\TestCase;

class ReportBuilderApiTest extends TestCase
{
    private function createTokenWithRoles(array $roles): string
    {
        $payload = [
            'iss' => 'http://localhost:8180/realms/dental-platform',
            'aud' => 'ms3-agenda-stock',
            'sub' => 'usr-report-test',
            'preferred_username' => 'report.admin',
            'email' => 'admin@dental.local',
            'realm_access' => [
                'roles' => $roles,
            ],
            'exp' => time() + 3600,
        ];

        return JWT::encode($payload, KeycloakAuthMiddleware::DEFAULT_DEV_SECRET, 'HS256');
    }

    public function test_reportes_requires_authentication_when_enforced(): void
    {
        Env::getRepository()->set('ENFORCE_KEYCLOAK_IN_TESTS', 'true');

        try {
            $response = $this->getJson('/api/agendamiento/reportes/citas');

            $response->assertStatus(401)
                ->assertJsonPath('success', false)
                ->assertJsonPath('code', 'UNAUTHORIZED');
        } finally {
            Env::getRepository()->clear('ENFORCE_KEYCLOAK_IN_TESTS');
        }
    }

    public function test_reportes_requires_admin_role_returns_403_for_non_admin(): void
    {
        $token = $this->createTokenWithRoles(['RECEPCIONISTA']);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/agendamiento/reportes/citas');

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'FORBIDDEN_ROLE');
    }

    public function test_reportes_returns_404_for_unknown_entities(): void
    {
        $token = $this->createTokenWithRoles(['ADMIN']);

        foreach (['pacientes', 'cuotas', 'pagos', 'egresos', 'servicios', 'desconocido'] as $unknownEntity) {
            $response = $this->withHeader('Authorization', 'Bearer '.$token)
                ->getJson('/api/agendamiento/reportes/'.$unknownEntity);

            $response->assertStatus(404);
        }
    }

    public function test_reportes_returns_422_when_only_one_period_boundary_is_provided(): void
    {
        $token = $this->createTokenWithRoles(['ADMIN']);

        // Only desde
        $res1 = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/agendamiento/reportes/citas?desde=2026-11-01');
        $res1->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'INVALID_PERIOD');

        // Only hasta
        $res2 = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/agendamiento/reportes/citas?hasta=2026-11-30');
        $res2->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'INVALID_PERIOD');
    }

    public function test_reportes_returns_422_for_invalid_period_dates(): void
    {
        $token = $this->createTokenWithRoles(['ADMIN']);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/agendamiento/reportes/citas?desde=not-a-date&hasta=2026-11-30');

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'INVALID_PERIOD');
    }

    public function test_reportes_returns_422_when_desde_is_after_hasta(): void
    {
        $token = $this->createTokenWithRoles(['ADMIN']);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/agendamiento/reportes/citas?desde=2026-11-30&hasta=2026-11-01');

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'INVALID_PERIOD');
    }

    public function test_reportes_returns_422_when_period_exceeds_366_days(): void
    {
        $token = $this->createTokenWithRoles(['ADMIN']);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/agendamiento/reportes/citas?desde=2025-01-01&hasta=2026-01-05');

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'PERIOD_TOO_LONG');
    }

    public function test_reportes_returns_422_when_estado_is_invalid_for_citas(): void
    {
        $token = $this->createTokenWithRoles(['ADMIN']);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/agendamiento/reportes/citas?estado=ESTADO_FANTASMA');

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'INVALID_ESTADO');
    }

    public function test_reportes_returns_422_when_estado_is_passed_for_insumos(): void
    {
        $token = $this->createTokenWithRoles(['ADMIN']);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/agendamiento/reportes/insumos?estado=COMPLETADA');

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'INVALID_FILTER');
    }

    public function test_reportes_citas_happy_path_with_period_and_estado_filter_and_ml_risk(): void
    {
        $token = $this->createTokenWithRoles(['ADMIN']);

        $mockCitas = [
            new Cita('c1', 'idem-1', 'odo-1', 'con-1', 'pac-1', '2026-11-05 10:00:00', Cita::ESTADO_COMPLETADA, 45, 'Limpieza', '2026-10-30 08:00:00'),
            new Cita('c2', 'idem-2', 'odo-1', 'con-1', 'pac-2', '2026-11-15 11:00:00', Cita::ESTADO_NO_ASISTIO, 30, 'Consulta', '2026-11-01 09:00:00'),
            new Cita('c3', 'idem-3', 'odo-2', 'con-2', 'pac-3', '2026-11-20 16:00:00', Cita::ESTADO_COMPLETADA, 60, 'Endodoncia', '2026-11-10 10:00:00'),
            // Outside period
            new Cita('c-out', 'idem-out', 'odo-1', 'con-1', 'pac-4', '2026-10-25 10:00:00', Cita::ESTADO_COMPLETADA),
        ];

        $citasRepo = Mockery::mock(CitaRepositoryInterface::class);
        $citasRepo->shouldReceive('findAll')->andReturn($mockCitas);
        $this->app->instance(CitaRepositoryInterface::class, $citasRepo);

        $mlService = Mockery::mock(MlInferenceServiceInterface::class);
        $mlService->shouldReceive('checkHealth')->andReturn(['status' => 'UP']);
        $mlService->shouldReceive('predictNoShowBatch')->andReturn([
            'predicciones' => [
                [
                    'cita_id' => 'c1',
                    'probabilidadNoShow' => 0.12,
                    'nivelRiesgo' => 'BAJO',
                ],
                [
                    'cita_id' => 'c3',
                    'probabilidadNoShow' => 0.78,
                    'nivelRiesgo' => 'ALTO',
                ],
            ],
        ]);
        $this->app->instance(MlInferenceServiceInterface::class, $mlService);

        // Filter: period November 2026, estado COMPLETADA
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/agendamiento/reportes/citas?desde=2026-11-01&hasta=2026-11-30&estado=COMPLETADA');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.entidad', 'citas')
            ->assertJsonPath('data.total', 2);

        // Columns definition
        $columnas = $response->json('data.columnas');
        $claves = array_column($columnas, 'clave');
        $this->assertContains('citaId', $claves);
        $this->assertContains('pacienteId', $claves);
        $this->assertContains('odontologoId', $claves);
        $this->assertContains('consultorioId', $claves);
        $this->assertContains('fechaHora', $claves);
        $this->assertContains('duracionMin', $claves);
        $this->assertContains('tipoTratamiento', $claves);
        $this->assertContains('estado', $claves);
        $this->assertContains('reservadaEn', $claves);
        $this->assertContains('probabilidadNoShow', $claves);
        $this->assertContains('nivelRiesgo', $claves);

        // Rows
        $filas = $response->json('data.filas');
        $this->assertCount(2, $filas);

        $fila1 = $filas[0];
        $this->assertSame('c1', $fila1['citaId']);
        $this->assertSame('pac-1', $fila1['pacienteId']);
        $this->assertSame('odo-1', $fila1['odontologoId']);
        $this->assertSame('con-1', $fila1['consultorioId']);
        $this->assertSame('COMPLETADA', $fila1['estado']);
        $this->assertSame(45, $fila1['duracionMin']);
        $this->assertSame('Limpieza', $fila1['tipoTratamiento']);
        $this->assertSame('2026-10-30 08:00:00', $fila1['reservadaEn']);
        $this->assertEquals(0.12, $fila1['probabilidadNoShow']);
        $this->assertSame('BAJO', $fila1['nivelRiesgo']);

        $fila2 = $filas[1];
        $this->assertSame('c3', $fila2['citaId']);
        $this->assertEquals(0.78, $fila2['probabilidadNoShow']);
        $this->assertSame('ALTO', $fila2['nivelRiesgo']);
    }

    public function test_reportes_citas_graceful_null_risk_when_ml_sidecar_down(): void
    {
        $token = $this->createTokenWithRoles(['ADMIN']);

        $mockCitas = [
            new Cita('c1', 'idem-1', 'odo-1', 'con-1', 'pac-1', '2026-11-05 10:00:00', Cita::ESTADO_COMPLETADA),
        ];

        $citasRepo = Mockery::mock(CitaRepositoryInterface::class);
        $citasRepo->shouldReceive('findAll')->andReturn($mockCitas);
        $this->app->instance(CitaRepositoryInterface::class, $citasRepo);

        $mlService = Mockery::mock(MlInferenceServiceInterface::class);
        $mlService->shouldReceive('checkHealth')->andThrow(new MlSidecarUnavailableException('Sidecar offline'));
        $this->app->instance(MlInferenceServiceInterface::class, $mlService);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/agendamiento/reportes/citas');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.filas.0.citaId', 'c1')
            ->assertJsonPath('data.filas.0.probabilidadNoShow', null)
            ->assertJsonPath('data.filas.0.nivelRiesgo', null);
    }

    public function test_reportes_citas_without_period_returns_all_citas(): void
    {
        $token = $this->createTokenWithRoles(['ADMIN']);

        $mockCitas = [
            new Cita('c1', 'idem-1', 'odo-1', 'con-1', 'pac-1', '2025-05-01 10:00:00', Cita::ESTADO_COMPLETADA),
            new Cita('c2', 'idem-2', 'odo-1', 'con-1', 'pac-2', '2026-11-15 11:00:00', Cita::ESTADO_RESERVADA),
        ];

        $citasRepo = Mockery::mock(CitaRepositoryInterface::class);
        $citasRepo->shouldReceive('findAll')->andReturn($mockCitas);
        $this->app->instance(CitaRepositoryInterface::class, $citasRepo);

        $mlService = Mockery::mock(MlInferenceServiceInterface::class);
        $mlService->shouldReceive('checkHealth')->andReturn(['status' => 'DOWN']);
        $this->app->instance(MlInferenceServiceInterface::class, $mlService);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/agendamiento/reportes/citas');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total', 2);
    }

    public function test_reportes_insumos_happy_path_with_salidas_and_stock_metrics(): void
    {
        $token = $this->createTokenWithRoles(['ADMIN']);

        $mockInsumos = [
            new Insumo('ins-1', 'GUAN-01', 'Guantes Latex', 2, 5, 'Cajas'),
            new Insumo('ins-2', 'ANEST-01', 'Anestesia', 15, 5, 'Ampollas'),
            new Insumo('ins-3', 'BARB-01', 'Barbijos', 1, 3, 'Cajas'),
        ];

        $mockMovimientos = [
            new MovimientoStock('m1', 'ins-1', MovimientoStock::TIPO_SALIDA_PROCEDIMIENTO, 10, 12, 2, 'Uso clinico', 'dr-1', '2026-11-05 10:00:00'),
            new MovimientoStock('m2', 'ins-1', MovimientoStock::TIPO_SALIDA_PROCEDIMIENTO, 5, 2, 0, 'Uso clinico', 'dr-1', '2026-11-15 11:00:00'),
            new MovimientoStock('m3', 'ins-2', MovimientoStock::TIPO_SALIDA_PROCEDIMIENTO, 20, 35, 15, 'Procedimiento', 'dr-1', '2026-11-20 15:00:00'),
            // Entrada (no debe sumarse a salidas)
            new MovimientoStock('m4', 'ins-1', MovimientoStock::TIPO_ENTRADA_COMPRA, 50, 0, 50, 'Compra', 'admin-1', '2026-11-10 09:00:00'),
            // Salida fuera de período
            new MovimientoStock('m5', 'ins-1', MovimientoStock::TIPO_SALIDA_PROCEDIMIENTO, 100, 150, 50, 'Uso viejo', 'dr-1', '2026-10-25 10:00:00'),
        ];

        $insumosRepo = Mockery::mock(InsumoRepositoryInterface::class);
        $insumosRepo->shouldReceive('findAll')->andReturn($mockInsumos);
        $this->app->instance(InsumoRepositoryInterface::class, $insumosRepo);

        $movsRepo = Mockery::mock(MovimientoStockRepositoryInterface::class);
        $movsRepo->shouldReceive('findAll')->andReturn($mockMovimientos);
        $this->app->instance(MovimientoStockRepositoryInterface::class, $movsRepo);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/agendamiento/reportes/insumos?desde=2026-11-01&hasta=2026-11-30');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.entidad', 'insumos')
            ->assertJsonPath('data.total', 3);

        $columnas = $response->json('data.columnas');
        $claves = array_column($columnas, 'clave');
        $this->assertContains('insumoId', $claves);
        $this->assertContains('nombre', $claves);
        $this->assertContains('unidad', $claves);
        $this->assertContains('stockActual', $claves);
        $this->assertContains('stockMinimo', $claves);
        $this->assertContains('bajoMinimo', $claves);
        $this->assertContains('salidasPeriodo', $claves);

        // Sorting: bajoMinimo true first, then salidasPeriodo desc
        // 1. ins-1: bajoMinimo=true (2 <= 5), salidas=15
        // 2. ins-3: bajoMinimo=true (1 <= 3), salidas=0
        // 3. ins-2: bajoMinimo=false (15 > 5), salidas=20
        $filas = $response->json('data.filas');
        $this->assertCount(3, $filas);

        $this->assertSame('ins-1', $filas[0]['insumoId']);
        $this->assertTrue($filas[0]['bajoMinimo']);
        $this->assertSame(15, $filas[0]['salidasPeriodo']);
        $this->assertSame('Cajas', $filas[0]['unidad']);

        $this->assertSame('ins-3', $filas[1]['insumoId']);
        $this->assertTrue($filas[1]['bajoMinimo']);
        $this->assertSame(0, $filas[1]['salidasPeriodo']);

        $this->assertSame('ins-2', $filas[2]['insumoId']);
        $this->assertFalse($filas[2]['bajoMinimo']);
        $this->assertSame(20, $filas[2]['salidasPeriodo']);
    }

    public function test_reportes_is_strictly_read_only_and_writes_nothing(): void
    {
        $token = $this->createTokenWithRoles(['ADMIN']);

        $citasRepo = Mockery::mock(CitaRepositoryInterface::class);
        $citasRepo->shouldReceive('findAll')->once()->andReturn([]);
        $citasRepo->shouldNotReceive('save', 'delete', 'update', 'insert');
        $this->app->instance(CitaRepositoryInterface::class, $citasRepo);

        $insumosRepo = Mockery::mock(InsumoRepositoryInterface::class);
        $insumosRepo->shouldReceive('findAll')->once()->andReturn([]);
        $insumosRepo->shouldNotReceive('save', 'delete', 'update', 'insert');
        $this->app->instance(InsumoRepositoryInterface::class, $insumosRepo);

        $movsRepo = Mockery::mock(MovimientoStockRepositoryInterface::class);
        $movsRepo->shouldReceive('findAll')->once()->andReturn([]);
        $movsRepo->shouldNotReceive('save', 'delete', 'update', 'insert');
        $this->app->instance(MovimientoStockRepositoryInterface::class, $movsRepo);

        $stockWriter = Mockery::mock(StockMovementWriterInterface::class);
        $stockWriter->shouldNotReceive('applyMovement');
        $this->app->instance(StockMovementWriterInterface::class, $stockWriter);

        $mlService = Mockery::mock(MlInferenceServiceInterface::class);
        $mlService->shouldReceive('checkHealth')->andReturn(['status' => 'DOWN']);
        $this->app->instance(MlInferenceServiceInterface::class, $mlService);

        $response1 = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/agendamiento/reportes/citas');
        $response1->assertStatus(200);

        $response2 = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/agendamiento/reportes/insumos');
        $response2->assertStatus(200);
    }

    public function test_reportes_returns_422_when_desde_or_hasta_are_arrays(): void
    {
        $token = $this->createTokenWithRoles(['ADMIN']);

        // 1. ?desde[]=x&hasta[]=y
        $res1 = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/agendamiento/reportes/citas?desde[]=x&hasta[]=y');
        $res1->assertStatus(422)
            ->assertJsonPath('success', false);

        // 2. ?desde=2026-11-01&hasta[]=y
        $res2 = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/agendamiento/reportes/citas?desde=2026-11-01&hasta[]=y');
        $res2->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_reportes_returns_422_when_estado_is_array(): void
    {
        $token = $this->createTokenWithRoles(['ADMIN']);

        // 3. ?estado[]=x
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/agendamiento/reportes/citas?estado[]=x');
        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_reportes_sanitizes_500_failure_and_redacts_internal_canary_exception(): void
    {
        $token = $this->createTokenWithRoles(['ADMIN']);

        $citasRepo = Mockery::mock(CitaRepositoryInterface::class);
        $citasRepo->shouldReceive('findAll')
            ->andThrow(new \RuntimeException('CANARY_INTERNAL endpoint=http://db.internal.test table=clinic-private'));
        $this->app->instance(CitaRepositoryInterface::class, $citasRepo);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/agendamiento/reportes/citas');

        $response->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'REPORT_GENERATION_ERROR');

        $body = $response->getContent();
        $this->assertStringNotContainsString('CANARY_INTERNAL', $body);
        $this->assertStringNotContainsString('db.internal.test', $body);
        $this->assertStringNotContainsString('clinic-private', $body);
    }
}
