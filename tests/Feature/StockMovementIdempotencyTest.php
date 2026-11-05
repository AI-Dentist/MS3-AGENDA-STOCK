<?php

namespace Tests\Feature;

use App\Domain\Entities\Insumo;
use Tests\Support\StockMovementDatabase;
use Tests\Support\StockMovementTestEnvironment;
use Tests\TestCase;

class StockMovementIdempotencyTest extends TestCase
{
    use StockMovementTestEnvironment;

    private StockMovementDatabase $database;
    private const URL = '/api/agendamiento/stock/movimientos';

    protected function setUp(): void
    {
        $this->phase('setUp: start');
        parent::setUp();
        $this->phase('setUp: application ready');
        $this->freezeTime();
        $this->database = $this->useStockMovementDatabase();
        $this->database->tables['insumos']['synthetic-stock'] = (new Insumo('synthetic-stock', 'SYN', 'Synthetic', 10, 2, 'Unidad'))->toArray();
        $this->actor('actor-a');
        $this->phase('setUp: synthetic SDK and verified token ready');
    }

    private function payload(array $changes = []): array
    {
        return array_replace(['insumo_id' => 'synthetic-stock', 'tipo_movimiento' => 'ENTRADA_COMPRA',
            'cantidad' => 3, 'motivo' => 'Synthetic movement', 'usuario_id' => 'spoofed'], $changes);
    }

    private function state(int $stock, int $movements): void
    {
        $this->getJson('/api/agendamiento/stock')->assertOk()->assertJsonPath('data.insumos.0.stock_actual', $stock);
        $this->getJson('/api/agendamiento/stock/synthetic-stock/kardex')->assertOk()
            ->assertJsonPath('data.insumo.stock_actual', $stock)->assertJsonPath('data.total_movimientos', $movements);
    }

    private function error(int $status, string $code, string $message, array $payload, array $headers = []): void
    {
        $this->postJson(self::URL, $payload, $headers)->assertStatus($status)->assertExactJson([
            'success' => false, 'code' => $code, 'error' => $message, 'timestamp' => now()->toIso8601String(),
        ]);
    }

    public function test_replay_returns_original_response_and_verified_actor_once(): void
    {
        $this->phase('HTTP first movement: start');
        $first = $this->postJson(self::URL, $this->payload(), ['Idempotency-Key' => 'intent-1'])->assertCreated();
        $this->phase('HTTP first movement: 201; checking verified actor');
        $first->assertJsonPath('data.movimiento.usuario_id', 'actor-a');
        $this->travel(5)->minutes();
        $this->postJson(self::URL, $this->payload(), ['X-Idempotency-Key' => 'intent-1'])
            ->assertCreated()->assertExactJson($first->json());
        $this->state(13, 1);
    }

    public function test_repeating_the_same_key_changes_stock_and_kardex_once(): void
    {
        $headers = ['Idempotency-Key' => 'one-stock-intent'];
        $this->postJson(self::URL, $this->payload(), $headers)->assertCreated();
        $this->postJson(self::URL, $this->payload(), $headers)->assertCreated();
        $this->state(13, 1);
    }

    public function test_conflict_binds_payload_direction_and_not_spoofed_actor(): void
    {
        $payload = $this->payload(['tipo_movimiento' => 'AJUSTE']);
        $this->postJson(self::URL, $payload, ['Idempotency-Key' => 'intent'])->assertCreated();
        foreach ([['cantidad' => 4], ['direccion' => 'DECREMENTO'], ['motivo' => 'Different']] as $change) {
            $this->error(409, 'IDEMPOTENCY_CONFLICT', 'La clave de idempotencia ya fue utilizada con otro movimiento.', array_replace($payload, $change), ['Idempotency-Key' => 'intent']);
            $this->state(13, 1);
        }
        $this->actor('actor-b');
        $this->postJson(self::URL, $payload, ['Idempotency-Key' => 'intent'])->assertCreated()
            ->assertJsonPath('data.movimiento.usuario_id', 'actor-b')->assertJsonPath('data.movimiento.stock_anterior', 13);
        $this->state(16, 2);
    }

