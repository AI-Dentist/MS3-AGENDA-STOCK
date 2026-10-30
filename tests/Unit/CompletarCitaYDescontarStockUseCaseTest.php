<?php

namespace Tests\Unit;

use App\Application\UseCases\CompletarCitaYDescontarStockUseCase;
use App\Domain\Entities\Cita;
use App\Domain\Entities\Insumo;
use App\Domain\Entities\MovimientoStock;
use App\Domain\Repositories\CitaRepositoryInterface;
use App\Domain\Repositories\InsumoRepositoryInterface;
use App\Domain\Repositories\MovimientoStockRepositoryInterface;
use Aws\DynamoDb\DynamoDbClient;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CompletarCitaYDescontarStockUseCaseTest extends TestCase
{
    private CitaRepositoryInterface $citaRepo;

    private InsumoRepositoryInterface $insumoRepo;

    private MovimientoStockRepositoryInterface $movRepo;

    private DynamoDbClient $dynamoClient;

    private CompletarCitaYDescontarStockUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->citaRepo = new class implements CitaRepositoryInterface
        {
            public array $citas = [];

            public function findById(string $id): ?Cita
            {
                return $this->citas[$id] ?? null;
            }

            public function findByIdempotencyKey(string $key): ?Cita
            {
                return null;
            }

            public function existeColision(string $o, string $c, string $f, ?string $e = null): bool
            {
                return false;
            }

            public function save(Cita $cita): void
            {
                $this->citas[$cita->id] = $cita;
            }

            public function updateEstado(string $id, string $nuevoEstado): void
            {
                if (isset($this->citas[$id])) {
                    $this->citas[$id]->estado = $nuevoEstado;
                }
            }

            public function findAll(): array
            {
                return array_values($this->citas);
            }
        };

        $this->insumoRepo = new class implements InsumoRepositoryInterface
        {
            public array $insumos = [];

            public function findAll(): array
            {
                return array_values($this->insumos);
            }

            public function findById(string $id): ?Insumo
            {
                return $this->insumos[$id] ?? null;
            }

            public function findByCodigo(string $c): ?Insumo
            {
                return null;
            }

            public function save(Insumo $insumo): void
            {
                $this->insumos[$insumo->id] = $insumo;
            }

            public function updateStock(string $id, int $nuevoStock): void
            {
                if (isset($this->insumos[$id])) {
                    $old = $this->insumos[$id];
                    $this->insumos[$id] = new Insumo(
                        id: $old->id,
                        codigo: $old->codigo,
                        nombre: $old->nombre,
                        stockActual: $nuevoStock,
                        stockMinimo: $old->stockMinimo,
                        unidadMedida: $old->unidadMedida
                    );
                }
            }
        };

        $this->movRepo = new class implements MovimientoStockRepositoryInterface
        {
            public array $movimientos = [];

            public function save(MovimientoStock $m): void
            {
                $this->movimientos[] = $m;
            }

            public function findByInsumoId(string $insumoId): array
            {
                return array_values(array_filter($this->movimientos, fn ($m) => $m->insumoId === $insumoId));
            }

            public function findAll(): array
            {
                return $this->movimientos;
            }
        };

        $this->dynamoClient = new DynamoDbClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'local', 'secret' => 'local'],
            'endpoint' => 'http://127.0.0.1:8002',
        ]);

        $this->useCase = new CompletarCitaYDescontarStockUseCase(
            $this->citaRepo,
            $this->insumoRepo,
            $this->movRepo,
            $this->dynamoClient
        );
    }

    public function test_completa_cita_y_descuenta_stock_atomicamente(): void
    {
        $cita = new Cita(
            id: 'cita-comp-1',
            idempotencyKey: 'key-100',
            odontologoId: 'odo-1',
            consultorioId: 'con-1',
            pacienteId: 'pac-1',
            fechaHora: '2026-11-20 10:00:00',
            estado: Cita::ESTADO_RESERVADA
        );
        $this->citaRepo->save($cita);

        $insumo = new Insumo(
            id: 'ins-01',
            codigo: 'GUAN-01',
            nombre: 'Guantes de Nitrilo',
            stockActual: 20,
            stockMinimo: 5,
            unidadMedida: 'Cajas'
        );
        $this->insumoRepo->save($insumo);

        $resultado = $this->useCase->execute(
            citaId: 'cita-comp-1',
            insumosUtilizados: [
                ['insumo_id' => 'ins-01', 'cantidad' => 3],
            ],
            usuarioId: 'odo-dr-smith',
            motivo: 'Procedimiento de ortodoncia'
        );

        $this->assertEquals(Cita::ESTADO_COMPLETADA, $resultado['cita']['estado']);
        $this->assertEquals(1, $resultado['insumos_descontados']);
        $this->assertEquals(17, $this->insumoRepo->findById('ins-01')->stockActual);

        $kardex = $this->movRepo->findByInsumoId('ins-01');
        $this->assertCount(1, $kardex);
        $this->assertEquals(MovimientoStock::TIPO_SALIDA_PROCEDIMIENTO, $kardex[0]->tipoMovimiento);
        $this->assertEquals(3, $kardex[0]->cantidad);
        $this->assertEquals(20, $kardex[0]->stockAnterior);
        $this->assertEquals(17, $kardex[0]->stockPosterior);
    }

    public function test_rechaza_completar_con_stock_insuficiente(): void
    {
        $cita = new Cita(
            id: 'cita-comp-2',
            idempotencyKey: 'key-101',
            odontologoId: 'odo-1',
            consultorioId: 'con-1',
            pacienteId: 'pac-1',
            fechaHora: '2026-11-20 10:00:00',
            estado: Cita::ESTADO_RESERVADA
        );
        $this->citaRepo->save($cita);

        $insumo = new Insumo(
            id: 'ins-02',
            codigo: 'ANES-01',
            nombre: 'Anestesia',
            stockActual: 2,
            stockMinimo: 5,
            unidadMedida: 'Ampollas'
        );
        $this->insumoRepo->save($insumo);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Stock insuficiente/');

        $this->useCase->execute(
            citaId: 'cita-comp-2',
            insumosUtilizados: [
                ['insumo_id' => 'ins-02', 'cantidad' => 5],
            ]
        );
    }

    public function test_rechaza_completar_cita_previamente_cancelada(): void
    {
        $cita = new Cita(
            id: 'cita-comp-3',
            idempotencyKey: 'key-102',
            odontologoId: 'odo-1',
            consultorioId: 'con-1',
            pacienteId: 'pac-1',
            fechaHora: '2026-11-20 10:00:00',
            estado: Cita::ESTADO_CANCELADA
        );
        $this->citaRepo->save($cita);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/cancelada/');

        $this->useCase->execute(citaId: 'cita-comp-3');
    }
}
