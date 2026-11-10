<?php

namespace Tests\Unit;

use App\Domain\Entities\Cita;
use App\Domain\Services\CitaFeatureExtractor;
use DateTimeImmutable;
use Tests\TestCase;

class CitaFeatureExtractorTest extends TestCase
{
    private CitaFeatureExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new CitaFeatureExtractor();
    }

    public function test_extracts_features_and_calculates_cancelaciones_previas_strictly_before_target(): void
    {
        $targetCita = new Cita(
            id: 'cita-target',
            idempotencyKey: 'idem-target',
            odontologoId: 'odo-1',
            consultorioId: 'con-1',
            pacienteId: 'pac-100',
            fechaHora: '2026-11-20 14:00:00',
            estado: Cita::ESTADO_RESERVADA,
            duracionEstimadaMin: 45,
            tipoTratamiento: 'ORTODONCIA',
            reservadaEn: '2026-11-10 10:00:00'
        );

        $historial = [
            // Cancelada antes de target -> debe contar (+1)
            new Cita('c-1', 'idem-1', 'odo-1', 'con-1', 'pac-100', '2026-10-01 10:00:00', Cita::ESTADO_CANCELADA),
            // No asistió antes de target -> debe contar (+1)
            new Cita('c-2', 'idem-2', 'odo-1', 'con-1', 'pac-100', '2026-10-15 11:00:00', Cita::ESTADO_NO_ASISTIO),
            // Completada antes de target -> NO cuenta
            new Cita('c-3', 'idem-3', 'odo-1', 'con-1', 'pac-100', '2026-11-01 09:00:00', Cita::ESTADO_COMPLETADA),
            // Cancelada DESPUÉS de target -> NO debe contar
            new Cita('c-4', 'idem-4', 'odo-1', 'con-1', 'pac-100', '2026-11-25 15:00:00', Cita::ESTADO_CANCELADA),
            // Cita de OTRO paciente cancelada -> NO debe contar
            new Cita('c-5', 'idem-5', 'odo-1', 'con-1', 'pac-999', '2026-10-05 10:00:00', Cita::ESTADO_CANCELADA),
        ];

        $extracted = $this->extractor->extract($targetCita, $historial);

        $payload = $extracted['payload'];
        $this->assertSame(2, $payload['cancelaciones_previas']);
        $this->assertSame(10, $payload['dias_anticipacion']); // 2026-11-20 vs 2026-11-10
        $this->assertSame(45, $payload['duracion_minutos']);
        $this->assertSame(1, $payload['tipo_tratamiento_riesgo']); // Ortodoncia
        $this->assertSame(5, $payload['dia_semana']); // Viernes
        $this->assertSame(14, $payload['hora_cita']);
        $this->assertSame(1, $payload['banda_horaria']); // >= 13h -> tarde (1)
        $this->assertSame(35, $payload['edad']); // Imputada

        // Edad SIEMPRE debe estar imputada (S7)
        $this->assertContains('edad', $extracted['featuresImputadas']);
        // Como reservadaEn y duracion si estaban, no deben estar imputadas
        $this->assertNotContains('anticipacionReserva', $extracted['featuresImputadas']);
        $this->assertNotContains('duracion_minutos', $extracted['featuresImputadas']);
    }

    public function test_imputes_missing_features_and_lists_them_in_features_imputadas(): void
    {
        // Cita mínima sin reservadaEn, sin duracion ni tipoTratamiento
        $minimalCita = [
            'id' => 'cita-minima',
            'paciente_id' => 'pac-200',
            'fecha_hora' => '2026-11-16 09:30:00', // Lunes
        ];

        $extracted = $this->extractor->extract($minimalCita, []);

        $payload = $extracted['payload'];
        $imputadas = $extracted['featuresImputadas'];

        $this->assertSame(35, $payload['edad']);
        $this->assertSame(7, $payload['dias_anticipacion']); // Default 7
        $this->assertSame(30, $payload['duracion_minutos']); // Default 30
        $this->assertSame(1, $payload['tipo_tratamiento_riesgo']); // Default 1
        $this->assertSame(0, $payload['banda_horaria']); // 9h -> mañana (0)
        $this->assertSame(1, $payload['dia_semana']); // Lunes

        $this->assertContains('edad', $imputadas);
        $this->assertContains('anticipacionReserva', $imputadas);
        $this->assertContains('duracion_minutos', $imputadas);
        $this->assertContains('tipo_tratamiento_riesgo', $imputadas);
    }
}
