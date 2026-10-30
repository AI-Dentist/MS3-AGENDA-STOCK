<?php

namespace App\Infrastructure\Persistence\DynamoDB;

use App\Domain\Entities\Insumo;
use App\Domain\Repositories\InsumoRepositoryInterface;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Aws\Exception\AwsException;
use GuzzleHttp\Exception\ConnectException;
use Throwable;

class DynamoDbInsumoRepository implements InsumoRepositoryInterface
{
    private const TABLE_NAME = 'insumos';

    private const GSI_CODIGO = 'codigo-index';

    private static array $inMemoryStore = [];

    public function __construct(
        private DynamoDbClient $client,
        private ?Marshaler $marshaler = null
    ) {
        $this->marshaler = $marshaler ?? new Marshaler;

        // Seed default in-memory store if empty
        if (empty(self::$inMemoryStore)) {
            self::$inMemoryStore = [
                'ins-001' => [
                    'id' => 'ins-001',
                    'codigo' => 'GUAN-LAT-M',
                    'nombre' => 'Guantes de Látex Talla M',
                    'stock_actual' => 12,
                    'stock_minimo' => 20,
                    'unidad_medida' => 'Cajas',
                ],
                'ins-002' => [
                    'id' => 'ins-002',
                    'codigo' => 'ANES-LIDO-2',
                    'nombre' => 'Lidocaína 2% con Epinefrina',
                    'stock_actual' => 50,
                    'stock_minimo' => 15,
                    'unidad_medida' => 'Cartuchos',
                ],
            ];
        }
    }

    public static function resetInMemoryStore(): void
    {
        self::$inMemoryStore = [];
    }

    public function findById(string $id): ?Insumo
    {
        if (DynamoDbConnectionManager::isAvailable()) {
            try {
                $result = $this->client->getItem([
                    'TableName' => self::TABLE_NAME,
                    'Key' => $this->marshaler->marshalItem(['id' => $id]),
                ]);

                if (isset($result['Item'])) {
                    $data = $this->marshaler->unmarshalItem($result['Item']);

                    return $this->hydrate($data);
                }

                return null;
            } catch (Throwable $e) {
                if (! $this->shouldFallback($e)) {
                    throw $e;
                }
            }
        }

        $data = self::$inMemoryStore[$id] ?? null;

        return $data ? $this->hydrate($data) : null;
    }

    public function findByCodigo(string $codigo): ?Insumo
    {
        if (DynamoDbConnectionManager::isAvailable()) {
            try {
                $result = $this->client->query([
                    'TableName' => self::TABLE_NAME,
                    'IndexName' => self::GSI_CODIGO,
                    'KeyConditionExpression' => 'codigo = :c',
                    'ExpressionAttributeValues' => $this->marshaler->marshalItem([':c' => $codigo]),
                ]);

                if (! empty($result['Items'])) {
                    $data = $this->marshaler->unmarshalItem($result['Items'][0]);

                    return $this->hydrate($data);
                }

                return null;
            } catch (Throwable $e) {
                if (! $this->shouldFallback($e)) {
                    throw $e;
                }
            }
        }

        foreach (self::$inMemoryStore as $item) {
            if (($item['codigo'] ?? '') === $codigo) {
                return $this->hydrate($item);
            }
        }

        return null;
    }

    public function findAll(): array
    {
        if (DynamoDbConnectionManager::isAvailable()) {
            try {
                $result = $this->client->scan([
                    'TableName' => self::TABLE_NAME,
                ]);

                $insumos = [];
                foreach ($result['Items'] ?? [] as $rawItem) {
                    $insumos[] = $this->hydrate($this->marshaler->unmarshalItem($rawItem));
                }

                return $insumos;
            } catch (Throwable $e) {
                if (! $this->shouldFallback($e)) {
                    throw $e;
                }
            }
        }

        $insumos = [];
        foreach (self::$inMemoryStore as $item) {
            $insumos[] = $this->hydrate($item);
        }

        return $insumos;
    }

    public function save(Insumo $insumo): void
    {
        $itemArray = $insumo->toArray();
        self::$inMemoryStore[$insumo->id] = $itemArray;

        if (DynamoDbConnectionManager::isAvailable()) {
            try {
                $this->client->putItem([
                    'TableName' => self::TABLE_NAME,
                    'Item' => $this->marshaler->marshalItem($itemArray),
                ]);
            } catch (Throwable $e) {
                if (! $this->shouldFallback($e)) {
                    throw $e;
                }
            }
        }
    }

    public function updateStock(string $id, int $nuevoStock): void
    {
        if (isset(self::$inMemoryStore[$id])) {
            self::$inMemoryStore[$id]['stock_actual'] = $nuevoStock;
        }

        if (DynamoDbConnectionManager::isAvailable()) {
            try {
                $this->client->updateItem([
                    'TableName' => self::TABLE_NAME,
                    'Key' => $this->marshaler->marshalItem(['id' => $id]),
                    'UpdateExpression' => 'SET stock_actual = :s',
                    'ExpressionAttributeValues' => $this->marshaler->marshalItem([
                        ':s' => $nuevoStock,
                    ]),
                ]);
            } catch (Throwable $e) {
                if (! $this->shouldFallback($e)) {
                    throw $e;
                }
            }
        }
    }

    private function hydrate(array $data): Insumo
    {
        return new Insumo(
            id: $data['id'],
            codigo: $data['codigo'],
            nombre: $data['nombre'],
            stockActual: (int) $data['stock_actual'],
            stockMinimo: (int) $data['stock_minimo'],
            unidadMedida: $data['unidad_medida']
        );
    }

    private function shouldFallback(Throwable $e): bool
    {
        $isTesting = env('APP_ENV') === 'testing'
            || (function_exists('app') && method_exists(app(), 'environment') && app()->environment('testing'));

        return $e instanceof AwsException
            || $e instanceof ConnectException
            || $isTesting
            || env('DYNAMODB_FALLBACK_MEMORY', true);
    }
}
