<?php

namespace Tests\Unit;

use App\Application\UseCases\AgendarCitaUseCase;
use App\Domain\Entities\Cita;
use App\Domain\Entities\Horario;
use App\Domain\Repositories\CitaRepositoryInterface;
use App\Domain\Repositories\HorarioRepositoryInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class HorarioValidationTest extends TestCase
{
    private CitaRepositoryInterface $citaRepo;

    private HorarioRepositoryInterface $horarioRepo;

    private AgendarCitaUseCase $useCase;

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

        $this->horarioRepo = new class implements HorarioRepositoryInterface
        {
            public array $horarios = [];

            public function findByOdontologo(string $odontologoId): array
            {
                return array_values(array_filter($this->horarios, fn ($h) => $h->odontologoId === $odontologoId));
            }

            public function findAll(): array
            {
                return $this->horarios;
            }

            public function save(Horario $h): void
            {
                $this->horarios[] = $h;
            }
        };

        // Registrar turno para Dr. Pérez: 2026-11-15 de 08:00 a 12:00 en consultorio con-01
        $this->horarioRepo->save(new Horario(
            id: 'hor-test-1',
            odontologoId: 'odo-dr-perez',
            especialidad: 'Ortodoncia',
            consultorioId: 'con-01',
            fecha: '2026-11-15',
            horaInicio: '08:00',
            horaFin: '12:00',
            disponible: true
        ));

        $this->useCase = new AgendarCitaUseCase($this->citaRepo, $this->horarioRepo);
    }

    public function test_permite_agendar_dentro_del_turno_disponible(): void
    {
        $payload = [
            'odontologo_id' => 'odo-dr-perez',
            'consultorio_id' => 'con-01',
            'paciente_id' => 'pac-1',
            'fecha_hora' => '2026-11-15 09:30:00',
        ];

        $resultado = $this->useCase->execute($payload, 'idemp-valid-shift');

        $this->assertFalse($resultado['idempotente']);
        $this->assertEquals(Cita::ESTADO_RESERVADA, $resultado['cita']['estado']);
        $this->assertEquals('2026-11-15 09:30:00', $resultado['cita']['fecha_hora']);
    }

    public function test_rechaza_agendar_fuera_del_rango_horario_del_turno(): void
    {
        $payload = [
            'odontologo_id' => 'odo-dr-perez',
            'consultorio_id' => 'con-01',
            'paciente_id' => 'pac-1',
            'fecha_hora' => '2026-11-15 14:00:00', // Turno finaliza a las 12:00
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no se encuentra dentro de un turno disponible/');

        $this->useCase->execute($payload, 'idemp-out-of-hours');
    }

    public function test_rechaza_agendar_en_un_consultorio_distinto_al_del_turno(): void
    {
        $payload = [
            'odontologo_id' => 'odo-dr-perez',
            'consultorio_id' => 'con-02', // Turno registrado para con-01
            'paciente_id' => 'pac-1',
            'fecha_hora' => '2026-11-15 10:00:00',
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/consultorio con-02/');

        $this->useCase->execute($payload, 'idemp-wrong-consultorio');
    }

    public function test_rechaza_agendar_en_fecha_sin_turno_programado(): void
    {
        $payload = [
            'odontologo_id' => 'odo-dr-perez',
            'consultorio_id' => 'con-01',
            'paciente_id' => 'pac-1',
            'fecha_hora' => '2026-11-16 10:00:00', // No hay turno el 16 de noviembre
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no se encuentra dentro de un turno disponible/');

        $this->useCase->execute($payload, 'idemp-wrong-date');
    }
}
