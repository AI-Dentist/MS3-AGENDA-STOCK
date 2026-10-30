<?php

namespace App\Infrastructure\Persistence\DynamoDB;

use App\Domain\Entities\MovimientoStock;
use App\Domain\Repositories\MovimientoStockRepositoryInterface;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Aws\Exception\AwsException;
use GuzzleHttp\Exception\ConnectException;
use Throwable;

class DynamoDbMovimientoStockRepository implements MovimientoStockRepositoryInterface
{
    private const TABLE_NAME = 'movimientos_stock';

    private const GSI_INSUMO = 'insumo_id-index';

    private static array $inMemoryStore = [];

    public function __construct(
        private DynamoDbClient $client,
        private ?Marshaler $marshaler = null
    ) {
        $this->marshaler = $marshaler ?? new Marshaler;
    }

    public static function resetInMemoryStore(): void
    {
        self::$inMemoryStore = [];
    }

    public function save(MovimientoStock $movimiento): void
    {
        $itemArray = $movimiento->toArray();
        self::$inMemoryStore[$movimiento->id] = $itemArray;

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

    public function findByInsumoId(string $insumoId): array
    {
        if (DynamoDbConnectionManager::isAvailable()) {
            try {
                $result = $this->client->query([
                    'TableName' => self::TABLE_NAME,
                    'IndexName' => self::GSI_INSUMO,
                    'KeyConditionExpression' => 'insumo_id = :ins',
                    'ExpressionAttributeValues' => $this->marshaler->marshalItem([':ins' => $insumoId]),
                ]);

                $movimientos = [];
                foreach ($result['Items'] ?? [] as $rawItem) {
                    $movimientos[] = $this->hydrate($this->marshaler->unmarshalItem($rawItem));
                }

                return $movimientos;
            } catch (Throwable $e) {
                if (! $this->shouldFallback($e)) {
                    throw $e;
                }
            }
        }

        $movimientos = [];
        foreach (self::$inMemoryStore as $item) {
            if (($item['insumo_id'] ?? '') === $insumoId) {
                $movimientos[] = $this->hydrate($item);
            }
        }

        return $movimientos;
    }

    public function findAll(): array
    {
        if (DynamoDbConnectionManager::isAvailable()) {
            try {
                $result = $this->client->scan([
                    'TableName' => self::TABLE_NAME,
                ]);

                $movimientos = [];
                foreach ($result['Items'] ?? [] as $rawItem) {
                    $movimientos[] = $this->hydrate($this->marshaler->unmarshalItem($rawItem));
                }

                return $movimientos;
            } catch (Throwable $e) {
                if (! $this->shouldFallback($e)) {
                    throw $e;
                }
            }
        }

        $movimientos = [];
        foreach (self::$inMemoryStore as $item) {
            $movimientos[] = $this->hydrate($item);
        }

        return $movimientos;
    }

    private function hydrate(array $data): MovimientoStock
    {
        return new MovimientoStock(
            id: $data['id'],
            insumoId: $data['insumo_id'],
            tipoMovimiento: $data['tipo_movimiento'],
            cantidad: (int) $data['cantidad'],
            stockAnterior: (int) $data['stock_anterior'],
            stockPosterior: (int) $data['stock_posterior'],
            motivo: $data['motivo'] ?? '',
            usuarioId: $data['usuario_id'] ?? null,
            fechaHora: $data['fecha_hora'] ?? null
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
