<?php

namespace Tests\Unit;

use App\Application\UseCases\AgendarCitaUseCase;
use App\Domain\Entities\Cita;
use App\Domain\Exceptions\CitaCollisionException;
use App\Domain\Repositories\CitaRepositoryInterface;
use PHPUnit\Framework\TestCase;

class AgendarCitaUseCaseTest extends TestCase
{
    private CitaRepositoryInterface $repository;

    private AgendarCitaUseCase $useCase;

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

        $this->useCase = new AgendarCitaUseCase($this->repository);
    }

    public function test_agenda_cita_exitosamente(): void
    {
        $payload = [
            'odontologo_id' => 'odo-01',
            'consultorio_id' => 'con-01',
            'paciente_id' => 'pac-100',
            'fecha_hora' => '2026-11-20 10:00:00',
        ];

        $resultado = $this->useCase->execute($payload, 'idemp-001');

        $this->assertFalse($resultado['idempotente']);
        $this->assertEquals('RESERVADA', $resultado['cita']['estado']);
        $this->assertEquals('pac-100', $resultado['cita']['paciente_id']);
    }

    public function test_retorna_cita_idempotente_si_clave_ya_existe(): void
    {
        $payload = [
            'odontologo_id' => 'odo-01',
            'consultorio_id' => 'con-01',
            'paciente_id' => 'pac-100',
            'fecha_hora' => '2026-11-20 10:00:00',
        ];

        $primeraReserva = $this->useCase->execute($payload, 'idemp-002');
        $segundaReserva = $this->useCase->execute($payload, 'idemp-002');

        $this->assertTrue($segundaReserva['idempotente']);
        $this->assertEquals($primeraReserva['cita']['id'], $segundaReserva['cita']['id']);
    }

    public function test_lanza_excepcion_de_colision_si_odontologo_o_consultorio_esta_ocupado(): void
    {
        $cita1 = [
            'odontologo_id' => 'odo-01',
            'consultorio_id' => 'con-01',
            'paciente_id' => 'pac-100',
            'fecha_hora' => '2026-11-20 10:00:00',
        ];
        $this->useCase->execute($cita1, 'idemp-101');

        // Intento de reservar en el mismo consultorio a la misma hora con otro odontólogo y paciente
        $citaConflicto = [
            'odontologo_id' => 'odo-99',
            'consultorio_id' => 'con-01',
            'paciente_id' => 'pac-200',
            'fecha_hora' => '2026-11-20 10:00:00',
        ];

        $this->expectException(CitaCollisionException::class);
        $this->useCase->execute($citaConflicto, 'idemp-102');
    }
}
