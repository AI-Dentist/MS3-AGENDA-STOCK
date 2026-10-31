<?php

namespace Tests\Unit;

use App\Application\Ports\Ms1HorarioReferenceValidatorPort;
use App\Application\UseCases\CrearHorarioUseCase;
use App\Domain\Entities\Horario;
use App\Domain\Repositories\HorarioRepositoryInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CrearHorarioUseCaseTest extends TestCase
{
    private HorarioRepositoryInterface $repository;

    private Ms1HorarioReferenceValidatorPort $validator;

    private CrearHorarioUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new class implements HorarioRepositoryInterface
        {
            public array $horarios = [];

            public function findByOdontologo(string $odontologoId): array
            {
                return array_values(array_filter($this->horarios, fn ($h) => $h->odontologoId === $odontologoId));
            }

            public function save(Horario $horario): void
            {
                $this->horarios[$horario->id] = $horario;
            }

            public function findAll(): array
            {
                return array_values($this->horarios);
            }
        };

        $this->validator = new class implements Ms1HorarioReferenceValidatorPort
        {
            public bool $shouldFailValidation = false;

            public bool $shouldFailUpstream = false;

            public ?string $lastCallerToken = null;

            public function validateReferences(string $odontologoId, string $consultorioId, string $callerBearerToken): array
            {
                $this->lastCallerToken = $callerBearerToken;

                if ($this->shouldFailUpstream) {
                    throw new RuntimeException('Upstream ERP error', 502);
                }

                if ($this->shouldFailValidation) {
                    throw new InvalidArgumentException("Referencia inválida para odontólogo {$odontologoId}");
                }

                return [
                    'odontologo_id' => strtolower($odontologoId),
                    'consultorio_id' => strtolower($consultorioId),
                ];
            }
        };

        $this->useCase = new CrearHorarioUseCase($this->repository, $this->validator);
    }

    public function test_crea_horario_exitosamente_con_datos_validos(): void
    {
        $input = [
            'odontologo_id' => '11111111-2222-3333-4444-555555555555',
            'consultorio_id' => '99999999-8888-7777-6666-555555555555',
            'especialidad' => 'Cirugía Maxilofacial',
            'fecha' => '2026-12-05',
            'hora_inicio' => '08:30',
            'hora_fin' => '13:00',
            'disponible' => true,
        ];

        $resultado = $this->useCase->execute($input, 'mock-caller-admin-token');

        $this->assertTrue($resultado['horario']['disponible']);
        $this->assertEquals('Cirugía Maxilofacial', $resultado['horario']['especialidad']);
        $this->assertStringStartsWith('hor-', $resultado['horario']['id']);
        $this->assertEquals('mock-caller-admin-token', $this->validator->lastCallerToken);
        $this->assertCount(1, $this->repository->findAll());
    }

    public function test_rechaza_campos_obligatorios_vacios(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->useCase->execute([
            'odontologo_id' => '',
            'consultorio_id' => '99999999-8888-7777-6666-555555555555',
            'especialidad' => 'Ortodoncia',
            'fecha' => '2026-12-05',
            'hora_inicio' => '08:30',
            'hora_fin' => '13:00',
        ], 'token');
    }

    public function test_rechaza_formato_de_fecha_invalido(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->useCase->execute([
            'odontologo_id' => '11111111-2222-3333-4444-555555555555',
            'consultorio_id' => '99999999-8888-7777-6666-555555555555',
            'especialidad' => 'Ortodoncia',
            'fecha' => '05/12/2026', // Formato no Y-m-d
            'hora_inicio' => '08:30',
            'hora_fin' => '13:00',
        ], 'token');
    }

    public function test_rechaza_rango_horario_invalido(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->useCase->execute([
            'odontologo_id' => '11111111-2222-3333-4444-555555555555',
            'consultorio_id' => '99999999-8888-7777-6666-555555555555',
            'especialidad' => 'Ortodoncia',
            'fecha' => '2026-12-05',
            'hora_inicio' => '14:00',
            'hora_fin' => '12:00', // hora_inicio > hora_fin
        ], 'token');
    }

    public function test_rechaza_hora_inicio_con_formato_no_hh_mm(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->useCase->execute([
            'odontologo_id' => '11111111-2222-3333-4444-555555555555',
            'consultorio_id' => '99999999-8888-7777-6666-555555555555',
            'especialidad' => 'Ortodoncia',
            'fecha' => '2026-12-05',
            'hora_inicio' => 'not-a-time',
            'hora_fin' => '17:00',
        ], 'token');
    }

    public function test_persiste_referencias_canonicas_resueltas_en_entidad(): void
    {
        $resultado = $this->useCase->execute([
            'odontologo_id' => '11111111-2222-3333-4444-555555555555',
            'consultorio_id' => '99999999-8888-7777-6666-555555555555',
            'especialidad' => 'Ortodoncia',
            'fecha' => '2026-12-05',
            'hora_inicio' => '08:00',
            'hora_fin' => '12:00',
            'disponible' => true,
        ], 'token-admin');

        $this->assertEquals('11111111-2222-3333-4444-555555555555', $resultado['horario']['odontologo_id']);
        $this->assertEquals('99999999-8888-7777-6666-555555555555', $resultado['horario']['consultorio_id']);
    }

    public function test_propaga_fallo_de_referencia_invalida_sin_guardar_registro(): void
    {
        $this->validator->shouldFailValidation = true;

        $this->expectException(InvalidArgumentException::class);
        try {
            $this->useCase->execute([
                'odontologo_id' => '11111111-2222-3333-4444-555555555555',
                'consultorio_id' => '99999999-8888-7777-6666-555555555555',
                'especialidad' => 'Ortodoncia',
                'fecha' => '2026-12-05',
                'hora_inicio' => '08:00',
                'hora_fin' => '12:00',
            ], 'token');
        } finally {
            $this->assertCount(0, $this->repository->findAll());
        }
    }

    public function test_propaga_fallo_de_upstream_sin_guardar_registro(): void
    {
        $this->validator->shouldFailUpstream = true;

        $this->expectException(RuntimeException::class);
        try {
            $this->useCase->execute([
                'odontologo_id' => '11111111-2222-3333-4444-555555555555',
                'consultorio_id' => '99999999-8888-7777-6666-555555555555',
                'especialidad' => 'Ortodoncia',
                'fecha' => '2026-12-05',
                'hora_inicio' => '08:00',
                'hora_fin' => '12:00',
            ], 'token');
        } finally {
            $this->assertCount(0, $this->repository->findAll());
        }
    }
}
