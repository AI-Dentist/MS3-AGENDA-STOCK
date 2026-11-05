<?php

namespace Tests\Support;

use Aws\CommandInterface;
use Aws\DynamoDb\Marshaler;
use Aws\Exception\AwsException;
use Aws\Result;
use GuzzleHttp\Promise\Create;

/**
 * Synthetic DynamoDB handler for Appointment and Stock concurrency testing.
 */
class AppointmentStockDatabase
{
    public array $tables = [
        'citas' => [],
        'insumos' => [],
        'movimientos_stock' => [],
    ];
    public array $transactions = [];
    public array $putItems = [];
    public string $failure = '';
    public ?\Closure $beforeTransaction = null;
    public ?\Closure $afterTransaction = null;
    public array $reads = [];
    private Marshaler $marshaler;

    public function __construct()
    {
        $this->marshaler = new Marshaler;
    }

    public function __invoke(CommandInterface $command)
    {
        $name = $command->getName();
        $table = $command['TableName'] ?? '';

        if ($name === 'GetItem') {
            $this->reads[] = $command->toArray();
            $key = isset($command['Key']) ? $this->marshaler->unmarshalItem($command['Key'])['id'] : null;
            if ($this->failure === 'read-unavailable') {
                return Create::rejectionFor(new AwsException('synthetic read failure', $command, ['code' => 'InternalServerError']));
            }
            return Create::promiseFor(new Result(isset($this->tables[$table][$key])
                ? ['Item' => $this->marshaler->marshalItem($this->tables[$table][$key])] : []));
        }

        if ($name === 'Scan' || $name === 'Query') {
            $items = array_values($this->tables[$table] ?? []);
            if ($name === 'Query' && isset($command['ExpressionAttributeValues'])) {
                $values = $this->marshaler->unmarshalItem($command['ExpressionAttributeValues']);
                if (isset($values[':ins'])) {
                    $items = array_values(array_filter($items, fn ($item) => ($item['insumo_id'] ?? null) === $values[':ins']));
                }
            }
            return Create::promiseFor(new Result(['Items' => array_map($this->marshaler->marshalItem(...), $items)]));
        }

        if ($name === 'PutItem') {
            $item = $this->marshaler->unmarshalItem($command['Item']);
            $this->putItems[] = ['table' => $table, 'item' => $item];
            $this->tables[$table][$item['id']] = $item;
            return Create::promiseFor(new Result);
        }

        if ($name === 'UpdateItem') {
            $key = isset($command['Key']) ? $this->marshaler->unmarshalItem($command['Key'])['id'] : null;
            $values = isset($command['ExpressionAttributeValues']) ? $this->marshaler->unmarshalItem($command['ExpressionAttributeValues']) : [];
            if (isset($values[':s'])) {
                $this->tables[$table][$key]['stock_actual'] = $values[':s'];
            }
            return Create::promiseFor(new Result);
        }

        if ($name === 'TransactWriteItems') {
            $this->transactions[] = $command->toArray();

            if ($this->beforeTransaction !== null) {
                $hook = $this->beforeTransaction;
                $this->beforeTransaction = null;
                $hook();
            }

            if ($this->failure === 'unavailable') {
                return Create::rejectionFor(new AwsException('synthetic unavailable', $command, ['code' => 'InternalServerError']));
            }

            // Validar condiciones de cada operación en la transacción
            foreach ($command['TransactItems'] as $operation) {
                if (isset($operation['Update'])) {
                    $update = $operation['Update'];
                    $tName = $update['TableName'];
                    $key = $this->marshaler->unmarshalItem($update['Key'])['id'];
                    $cond = $update['ConditionExpression'] ?? '';
                    $values = isset($update['ExpressionAttributeValues']) ? $this->marshaler->unmarshalItem($update['ExpressionAttributeValues']) : [];

                    $current = $this->tables[$tName][$key] ?? null;
                    if ($current === null) {
                        return Create::rejectionFor(new AwsException('ConditionalCheckFailed: item not found', $command, ['code' => 'TransactionCanceledException']));
                    }

                    if ($tName === 'citas') {
                        if (($current['estado'] ?? '') === 'CANCELADA' || ($current['estado'] ?? '') === 'COMPLETADA') {
                            return Create::rejectionFor(new AwsException('ConditionalCheckFailed: cita not reservada', $command, ['code' => 'TransactionCanceledException']));
                        }
                    } elseif ($tName === 'insumos') {
                        // CAS condition check: stock_actual = :previous AND stock_actual >= :cant
                        $previous = $values[':previous'] ?? null;
                        $cant = $values[':cant'] ?? 0;
                        $currStock = (int) ($current['stock_actual'] ?? 0);

                        if ($previous !== null && $currStock !== (int) $previous) {
                            return Create::rejectionFor(new AwsException("ConditionalCheckFailed: stock CAS mismatch (expected {$previous}, got {$currStock})", $command, ['code' => 'TransactionCanceledException']));
                        }
                        if ($currStock < (int) $cant) {
                            return Create::rejectionFor(new AwsException("ConditionalCheckFailed: insufficient stock ({$currStock} < {$cant})", $command, ['code' => 'TransactionCanceledException']));
                        }
                    }
                } elseif (isset($operation['Put'])) {
                    $put = $operation['Put'];
                    $tName = $put['TableName'];
                    $item = $this->marshaler->unmarshalItem($put['Item']);
                    $cond = $put['ConditionExpression'] ?? '';
                    if (str_contains($cond, 'attribute_not_exists') && isset($this->tables[$tName][$item['id']])) {
                        return Create::rejectionFor(new AwsException('ConditionalCheckFailed: duplicate item', $command, ['code' => 'TransactionCanceledException']));
                    }
                }
            }

            // Aplicar todas las mutaciones
            foreach ($command['TransactItems'] as $operation) {
                if (isset($operation['Update'])) {
                    $update = $operation['Update'];
                    $tName = $update['TableName'];
                    $key = $this->marshaler->unmarshalItem($update['Key'])['id'];
                    $values = isset($update['ExpressionAttributeValues']) ? $this->marshaler->unmarshalItem($update['ExpressionAttributeValues']) : [];

                    if ($tName === 'citas' && isset($values[':nuevo_estado'])) {
                        $this->tables[$tName][$key]['estado'] = $values[':nuevo_estado'];
                    } elseif ($tName === 'insumos') {
                        if (isset($values[':next'])) {
                            $this->tables[$tName][$key]['stock_actual'] = (int) $values[':next'];
                        } elseif (isset($values[':cant'])) {
                            $this->tables[$tName][$key]['stock_actual'] -= (int) $values[':cant'];
                        }
                    }
                } elseif (isset($operation['Put'])) {
                    $put = $operation['Put'];
                    $tName = $put['TableName'];
                    $item = $this->marshaler->unmarshalItem($put['Item']);
                    $this->tables[$tName][$item['id']] = $item;
                }
            }

            if ($this->afterTransaction !== null) {
                $hook = $this->afterTransaction;
                $this->afterTransaction = null;
                $hook();
            }

            return Create::promiseFor(new Result);
        }

        throw new \LogicException('Unexpected SDK operation: '.$name);
    }
}
