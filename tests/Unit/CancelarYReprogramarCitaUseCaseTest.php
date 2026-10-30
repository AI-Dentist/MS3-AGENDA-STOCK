<?php

namespace Tests\Unit;

use App\Application\UseCases\CancelarCitaUseCase;
use App\Application\UseCases\ReprogramarCitaUseCase;
use App\Domain\Entities\Cita;
use App\Domain\Exceptions\CitaCollisionException;
use App\Domain\Repositories\CitaRepositoryInterface;
use PHPUnit\Framework\TestCase;

class CancelarYReprogramarCitaUseCaseTest extends TestCase
{
    private CitaRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new class implements CitaRepositoryInterface
        {
            public array $citas = [];

            public function findById(string $id): ?Cita
            {
                return $this->citas[$id] ?? null;
            }

            public function findByIdempotencyKey(string $key): ?Cita
            {
                foreach ($this->citas as $c) {
                    if ($c->idempotencyKey === $key) {
                        return $c;
                    }
                }

                return null;
            }

            public function existeColision(string $odontologoId, string $consultorioId, string $fechaHora, ?string $excluirCitaId = null): bool
            {
                foreach ($this->citas as $c) {
                    if ($c->estado === 'CANCELADA') {
                        continue;
                    }
                    if ($excluirCitaId && $c->id === $excluirCitaId) {
                        continue;
                    }
                    if ($c->fechaHora === $fechaHora) {
                        if ($c->odontologoId === $odontologoId || $c->consultorioId === $consultorioId) {
                            return true;
                        }
                    }
                }

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
    }

    public function test_cancela_cita_correctamente(): void
    {
        $cita = new Cita(
            id: 'cita-1',
            idempotencyKey: 'k-1',
            odontologoId: 'odo-1',
            consultorioId: 'con-1',
            pacienteId: 'pac-1',
            fechaHora: '2026-11-20 09:00:00',
            estado: 'RESERVADA'
        );
        $this->repository->save($cita);

        $cancelarUseCase = new CancelarCitaUseCase($this->repository);
        $resultado = $cancelarUseCase->execute('cita-1');

        $this->assertEquals('CANCELADA', $resultado['cita']['estado']);
        $this->assertEquals('CANCELADA', $this->repository->findById('cita-1')->estado);
    }

    public function test_reprograma_cita_correctamente(): void
    {
        $cita = new Cita(
            id: 'cita-2',
            idempotencyKey: 'k-2',
            odontologoId: 'odo-1',
            consultorioId: 'con-1',
            pacienteId: 'pac-1',
            fechaHora: '2026-11-20 09:00:00',
            estado: 'RESERVADA'
        );
        $this->repository->save($cita);

        $reprogramarUseCase = new ReprogramarCitaUseCase($this->repository);
        $resultado = $reprogramarUseCase->execute('cita-2', '2026-11-21 15:00:00');

        $this->assertEquals('REPROGRAMADA', $resultado['cita']['estado']);
        $this->assertEquals('2026-11-21 15:00:00', $resultado['cita']['fecha_hora']);
    }

    public function test_impide_reprogramar_a_un_horario_con_colision(): void
    {
        $citaOcupada = new Cita(
            id: 'cita-ocupada',
            idempotencyKey: 'k-oc',
            odontologoId: 'odo-1',
            consultorioId: 'con-1',
            pacienteId: 'pac-99',
            fechaHora: '2026-11-25 11:00:00',
            estado: 'RESERVADA'
        );
        $this->repository->save($citaOcupada);

        $citaParaMover = new Cita(
            id: 'cita-mover',
            idempotencyKey: 'k-mov',
            odontologoId: 'odo-1',
            consultorioId: 'con-2',
            pacienteId: 'pac-2',
            fechaHora: '2026-11-20 09:00:00',
            estado: 'RESERVADA'
        );
        $this->repository->save($citaParaMover);

        $reprogramarUseCase = new ReprogramarCitaUseCase($this->repository);

        $this->expectException(CitaCollisionException::class);
        $reprogramarUseCase->execute('cita-mover', '2026-11-25 11:00:00');
    }

    public function test_rechaza_cancelar_cita_ya_completada(): void
    {
        $cita = new Cita(
            id: 'cita-comp',
            idempotencyKey: 'k-comp',
            odontologoId: 'odo-1',
            consultorioId: 'con-1',
            pacienteId: 'pac-1',
            fechaHora: '2026-11-20 09:00:00',
            estado: Cita::ESTADO_COMPLETADA
        );
        $this->repository->save($cita);

        $cancelarUseCase = new CancelarCitaUseCase($this->repository);
        $this->expectException(\InvalidArgumentException::class);
        $cancelarUseCase->execute('cita-comp');
    }

    public function test_rechaza_reprogramar_cita_ya_completada(): void
    {
        $cita = new Cita(
            id: 'cita-comp-2',
            idempotencyKey: 'k-comp-2',
            odontologoId: 'odo-1',
            consultorioId: 'con-1',
            pacienteId: 'pac-1',
            fechaHora: '2026-11-20 09:00:00',
            estado: Cita::ESTADO_COMPLETADA
        );
        $this->repository->save($cita);

        $reprogramarUseCase = new ReprogramarCitaUseCase($this->repository);
        $this->expectException(\InvalidArgumentException::class);
        $reprogramarUseCase->execute('cita-comp-2', '2026-11-22 10:00:00');
    }
}
