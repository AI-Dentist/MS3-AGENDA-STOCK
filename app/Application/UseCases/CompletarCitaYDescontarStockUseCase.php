<?php

namespace App\Application\UseCases;

use App\Domain\Entities\Cita;
use App\Domain\Entities\Insumo;
use App\Domain\Entities\MovimientoStock;
use App\Domain\Repositories\CitaRepositoryInterface;
use App\Domain\Repositories\InsumoRepositoryInterface;
use App\Domain\Repositories\MovimientoStockRepositoryInterface;
use App\Infrastructure\Persistence\DynamoDB\DynamoDbConnectionManager;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\DynamoDb\Marshaler;
use Aws\Exception\AwsException;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class CompletarCitaYDescontarStockUseCase
{
    public function __construct(
        private CitaRepositoryInterface $citaRepository,
        private InsumoRepositoryInterface $insumoRepository,
        private MovimientoStockRepositoryInterface $movimientoRepository,
        private DynamoDbClient $dynamoDbClient,
        private ?Marshaler $marshaler = null
    ) {
        $this->marshaler = $marshaler ?? new Marshaler;
    }

    /**
     * Completa una cita y descuenta atómicamente el stock utilizado mediante TransactWriteItems en DynamoDB.
     *
     * @param  array  $insumosUtilizados  Array de items con formato: [['insumo_id' => 'ins-001', 'cantidad' => 2], ...]
     */
    public function execute(string $citaId, array $insumosUtilizados = [], ?string $usuarioId = null, ?string $motivo = null): array
    {
        $dynamoAvailable = DynamoDbConnectionManager::isAvailable();
        if (! $dynamoAvailable && ! $this->canUseInMemoryFallback()) {
            throw new \RuntimeException('Fallo de persistencia al completar cita: servicio de base de datos no disponible.');
        }

        $cita = $this->citaRepository->findById($citaId);
        if (! $cita) {
            throw new InvalidArgumentException("Cita no encontrada con ID: {$citaId}.");
        }

        if ($cita->estado === Cita::ESTADO_CANCELADA) {
            throw new InvalidArgumentException('No se puede completar una cita previamente cancelada.');
        }
        if ($cita->estado === Cita::ESTADO_COMPLETADA) {
            throw new InvalidArgumentException('La cita ya se encuentra en estado COMPLETADA.');
        }

        // Validar existencias de stock antes de armar la transacción
        $insumoEntities = [];
        foreach ($insumosUtilizados as $item) {
            $insumoId = $item['insumo_id'] ?? null;
            $cantidad = (int) ($item['cantidad'] ?? 0);

            if (! $insumoId || $cantidad <= 0) {
                throw new InvalidArgumentException("Cada insumo utilizado debe contener 'insumo_id' válido y 'cantidad' mayor a cero.");
            }

            $insumo = $this->insumoRepository->findById($insumoId);
            if (! $insumo) {
                throw new InvalidArgumentException("Insumo no encontrado: {$insumoId}.");
            }

            if ($insumo->stockActual < $cantidad) {
                throw new InvalidArgumentException(
                    "Stock insuficiente para el insumo '{$insumo->nombre}'. Stock actual: {$insumo->stockActual}, Solicitado: {$cantidad}."
                );
            }

            $insumoEntities[$insumoId] = [
                'entity' => $insumo,
                'cantidad' => $cantidad,
            ];
        }

        // Armar TransactWriteItems para DynamoDB
        $transactItems = [];
        $nowIso = now()->toIso8601String();
        $generatedMovimientos = [];

        // 1. Actualización atómica de la cita a COMPLETADA
        $transactItems[] = [
            'Update' => [
                'TableName' => 'citas',
                'Key' => $this->marshaler->marshalItem(['id' => $citaId]),
                'UpdateExpression' => 'SET estado = :nuevo_estado',
                'ConditionExpression' => 'attribute_exists(id) AND estado <> :cancelado AND estado <> :completado',
                'ExpressionAttributeValues' => $this->marshaler->marshalItem([
                    ':nuevo_estado' => Cita::ESTADO_COMPLETADA,
                    ':cancelado' => Cita::ESTADO_CANCELADA,
                    ':completado' => Cita::ESTADO_COMPLETADA,
                ]),
            ],
        ];

        // 2. Descuento condicional de cada insumo y registro en kardex (movimientos_stock)
        foreach ($insumoEntities as $insumoId => $data) {
            /** @var Insumo $insumo */
            $insumo = $data['entity'];
            $cantidad = $data['cantidad'];
            $stockPosterior = $insumo->stockActual - $cantidad;
            $movId = (string) Str::uuid();

            // Descuento atómico con condición CAS sobre el snapshot leído y anti-negativos
            $transactItems[] = [
                'Update' => [
                    'TableName' => 'insumos',
                    'Key' => $this->marshaler->marshalItem(['id' => $insumoId]),
                    'UpdateExpression' => 'SET stock_actual = :next',
                    'ConditionExpression' => 'attribute_exists(id) AND stock_actual = :previous AND stock_actual >= :cant',
                    'ExpressionAttributeValues' => $this->marshaler->marshalItem([
                        ':next' => $stockPosterior,
                        ':previous' => $insumo->stockActual,
                        ':cant' => $cantidad,
                    ]),
                ],
            ];

            // Inserción en tabla movimientos_stock con garantía de id único
            $movArray = [
                'id' => $movId,
                'insumo_id' => $insumoId,
                'tipo_movimiento' => MovimientoStock::TIPO_SALIDA_PROCEDIMIENTO,
                'cantidad' => $cantidad,
                'stock_anterior' => $insumo->stockActual,
                'stock_posterior' => $stockPosterior,
                'motivo' => $motivo ?? "Descuento por atención clínica de cita {$citaId}",
                'usuario_id' => $usuarioId ?? 'sistema',
                'fecha_hora' => $nowIso,
            ];

            $transactItems[] = [
                'Put' => [
                    'TableName' => 'movimientos_stock',
                    'Item' => $this->marshaler->marshalItem($movArray),
                    'ConditionExpression' => 'attribute_not_exists(id)',
                ],
            ];

            $generatedMovimientos[] = $movArray;
        }

        // Ejecutar transacción atómica en DynamoDB si el servicio está activo
        if (DynamoDbConnectionManager::isAvailable()) {
            try {
                $this->dynamoDbClient->transactWriteItems([
                    '@retries' => 0,
                    'TransactItems' => $transactItems,
                ]);
            } catch (DynamoDbException|AwsException $e) {
                if (str_contains($e->getMessage(), 'ConditionalCheckFailed')
                    || $e->getAwsErrorCode() === 'TransactionCanceledException'
                    || str_contains($e->getMessage(), 'TransactionCanceledException')) {
                    throw new InvalidArgumentException(
                        'Transacción DynamoDB rechazada: Conflicto de concurrencia, stock insuficiente o cita ya completada/cancelada.',
                        0,
                        $e
                    );
                }
                // Si la transacción falla o tiene resultado incierto: FAIL CLOSED. Sin fallback a memoria.
                throw new \RuntimeException('Fallo de persistencia al completar cita: servicio de base de datos no disponible.', 0, $e);
            } catch (Throwable $e) {
                throw new \RuntimeException('Fallo inesperado al completar cita: '.$e->getMessage(), 0, $e);
            }

            // Sincronizar únicamente la entidad en memoria devuelta SIN escrituras redundantes a DynamoDB
            $cita->completar();
        } else {
            // Entorno sin persistencia DynamoDB (repositorios simulados en memoria / unit tests puros)
            $cita->completar();
            $this->citaRepository->save($cita);

            foreach ($insumoEntities as $insumoId => $data) {
                /** @var Insumo $insumo */
                $insumo = $data['entity'];
                $cantidad = $data['cantidad'];
                $stockPosterior = $insumo->stockActual - $cantidad;

                $updatedInsumo = new Insumo(
                    id: $insumo->id,
                    codigo: $insumo->codigo,
                    nombre: $insumo->nombre,
                    stockActual: $stockPosterior,
                    stockMinimo: $insumo->stockMinimo,
                    unidadMedida: $insumo->unidadMedida
                );
                $this->insumoRepository->save($updatedInsumo);
            }

            foreach ($generatedMovimientos as $mov) {
                $movimientoEntity = new MovimientoStock(
                    id: $mov['id'],
                    insumoId: $mov['insumo_id'],
                    tipoMovimiento: $mov['tipo_movimiento'],
                    cantidad: $mov['cantidad'],
                    stockAnterior: $mov['stock_anterior'],
                    stockPosterior: $mov['stock_posterior'],
                    motivo: $mov['motivo'],
                    usuarioId: $mov['usuario_id'],
                    fechaHora: $mov['fecha_hora']
                );
                $this->movimientoRepository->save($movimientoEntity);
            }
        }

        return [
            'cita' => $cita->toArray(),
            'insumos_descontados' => count($insumoEntities),
            'movimientos' => $generatedMovimientos,
            'mensaje' => 'Cita completada con éxito y stock descontado atómicamente.',
        ];
    }

    private function canUseInMemoryFallback(): bool
    {
        $explicitFallback = env('DYNAMODB_FALLBACK_MEMORY');
        if ($explicitFallback !== null) {
            return filter_var($explicitFallback, FILTER_VALIDATE_BOOLEAN);
        }

        $appEnv = env('APP_ENV');
        if ($appEnv === 'testing') {
            return true;
        }

        return false;
    }
}
