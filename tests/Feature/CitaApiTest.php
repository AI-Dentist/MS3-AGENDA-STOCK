<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\TestCase;

class CitaApiTest extends TestCase
{
    public function test_rechaza_agendamiento_sin_idempotency_key(): void
    {
        $response = $this->postJson('/api/agendamiento/citas', [
            'odontologo_id' => 'odo-test',
            'consultorio_id' => 'con-test',
            'paciente_id' => 'pac-test',
            'fecha_hora' => '2026-12-01 10:00:00',
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'code' => 'MISSING_IDEMPOTENCY_KEY',
            ]);
    }

    public function test_agenda_cita_y_previene_colisiones(): void
    {
        $uniqueHour = '2026-12-01 '.rand(10, 20).':00:00';
        $key1 = (string) Str::uuid();

        // 1. Agendar cita exitosamente
        $res1 = $this->withHeader('Idempotency-Key', $key1)
            ->postJson('/api/agendamiento/citas', [
                'odontologo_id' => 'odo-colision-test',
                'consultorio_id' => 'con-colision-test',
                'paciente_id' => 'pac-1',
                'fecha_hora' => $uniqueHour,
            ]);

        $res1->assertStatus(201)
            ->assertJson([
                'success' => true,
                'idempotente' => false,
            ]);

        $citaId = $res1->json('data.id');

        // 2. Intentar agendar en el mismo horario y odontólogo -> Colisión (HTTP 409)
        $key2 = (string) Str::uuid();
        $res2 = $this->withHeader('Idempotency-Key', $key2)
            ->postJson('/api/agendamiento/citas', [
                'odontologo_id' => 'odo-colision-test',
                'consultorio_id' => 'con-otro',
                'paciente_id' => 'pac-2',
                'fecha_hora' => $uniqueHour,
            ]);

        $res2->assertStatus(409)
            ->assertJson([
                'success' => false,
                'code' => 'SCHEDULE_COLLISION',
            ]);

        // 3. Cancelar la cita original -> HTTP 200
        $resCancel = $this->patchJson("/api/agendamiento/citas/{$citaId}/cancelar");
        $resCancel->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'estado' => 'CANCELADA',
                ],
            ]);
    }

    public function test_reprogramar_cita_endpoint(): void
    {
        $uniqueHour = '2026-12-05 11:00:00';
        $key = (string) Str::uuid();

        $res = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/agendamiento/citas', [
                'odontologo_id' => 'odo-reprog-test',
                'consultorio_id' => 'con-reprog-test',
                'paciente_id' => 'pac-reprog',
                'fecha_hora' => $uniqueHour,
            ]);

        $res->assertStatus(201);
        $citaId = $res->json('data.id');

        $nuevaFecha = '2026-12-06 14:00:00';
        $resReprog = $this->putJson("/api/agendamiento/citas/{$citaId}/reprogramar", [
            'nueva_fecha_hora' => $nuevaFecha,
        ]);

        $resReprog->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'estado' => 'REPROGRAMADA',
                    'fecha_hora' => $nuevaFecha,
                ],
            ]);
    }

    public function test_marcar_no_asistio_endpoint(): void
    {
        $uniqueHour = '2026-12-07 15:00:00';
        $key = (string) Str::uuid();

        $res = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/agendamiento/citas', [
                'odontologo_id' => 'odo-noshow-test',
                'consultorio_id' => 'con-noshow-test',
                'paciente_id' => 'pac-noshow',
                'fecha_hora' => $uniqueHour,
            ]);

        $res->assertStatus(201);
        $citaId = $res->json('data.id');

        $resNoShow = $this->patchJson("/api/agendamiento/citas/{$citaId}/no-asistio");

        $resNoShow->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $citaId,
                    'estado' => 'NO_ASISTIO',
                ],
            ]);
    }
}