    public function test_interleaved_same_intent_returns_the_winning_response_once(): void
    {
        $winner = null;
        $this->database->beforeTransaction = function () use (&$winner): void {
            $winner = $this->postJson(self::URL, $this->payload(), ['Idempotency-Key' => 'race'])->assertCreated()->json();
        };
        $this->postJson(self::URL, $this->payload(), ['Idempotency-Key' => 'race'])->assertCreated()->assertExactJson($winner);
        $this->assertCount(2, $this->database->transactions);
        foreach ($this->database->reads as $read) {
            $this->assertTrue($read['ConsistentRead'] ?? false);
        }
        $this->state(13, 1);
    }

    public function test_interleaved_different_payload_same_key_conflicts_without_writes(): void
    {
        $this->database->beforeTransaction = function (): void {
            $this->postJson(self::URL, $this->payload(['cantidad' => 4]), ['Idempotency-Key' => 'race'])->assertCreated();
        };
        $this->error(409, 'IDEMPOTENCY_CONFLICT', 'La clave de idempotencia ya fue utilizada con otro movimiento.', $this->payload(), ['Idempotency-Key' => 'race']);
        $this->state(14, 1);
        $this->assertCount(2, $this->database->transactions);
    }

    public function test_interleaved_other_key_cannot_overwrite_changed_stock(): void
    {
        $this->database->beforeTransaction = function (): void {
            $this->postJson(self::URL, $this->payload(['cantidad' => 4]), ['Idempotency-Key' => 'winner'])->assertCreated();
        };
        $this->error(503, 'STOCK_WRITE_UNAVAILABLE', 'No se pudo confirmar el movimiento de stock. Consulte el resultado antes de reintentar.', $this->payload(), ['Idempotency-Key' => 'loser']);
        $this->state(14, 1);
        $this->assertCount(2, $this->database->transactions);
    }

    public function test_no_key_and_supported_directions_preserve_behavior(): void
    {
        foreach ([['ENTRADA_COMPRA', null, 13], ['SALIDA_PROCEDIMIENTO', null, 10], ['AJUSTE', 'decremento', 7], ['AJUSTE', null, 10]] as $index => [$type, $direction, $stock]) {
            $payload = $this->payload(['tipo_movimiento' => $type]);
            if ($direction !== null) {
                $payload['direccion'] = $direction;
            }
            $result = $this->postJson(self::URL, $payload)->assertCreated();
            $result->assertExactJson([
                'success' => true, 'data' => [
                    'movimiento' => ['id' => $result->json('data.movimiento.id'), 'insumo_id' => 'synthetic-stock',
                        'tipo_movimiento' => $type, 'cantidad' => 3, 'stock_anterior' => [10, 13, 10, 7][$index],
                        'stock_posterior' => $stock, 'motivo' => 'Synthetic movement', 'usuario_id' => 'spoofed', 'fecha_hora' => now()->toIso8601String()],
                    'insumo' => (new Insumo('synthetic-stock', 'SYN', 'Synthetic', $stock, 2, 'Unidad'))->toArray(),
                ], 'mensaje' => 'Movimiento de stock registrado y Kardex actualizado exitosamente.', 'timestamp' => now()->toIso8601String(),
            ]);
            $this->state($stock, $index + 1);
        }
    }

    public function test_overdraw_does_not_write(): void
    {
        foreach (['SALIDA_PROCEDIMIENTO', 'AJUSTE'] as $type) {
            $message = $type === 'AJUSTE' ? 'Stock insuficiente para ajuste por decremento.'
                : 'Stock insuficiente para realizar la salida. Stock actual: 10, Solicitado: 11.';
            $this->error(400, 'INSUFFICIENT_STOCK', $message, $this->payload(['tipo_movimiento' => $type, 'cantidad' => 11, 'direccion' => 'DECREMENTO']), ['Idempotency-Key' => 'overdraw']);
            $this->state(10, 0);
        }
    }

