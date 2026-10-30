<?php

namespace App\Infrastructure\Persistence\DynamoDB;

use App\Domain\Entities\Cita;
use App\Domain\Repositories\CitaRepositoryInterface;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Aws\Exception\AwsException;
use GuzzleHttp\Exception\ConnectException;
use Throwable;

class DynamoDbCitaRepository implements CitaRepositoryInterface
{
    private const TABLE_NAME = 'citas';

    private const GSI_IDEMPOTENCY = 'idempotency_key-index';

    private const GSI_ODONTOLOGO_FECHA = 'odontologo_fecha-index';

    /**
     * In-memory store fallback for isolated unit testing or offline DynamoDB.
     */
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

    public function findById(string $id): ?Cita
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

    public function findByIdempotencyKey(string $key): ?Cita
    {
        if (DynamoDbConnectionManager::isAvailable()) {
            try {
                $result = $this->client->query([
                    'TableName' => self::TABLE_NAME,
                    'IndexName' => self::GSI_IDEMPOTENCY,
                    'KeyConditionExpression' => 'idempotency_key = :k',
                    'ExpressionAttributeValues' => $this->marshaler->marshalItem([':k' => $key]),
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
            if (($item['idempotency_key'] ?? '') === $key) {
                return $this->hydrate($item);
            }
        }

        return null;
    }

    public function existeColision(string $odontologoId, string $consultorioId, string $fechaHora, ?string $excluirCitaId = null): bool
    {
        if (DynamoDbConnectionManager::isAvailable()) {
            try {
                // 1. Validar colisión por Odontólogo usando GSI
                $resOdo = $this->client->query([
                    'TableName' => self::TABLE_NAME,
                    'IndexName' => self::GSI_ODONTOLOGO_FECHA,
                    'KeyConditionExpression' => 'odontologo_id = :odo AND fecha_hora = :fh',
                    'ExpressionAttributeValues' => $this->marshaler->marshalItem([
                        ':odo' => $odontologoId,
                        ':fh' => $fechaHora,
                    ]),
                ]);

                if (! empty($resOdo['Items'])) {
                    foreach ($resOdo['Items'] as $rawItem) {
                        $item = $this->marshaler->unmarshalItem($rawItem);
                        if (($item['estado'] ?? '') === Cita::ESTADO_CANCELADA) {
                            continue;
                        }
                        if ($excluirCitaId && ($item['id'] ?? '') === $excluirCitaId) {
                            continue;
                        }

                        return true;
                    }
                }

                // 2. Validar colisión por Consultorio usando Scan filtrado
                $resCon = $this->client->scan([
                    'TableName' => self::TABLE_NAME,
                    'FilterExpression' => 'consultorio_id = :con AND fecha_hora = :fh AND estado <> :canc',
                    'ExpressionAttributeValues' => $this->marshaler->marshalItem([
                        ':con' => $consultorioId,
                        ':fh' => $fechaHora,
                        ':canc' => Cita::ESTADO_CANCELADA,
                    ]),
                ]);

                if (! empty($resCon['Items'])) {
                    foreach ($resCon['Items'] as $rawItem) {
                        $item = $this->marshaler->unmarshalItem($rawItem);
                        if ($excluirCitaId && ($item['id'] ?? '') === $excluirCitaId) {
                            continue;
                        }

                        return true;
                    }
                }

                return false;
            } catch (Throwable $e) {
                if (! $this->shouldFallback($e)) {
                    throw $e;
                }
            }
        }

        foreach (self::$inMemoryStore as $item) {
            if (($item['estado'] ?? '') === Cita::ESTADO_CANCELADA) {
                continue;
            }
            if ($excluirCitaId && ($item['id'] ?? '') === $excluirCitaId) {
                continue;
            }
            if ($item['fecha_hora'] === $fechaHora) {
                if ($item['odontologo_id'] === $odontologoId || $item['consultorio_id'] === $consultorioId) {
                    return true;
                }
            }
        }

        return false;
    }

    public function save(Cita $cita): void
    {
        $itemArray = $cita->toArray();
        self::$inMemoryStore[$cita->id] = $itemArray;

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

    public function updateEstado(string $id, string $nuevoEstado): void
    {
        if (isset(self::$inMemoryStore[$id])) {
            self::$inMemoryStore[$id]['estado'] = $nuevoEstado;
        }

        if (DynamoDbConnectionManager::isAvailable()) {
            try {
                $this->client->updateItem([
                    'TableName' => self::TABLE_NAME,
                    'Key' => $this->marshaler->marshalItem(['id' => $id]),
                    'UpdateExpression' => 'SET estado = :nuevo_estado',
                    'ExpressionAttributeValues' => $this->marshaler->marshalItem([
                        ':nuevo_estado' => $nuevoEstado,
                    ]),
                ]);
            } catch (Throwable $e) {
                if (! $this->shouldFallback($e)) {
                    throw $e;
                }
            }
        }
    }

    public function findAll(): array
    {
        if (DynamoDbConnectionManager::isAvailable()) {
            try {
                $result = $this->client->scan([
                    'TableName' => self::TABLE_NAME,
                ]);

                $citas = [];
                foreach ($result['Items'] ?? [] as $rawItem) {
                    $citas[] = $this->hydrate($this->marshaler->unmarshalItem($rawItem));
                }

                return $citas;
            } catch (Throwable $e) {
                if (! $this->shouldFallback($e)) {
                    throw $e;
                }
            }
        }

        $citas = [];
        foreach (self::$inMemoryStore as $item) {
            $citas[] = $this->hydrate($item);
        }

        return $citas;
    }

    private function hydrate(array $data): Cita
    {
        return new Cita(
            id: $data['id'],
            idempotencyKey: $data['idempotency_key'] ?? '',
            odontologoId: $data['odontologo_id'],
            consultorioId: $data['consultorio_id'],
            pacienteId: $data['paciente_id'],
            fechaHora: $data['fecha_hora'],
            estado: $data['estado'] ?? Cita::ESTADO_RESERVADA
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
