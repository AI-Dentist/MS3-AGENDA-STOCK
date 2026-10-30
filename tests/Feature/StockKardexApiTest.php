<?php

namespace Tests\Feature;

use App\Domain\Entities\Cita;
use App\Domain\Entities\Insumo;
use App\Domain\Repositories\CitaRepositoryInterface;
use App\Domain\Repositories\InsumoRepositoryInterface;
use Illuminate\Support\Str;
use Tests\TestCase;

class StockKardexApiTest extends TestCase
{
    private InsumoRepositoryInterface $insumoRepo;

    private CitaRepositoryInterface $citaRepo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->insumoRepo = $this->app->make(InsumoRepositoryInterface::class);
        $this->citaRepo = $this->app->make(CitaRepositoryInterface::class);
    }

    public function test_registra_movimiento_de_entrada_y_actualiza_kardex(): void
    {
        $insumoId = 'ins-test-kardex-1';
        $insumo = new Insumo(
            id: $insumoId,
            codigo: 'TEST-INS-01',
            nombre: 'Gasas Estériles',
            stockActual: 10,
            stockMinimo: 5,
            unidadMedida: 'Paquetes'
        );
        $this->insumoRepo->save($insumo);

        // 1. Registrar ENTRADA_COMPRA de 15 unidades
        $response = $this->postJson('/api/agendamiento/stock/movimientos', [
            'insumo_id' => $insumoId,
            'tipo_movimiento' => 'ENTRADA_COMPRA',
            'cantidad' => 15,
            'motivo' => 'Compra a proveedor médico',
            'usuario_id' => 'admin-1',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'insumo' => [
                        'id' => $insumoId,
                        'stock_actual' => 25,
                    ],
                    'movimiento' => [
                        'tipo_movimiento' => 'ENTRADA_COMPRA',
                        'cantidad' => 15,
                        'stock_anterior' => 10,
                        'stock_posterior' => 25,
                    ],
                ],
            ]);

        // 2. Consultar Kardex del insumo
        $kardexRes = $this->getJson("/api/agendamiento/stock/{$insumoId}/kardex");
        $kardexRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'insumo' => [
                        'id' => $insumoId,
                        'stock_actual' => 25,
                    ],
                    'total_movimientos' => 1,
                ],
            ]);
    }

    public function test_completa_cita_descontando_stock_de_insumos_y_registrando_en_kardex(): void
    {
        $insumoId = 'ins-test-proc-1';
        $insumo = new Insumo(
            id: $insumoId,
            codigo: 'ANES-TEST',
            nombre: 'Anestesia Mepivacaína',
            stockActual: 30,
            stockMinimo: 10,
            unidadMedida: 'Cartuchos'
        );
        $this->insumoRepo->save($insumo);

        $citaId = 'cita-test-complete-1';
        $cita = new Cita(
            id: $citaId,
            idempotencyKey: (string) Str::uuid(),
            odontologoId: 'odo-dr-juan',
            consultorioId: 'con-1',
            pacienteId: 'pac-42',
            fechaHora: '2026-11-20 11:00:00',
            estado: Cita::ESTADO_RESERVADA
        );
        $this->citaRepo->save($cita);

        // Completar cita consumiendo 4 cartuchos
        $response = $this->patchJson("/api/agendamiento/citas/{$citaId}/completar", [
            'insumos' => [
                ['insumo_id' => $insumoId, 'cantidad' => 4],
            ],
            'motivo' => 'Extracción de tercer molar',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $citaId,
                    'estado' => Cita::ESTADO_COMPLETADA,
                ],
                'insumos_descontados' => 1,
            ]);

        // Verificar que el stock disminuyó a 26
        $insumoActualizado = $this->insumoRepo->findById($insumoId);
        $this->assertEquals(26, $insumoActualizado->stockActual);

        // Verificar Kardex
        $kardexRes = $this->getJson("/api/agendamiento/stock/{$insumoId}/kardex");
        $kardexRes->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $kardexRes->json('data.total_movimientos'));
    }

    public function test_marca_cita_como_no_asistio(): void
    {
        $citaId = 'cita-test-no-show-1';
        $cita = new Cita(
            id: $citaId,
            idempotencyKey: (string) Str::uuid(),
            odontologoId: 'odo-dr-juan',
            consultorioId: 'con-1',
            pacienteId: 'pac-42',
            fechaHora: '2026-11-20 11:00:00',
            estado: Cita::ESTADO_RESERVADA
        );
        $this->citaRepo->save($cita);

        $response = $this->patchJson("/api/agendamiento/citas/{$citaId}/no-asistio");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $citaId,
                    'estado' => Cita::ESTADO_NO_ASISTIO,
                ],
            ]);
    }
}
