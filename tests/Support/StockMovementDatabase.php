<?php

namespace Tests\Support;

use Aws\CommandInterface;
use Aws\DynamoDb\Marshaler;
use Aws\Exception\AwsException;
use Aws\Result;
use GuzzleHttp\Promise\Create;

/** Synthetic SDK boundary, not proof of DynamoDB's transaction implementation. */
class StockMovementDatabase
{
    public array $tables = ['insumos' => [], 'movimientos_stock' => []];
    public array $transactions = [];
    public string $failure = '';
    public ?\Closure $beforeTransaction = null;
    public array $reads = [];
    private Marshaler $marshaler;

    public function __construct()
    {
        $this->marshaler = new Marshaler;
    }

    public function __invoke(CommandInterface $command)
    {
        $name = $command->getName();
        $table = $command['TableName'];
        $key = isset($command['Key']) ? $this->marshaler->unmarshalItem($command['Key'])['id'] : null;
        if ($name === 'GetItem') {
            $this->reads[] = $command->toArray();
            if ($this->failure === 'read-unavailable'
                || ($this->failure === 'lost-readback' && $this->transactions !== [])) {
                return Create::rejectionFor(new AwsException('synthetic read failure', $command, ['code' => 'InternalServerError']));
            }
            return Create::promiseFor(new Result(isset($this->tables[$table][$key])
                ? ['Item' => $this->marshaler->marshalItem($this->tables[$table][$key])] : []));
        }
        if ($name === 'Scan' || $name === 'Query') {
            $items = array_values($this->tables[$table]);
            if ($name === 'Query') {
                $values = $this->marshaler->unmarshalItem($command['ExpressionAttributeValues']);
                $items = array_values(array_filter($items, fn ($item) => ($item['insumo_id'] ?? null) === $values[':ins']));
            }
            return Create::promiseFor(new Result(['Items' => array_map($this->marshaler->marshalItem(...), $items)]));
        }
        if ($name === 'PutItem') {
            $item = $this->marshaler->unmarshalItem($command['Item']);
            $this->tables[$table][$item['id']] = $item;
        } elseif ($name === 'UpdateItem') {
            $values = $this->marshaler->unmarshalItem($command['ExpressionAttributeValues']);
            $this->tables[$table][$key]['stock_actual'] = $values[':s'];
        } elseif ($name === 'TransactWriteItems') {
            $this->transactions[] = $command->toArray();
            if ($command['@retries'] !== 0 || count($command['TransactItems']) !== 2) {
                throw new \LogicException('Manual stock must issue one non-retried, two-item transaction.');
            }
            if ($this->beforeTransaction !== null) {
                $hook = $this->beforeTransaction;
                $this->beforeTransaction = null;
                $hook();
            }
            if ($this->failure === 'collision') {
                return Create::rejectionFor(new AwsException('synthetic collision', $command, ['code' => 'TransactionCanceledException']));
            }
            if ($this->failure === 'unavailable') {
                return Create::rejectionFor(new AwsException('synthetic unavailable', $command, ['code' => 'InternalServerError']));
            }
            // Validate every condition before applying any item.
            foreach ($command['TransactItems'] as $operation) {
                if (isset($operation['Update'])) {
                    $update = $operation['Update'];
                    $id = $this->marshaler->unmarshalItem($update['Key'])['id'];
                    $values = $this->marshaler->unmarshalItem($update['ExpressionAttributeValues']);
                    if ($update['ConditionExpression'] !== 'attribute_exists(id) AND stock_actual = :previous AND stock_actual >= :minimum'
                        || $update['UpdateExpression'] !== 'SET stock_actual = :next') {
                        throw new \LogicException('Missing stock CAS/anti-negative condition.');
                    }
                    $stock = $this->tables['insumos'][$id]['stock_actual'] ?? null;
                    if ($stock !== $values[':previous'] || $stock < $values[':minimum'] || $values[':next'] < 0) {
                        return Create::rejectionFor(new AwsException('synthetic CAS', $command, ['code' => 'TransactionCanceledException']));
                    }
                } else {
                    $put = $operation['Put'];
                    if ($put['ConditionExpression'] !== 'attribute_not_exists(id)') {
                        throw new \LogicException('Missing movement uniqueness condition.');
                    }
                    $item = $this->marshaler->unmarshalItem($put['Item']);
                    if (isset($this->tables[$put['TableName']][$item['id']])) {
                        return Create::rejectionFor(new AwsException('synthetic duplicate', $command, ['code' => 'TransactionCanceledException']));
                    }
                }
            }
            foreach ($command['TransactItems'] as $operation) {
                if (isset($operation['Update'])) {
                    $update = $operation['Update'];
                    $id = $this->marshaler->unmarshalItem($update['Key'])['id'];
                    $values = $this->marshaler->unmarshalItem($update['ExpressionAttributeValues']);
                    $this->tables['insumos'][$id]['stock_actual'] = $values[':next'];
                } else {
                    $put = $operation['Put'];
                    $item = $this->marshaler->unmarshalItem($put['Item']);
                    $this->tables[$put['TableName']][$item['id']] = $item;
                }
            }
            if (in_array($this->failure, ['lost', 'lost-readback'], true)) {
                return Create::rejectionFor(new AwsException('synthetic lost response', $command, ['code' => 'InternalServerError']));
            }
        } else {
            throw new \LogicException('Unexpected SDK operation: '.$name);
        }
        return Create::promiseFor(new Result);
    }
}
