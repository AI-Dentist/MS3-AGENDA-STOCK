<?php

namespace Tests\Feature;

use App\Domain\Entities\Cita;
use App\Domain\Entities\Insumo;
use App\Domain\Entities\MovimientoStock;
use App\Domain\Repositories\CitaRepositoryInterface;
use App\Domain\Repositories\InsumoRepositoryInterface;
use App\Domain\Repositories\MovimientoStockRepositoryInterface;
use App\Domain\Repositories\StockMovementWriterInterface;
use App\Http\Middleware\KeycloakAuthMiddleware;
use Firebase\JWT\JWT;
use Illuminate\Support\Env;
use Mockery;
use Tests\TestCase;

class ExecutiveKpisApiTest extends TestCase
{
    private function createTokenWithRoles(array $roles): string
    {
        $payload = [
            'iss' => 'http://localhost:8180/realms/dental-platform',
            'aud' => 'ms3-agenda-stock',
            'sub' => 'usr-kpi-test',
            'preferred_username' => 'kpi.admin',
            'email' => 'admin@dental.local',
            'realm_access' => [
                'roles' => $roles,
            ],
            'exp' => time() + 3600,
        ];

        return JWT::encode($payload, KeycloakAuthMiddleware::DEFAULT_DEV_SECRET, 'HS256');
    }

    public function test_kpis_requires_authentication_when_enforced(): void
    {
        Env::getRepository()->set('ENFORCE_KEYCLOAK_IN_TESTS', 'true');

        try {
            $response = $this->getJson('/api/agendamiento/kpis?desde=2026-11-01&hasta=2026-11-30');

            $response->assertStatus(401)
                ->assertJsonPath('success', false)
                ->assertJsonPath('code', 'UNAUTHORIZED');
        } finally {
            Env::getRepository()->clear('ENFORCE_KEYCLOAK_IN_TESTS');
        }
    }

