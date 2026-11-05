<?php

namespace App\Infrastructure\Persistence\DynamoDB;

use App\Domain\Entities\Insumo;
use App\Domain\Entities\MovimientoStock;
use App\Domain\Repositories\StockMovementWriterInterface;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use UnexpectedValueException;

class DynamoDbStockMovementWriter implements StockMovementWriterInterface
{
    private Marshaler $marshaler;

    public function __construct(private DynamoDbClient $client)
    {
        $this->marshaler = new Marshaler;
    }

    private function read(string $table, string $id): ?array
    {
        $result = $this->client->getItem([
            'TableName' => $table,
            'Key' => $this->marshaler->marshalItem(['id' => $id]),
            'ConsistentRead' => true,
            '@retries' => 0,
        ]);

        return isset($result['Item']) ? $this->marshaler->unmarshalItem($result['Item']) : null;
    }

    public function findInsumo(string $id): ?Insumo
    {
        $data = $this->read('insumos', $id);
        if ($data === null) {
            return null;
        }

        return new Insumo(
            id: $data['id'], codigo: $data['codigo'], nombre: $data['nombre'],
            stockActual: (int) $data['stock_actual'], stockMinimo: (int) $data['stock_minimo'],
            unidadMedida: $data['unidad_medida']
        );
    }

    public function findOutcome(string $movementId): ?array
    {
        $data = $this->read('movimientos_stock', $movementId);
        if ($data === null) {
            return null;
        }
        // Un registro incompleto/corrupto nunca equivale a éxito ni permite otra escritura.
        $body = json_decode($data['response_json'] ?? '', true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($body) || ($data['response_status'] ?? null) !== 201 || ! is_string($data['payload_hash'] ?? null)) {
            throw new UnexpectedValueException('Resultado durable de stock inválido.');
        }

        return ['payload_hash' => $data['payload_hash'], 'status' => 201, 'body' => $body];
    }

    public function commit(Insumo $previous, MovimientoStock $movement, string $payloadHash, array $body): void
    {
        $item = $movement->toArray();
        $item['payload_hash'] = $payloadHash;
        $item['response_status'] = 201;
        $item['response_json'] = json_encode($body, JSON_THROW_ON_ERROR);

        $this->client->transactWriteItems([
            '@retries' => 0,
            'TransactItems' => [
                ['Update' => [
                    'TableName' => 'insumos',
                    'Key' => $this->marshaler->marshalItem(['id' => $previous->id]),
                    'UpdateExpression' => 'SET stock_actual = :next',
                    'ConditionExpression' => 'attribute_exists(id) AND stock_actual = :previous AND stock_actual >= :minimum',
                    'ExpressionAttributeValues' => $this->marshaler->marshalItem([
                        ':next' => $movement->stockPosterior,
                        ':previous' => $previous->stockActual,
                        ':minimum' => max(0, $previous->stockActual - $movement->stockPosterior),
                    ]),
                ]],
                ['Put' => [
                    'TableName' => 'movimientos_stock',
                    'Item' => $this->marshaler->marshalItem($item),
                    'ConditionExpression' => 'attribute_not_exists(id)',
                ]],
            ],
        ]);
    }
}