    public function test_collision_and_unavailable_fail_closed_without_blind_retry(): void
    {
        foreach (['collision', 'unavailable'] as $failure) {
            $this->database->failure = $failure;
            $this->error(503, 'STOCK_WRITE_UNAVAILABLE', 'No se pudo confirmar el movimiento de stock. Consulte el resultado antes de reintentar.', $this->payload(), ['Idempotency-Key' => 'failed']);
            $this->state(10, 0);
        }
        $this->assertCount(2, $this->database->transactions);
    }

    public function test_failed_persistent_reads_do_not_use_legacy_memory_fallback(): void
    {
        foreach ([[], ['Idempotency-Key' => 'read-failed']] as $headers) {
            $this->database->failure = 'read-unavailable';
            $this->error(503, 'STOCK_WRITE_UNAVAILABLE', 'No se pudo confirmar el movimiento de stock. Consulte el resultado antes de reintentar.', $this->payload(), $headers);
            $this->database->failure = '';
            $this->state(10, 0);
        }
        $this->assertCount(0, $this->database->transactions);
    }

    public function test_uncertain_commit_with_failed_readback_returns_503_then_replays_once(): void
    {
        $this->database->failure = 'lost-readback';
        $this->error(503, 'STOCK_WRITE_UNAVAILABLE', 'No se pudo confirmar el movimiento de stock. Consulte el resultado antes de reintentar.', $this->payload(), ['Idempotency-Key' => 'uncertain']);
        $this->database->failure = '';
        $this->state(13, 1);
        $timestamp = now()->toIso8601String();
        $this->travel(1)->minutes();
        $recovered = $this->postJson(self::URL, $this->payload(), ['Idempotency-Key' => 'uncertain'])->assertCreated()
            ->assertJsonPath('timestamp', $timestamp)->assertJsonPath('data.movimiento.stock_anterior', 10);
        $this->postJson(self::URL, $this->payload(), ['Idempotency-Key' => 'uncertain'])->assertCreated()->assertExactJson($recovered->json());
        $this->state(13, 1);
        $this->assertCount(1, $this->database->transactions);
    }

    public function test_lost_response_replays_durable_outcome_after_writer_recreation(): void
    {
        $this->database->failure = 'lost';
        $first = $this->postJson(self::URL, $this->payload(), ['Idempotency-Key' => 'lost'])->assertCreated();
        $this->database->failure = '';
        $this->app->forgetInstance(\App\Domain\Repositories\StockMovementWriterInterface::class);
        $this->travel(1)->hours();
        $this->postJson(self::URL, $this->payload(), ['Idempotency-Key' => 'lost'])->assertCreated()->assertExactJson($first->json());
        $this->state(13, 1);
        $this->assertCount(1, $this->database->transactions);
    }

    public function test_irrelevant_numeric_direction_in_entrada_compra_is_ignored_without_validation_failure(): void
    {
        // En ENTRADA_COMPRA, el campo 'direccion' es irrelevante; si viene numérico no debe causar 422
        $payload = $this->payload([
            'tipo_movimiento' => 'ENTRADA_COMPRA',
            'direccion' => 999,
        ]);
        $response = $this->postJson(self::URL, $payload, ['Idempotency-Key' => 'ignored-dir-test'])->assertCreated();
        $this->state(13, 1);
    }

    public function test_contradictory_idempotency_headers_are_rejected_with_400(): void
    {
        // Si vienen ambos encabezados pero con valores discordantes, debe rechazarse con 400
        $this->postJson(self::URL, $this->payload(), [
            'Idempotency-Key' => 'key-alpha',
            'X-Idempotency-Key' => 'key-beta',
        ])->assertStatus(400)->assertJsonPath('code', 'CONTRADICTORY_IDEMPOTENCY_KEYS');

        // Estado intacto
        $this->state(10, 0);

        // Si ambos encabezados coinciden exactamente, debe aceptarse
        $this->postJson(self::URL, $this->payload(), [
            'Idempotency-Key' => 'key-matching',
            'X-Idempotency-Key' => 'key-matching',
        ])->assertCreated();

        $this->state(13, 1);
    }
}