    public function test_kpis_requires_admin_role_returns_403_for_non_admin(): void
    {
        $token = $this->createTokenWithRoles(['RECEPCIONISTA']);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/agendamiento/kpis?desde=2026-11-01&hasta=2026-11-30');

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'FORBIDDEN_ROLE');
    }

    public function test_kpis_returns_422_when_period_is_missing(): void
    {
        $token = $this->createTokenWithRoles(['ADMIN']);

        // Missing both
        $res1 = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/agendamiento/kpis');
        $res1->assertStatus(422)
            ->assertJsonPath('success', false);

        // Missing hasta
        $res2 = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/agendamiento/kpis?desde=2026-11-01');
        $res2->assertStatus(422)
            ->assertJsonPath('success', false);

        // Missing desde
        $res3 = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/agendamiento/kpis?hasta=2026-11-30');
        $res3->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_kpis_returns_422_when_period_dates_are_invalid(): void
    {
        $token = $this->createTokenWithRoles(['ADMIN']);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/agendamiento/kpis?desde=not-a-date&hasta=2026-11-30');

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_kpis_returns_422_when_desde_is_after_hasta(): void
    {
        $token = $this->createTokenWithRoles(['ADMIN']);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/agendamiento/kpis?desde=2026-11-30&hasta=2026-11-01');

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_kpis_returns_422_when_period_exceeds_366_days(): void
    {
        $token = $this->createTokenWithRoles(['ADMIN']);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/agendamiento/kpis?desde=2025-01-01&hasta=2026-01-05');

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_kpis_returns_zeros_and_empty_arrays_for_empty_period(): void
    {
        $token = $this->createTokenWithRoles(['ADMIN']);

        $citasRepo = Mockery::mock(CitaRepositoryInterface::class);
        $citasRepo->shouldReceive('findAll')->andReturn([]);
        $this->app->instance(CitaRepositoryInterface::class, $citasRepo);

        $insumosRepo = Mockery::mock(InsumoRepositoryInterface::class);
        $insumosRepo->shouldReceive('findAll')->andReturn([]);
        $this->app->instance(InsumoRepositoryInterface::class, $insumosRepo);

        $movsRepo = Mockery::mock(MovimientoStockRepositoryInterface::class);
        $movsRepo->shouldReceive('findAll')->andReturn([]);
        $this->app->instance(MovimientoStockRepositoryInterface::class, $movsRepo);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/agendamiento/kpis?desde=2026-01-01&hasta=2026-01-31');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.periodo.desde', '2026-01-01')
            ->assertJsonPath('data.periodo.hasta', '2026-01-31')
            ->assertJsonPath('data.pacientesFrecuentes', [])
            ->assertJsonPath('data.inasistencia.citasEvaluadas', 0)
            ->assertJsonPath('data.inasistencia.noAsistio', 0)
            ->assertJsonPath('data.inasistencia.porcentaje', 0)
            ->assertJsonPath('data.inasistencia.detalle', [])
            ->assertJsonPath('data.horariosPico.porDiaHora', [])
            ->assertJsonPath('data.horariosPico.top', [])
            ->assertJsonPath('data.insumosCriticos', [])
            ->assertJsonPath('data.productividadOdontologos', []);
        $this->assertEquals(0.0, $response->json('data.inasistencia.porcentaje'));
    }

    public function test_kpis_computes_exact_metrics_for_valid_period(): void
    {
        $token = $this->createTokenWithRoles(['ADMIN']);

        // Period: 2026-11-01 to 2026-11-30 in America/La_Paz
        // 2026-11-02 is Monday (diaSemana = 1)
        // 2026-11-03 is Tuesday (diaSemana = 2)
        $mockCitas = [
            // Paciente 1 (odo-1): 3 completadas, 1 no asistio = 4 citas
            new Cita('c1', 'idem-1', 'odo-1', 'con-1', 'pac-1', '2026-11-02 09:00:00', Cita::ESTADO_COMPLETADA),
            new Cita('c2', 'idem-2', 'odo-1', 'con-1', 'pac-1', '2026-11-02 09:30:00', Cita::ESTADO_COMPLETADA),
            new Cita('c3', 'idem-3', 'odo-1', 'con-1', 'pac-1', '2026-11-09 09:15:00', Cita::ESTADO_COMPLETADA),
            new Cita('c4', 'idem-4', 'odo-1', 'con-1', 'pac-1', '2026-11-16 14:00:00', Cita::ESTADO_NO_ASISTIO),

            // Paciente 2 (odo-2): 2 completadas = 2 citas
            new Cita('c5', 'idem-5', 'odo-2', 'con-1', 'pac-2', '2026-11-03 10:00:00', Cita::ESTADO_COMPLETADA),
            new Cita('c6', 'idem-6', 'odo-2', 'con-1', 'pac-2', '2026-11-10 10:30:00', Cita::ESTADO_COMPLETADA),

            // Paciente 3 (odo-2): 1 cancelada = 1 cita (no entra en evaluadas para inasistencia)
            new Cita('c7', 'idem-7', 'odo-2', 'con-1', 'pac-3', '2026-11-04 11:00:00', Cita::ESTADO_CANCELADA),

            // Cita fuera de período (2026-10-31): no debe incluirse
            new Cita('c-out', 'idem-out', 'odo-1', 'con-1', 'pac-1', '2026-10-31 10:00:00', Cita::ESTADO_COMPLETADA),
        ];

        $mockInsumos = [
            // Insumo 1: stock 2 <= minimo 5 -> bajoMinimo true, salidas = 12
            new Insumo('ins-1', 'GUAN-01', 'Guantes Latex', 2, 5, 'Cajas'),
            // Insumo 2: stock 10 > minimo 5 -> bajoMinimo false, salidas = 25
            new Insumo('ins-2', 'ANEST-01', 'Anestesia', 10, 5, 'Ampollas'),
            // Insumo 3: stock 1 <= minimo 3 -> bajoMinimo true, salidas = 0
            new Insumo('ins-3', 'BARB-01', 'Barbijos', 1, 3, 'Cajas'),
        ];

        $mockMovimientos = [
            // Salidas insumo 1 en período: 10 + 2 = 12
            new MovimientoStock('m1', 'ins-1', MovimientoStock::TIPO_SALIDA_PROCEDIMIENTO, 10, 12, 2, 'Uso clinico', 'dr-1', '2026-11-05 10:00:00'),
            new MovimientoStock('m2', 'ins-1', MovimientoStock::TIPO_SALIDA_PROCEDIMIENTO, 2, 2, 0, 'Uso clinico', 'dr-1', '2026-11-15 11:00:00'),
            // Salidas insumo 2 en período: 25
            new MovimientoStock('m3', 'ins-2', MovimientoStock::TIPO_SALIDA_PROCEDIMIENTO, 25, 35, 10, 'Procedimientos quirurgicos', 'dr-1', '2026-11-20 15:00:00'),
            // Entrada en período (no debe sumar a salidasPeriodo):
            new MovimientoStock('m4', 'ins-1', MovimientoStock::TIPO_ENTRADA_COMPRA, 50, 0, 50, 'Compra proveedor', 'admin-1', '2026-11-10 09:00:00'),
            // Salida fuera de período (no debe sumar):
            new MovimientoStock('m5', 'ins-1', MovimientoStock::TIPO_SALIDA_PROCEDIMIENTO, 100, 150, 50, 'Uso viejo', 'dr-1', '2026-10-25 10:00:00'),
        ];

        $citasRepo = Mockery::mock(CitaRepositoryInterface::class);
        $citasRepo->shouldReceive('findAll')->andReturn($mockCitas);
        $this->app->instance(CitaRepositoryInterface::class, $citasRepo);

        $insumosRepo = Mockery::mock(InsumoRepositoryInterface::class);
        $insumosRepo->shouldReceive('findAll')->andReturn($mockInsumos);
        $this->app->instance(InsumoRepositoryInterface::class, $insumosRepo);

        $movsRepo = Mockery::mock(MovimientoStockRepositoryInterface::class);
        $movsRepo->shouldReceive('findAll')->andReturn($mockMovimientos);
        $this->app->instance(MovimientoStockRepositoryInterface::class, $movsRepo);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/agendamiento/kpis?desde=2026-11-01&hasta=2026-11-30');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.periodo.desde', '2026-11-01')
            ->assertJsonPath('data.periodo.hasta', '2026-11-30');

        // Pacientes frecuentes: top by totalCitas
        // pac-1: 4 citas (3 completadas)
        // pac-2: 2 citas (2 completadas)
        // pac-3: 1 cita (0 completadas)
        $response->assertJsonPath('data.pacientesFrecuentes.0.pacienteId', 'pac-1')
            ->assertJsonPath('data.pacientesFrecuentes.0.totalCitas', 4)
            ->assertJsonPath('data.pacientesFrecuentes.0.completadas', 3)
            ->assertJsonPath('data.pacientesFrecuentes.1.pacienteId', 'pac-2')
            ->assertJsonPath('data.pacientesFrecuentes.1.totalCitas', 2)
            ->assertJsonPath('data.pacientesFrecuentes.1.completadas', 2);

        // Inasistencia:
        // Evaluadas = COMPLETADA (5) + NO_ASISTIO (1) = 6
        // noAsistio = 1
        // porcentaje = 1 / 6 * 100 = 16.67%
        $response->assertJsonPath('data.inasistencia.citasEvaluadas', 6)
            ->assertJsonPath('data.inasistencia.noAsistio', 1)
            ->assertJsonPath('data.inasistencia.porcentaje', 16.67)
            ->assertJsonPath('data.inasistencia.detalle.0.citaId', 'c4')
            ->assertJsonPath('data.inasistencia.detalle.0.pacienteId', 'pac-1');

        // Horarios Pico:
        // Citas en period:
        // c1: 2026-11-02 (Monday=1) 09:00 -> diaSemana 1, hora 9
        // c2: 2026-11-02 (Monday=1) 09:30 -> diaSemana 1, hora 9
        // c3: 2026-11-09 (Monday=1) 09:15 -> diaSemana 1, hora 9
        // c4: 2026-11-16 (Monday=1) 14:00 -> diaSemana 1, hora 14
        // c5: 2026-11-03 (Tuesday=2) 10:00 -> diaSemana 2, hora 10
        // c6: 2026-11-10 (Tuesday=2) 10:30 -> diaSemana 2, hora 10
        // c7: 2026-11-04 (Wednesday=3) 11:00 -> diaSemana 3, hora 11
        // Buckets non-zero:
        // (1, 9) => 3 citas
        // (2, 10) => 2 citas
        // (1, 14) => 1 cita
        // (3, 11) => 1 cita
        $response->assertJsonPath('data.horariosPico.top.0.diaSemana', 1)
            ->assertJsonPath('data.horariosPico.top.0.hora', 9)
            ->assertJsonPath('data.horariosPico.top.0.citas', 3)
            ->assertJsonPath('data.horariosPico.top.1.diaSemana', 2)
            ->assertJsonPath('data.horariosPico.top.1.hora', 10)
            ->assertJsonPath('data.horariosPico.top.1.citas', 2);

        // Insumos críticos: sorted bajoMinimo first, then salidasPeriodo desc
        // 1. ins-1: bajoMinimo=true, salidas=12
        // 2. ins-3: bajoMinimo=true, salidas=0
        // 3. ins-2: bajoMinimo=false, salidas=25
        $response->assertJsonPath('data.insumosCriticos.0.insumoId', 'ins-1')
            ->assertJsonPath('data.insumosCriticos.0.bajoMinimo', true)
            ->assertJsonPath('data.insumosCriticos.0.salidasPeriodo', 12)
            ->assertJsonPath('data.insumosCriticos.1.insumoId', 'ins-3')
            ->assertJsonPath('data.insumosCriticos.1.bajoMinimo', true)
            ->assertJsonPath('data.insumosCriticos.1.salidasPeriodo', 0)
            ->assertJsonPath('data.insumosCriticos.2.insumoId', 'ins-2')
            ->assertJsonPath('data.insumosCriticos.2.bajoMinimo', false)
            ->assertJsonPath('data.insumosCriticos.2.salidasPeriodo', 25);

        // Productividad Odontólogos:
        // odo-1: 4 citas totales (3 completadas) -> 3/4 = 75.0%
        // odo-2: 3 citas totales (2 completadas) -> 2/3 = 66.67%
        $response->assertJsonPath('data.productividadOdontologos.0.odontologoId', 'odo-1')
            ->assertJsonPath('data.productividadOdontologos.0.citasTotales', 4)
            ->assertJsonPath('data.productividadOdontologos.0.citasCompletadas', 3)
            ->assertJsonPath('data.productividadOdontologos.0.tasaCompletadas', 75)
            ->assertJsonPath('data.productividadOdontologos.1.odontologoId', 'odo-2')
            ->assertJsonPath('data.productividadOdontologos.1.citasTotales', 3)
            ->assertJsonPath('data.productividadOdontologos.1.citasCompletadas', 2)
            ->assertJsonPath('data.productividadOdontologos.1.tasaCompletadas', 66.67);
        $this->assertEquals(75.0, $response->json('data.productividadOdontologos.0.tasaCompletadas'));
    }

    public function test_kpis_request_is_strictly_read_only_and_writes_nothing(): void
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

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/agendamiento/kpis?desde=2026-11-01&hasta=2026-11-30');

        $response->assertStatus(200);
    }
}
