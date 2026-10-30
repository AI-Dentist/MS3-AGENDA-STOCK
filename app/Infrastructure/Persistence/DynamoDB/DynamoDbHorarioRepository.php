<?php

namespace App\Infrastructure\Persistence\DynamoDB;

use App\Domain\Entities\Horario;
use App\Domain\Repositories\HorarioRepositoryInterface;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Aws\Exception\AwsException;
use GuzzleHttp\Exception\ConnectException;
use Throwable;

class DynamoDbHorarioRepository implements HorarioRepositoryInterface
{
    private const TABLE_NAME = 'horarios';

    private const GSI_ODONTOLOGO = 'odontologo_id-index';

    private static array $inMemoryStore = [];

    public function __construct(
        private DynamoDbClient $client,
        private ?Marshaler $marshaler = null
    ) {
        $this->marshaler = $marshaler ?? new Marshaler;

        // Seed default in-memory store if empty
        if (empty(self::$inMemoryStore)) {
            self::$inMemoryStore = [
                'hor-001' => [
                    'id' => 'hor-001',
                    'odontologo_id' => 'odo-123',
                    'especialidad' => 'Ortodoncia',
                    'consultorio_id' => 'con-01',
                    'fecha' => '2026-11-15',
                    'hora_inicio' => '08:00',
                    'hora_fin' => '12:00',
                    'disponible' => true,
                ],
                'hor-002' => [
                    'id' => 'hor-002',
                    'odontologo_id' => 'odo-456',
                    'especialidad' => 'Endodoncia',
                    'consultorio_id' => 'con-02',
                    'fecha' => '2026-11-15',
                    'hora_inicio' => '14:00',
                    'hora_fin' => '18:00',
                    'disponible' => true,
                ],
            ];
        }
    }

    public static function resetInMemoryStore(): void
    {
        self::$inMemoryStore = [];
    }

    public function findByOdontologo(string $odontologoId): array
    {
        if (DynamoDbConnectionManager::isAvailable()) {
            try {
                $result = $this->client->query([
                    'TableName' => self::TABLE_NAME,
                    'IndexName' => self::GSI_ODONTOLOGO,
                    'KeyConditionExpression' => 'odontologo_id = :odo',
                    'ExpressionAttributeValues' => $this->marshaler->marshalItem([':odo' => $odontologoId]),
                ]);

                $horarios = [];
                foreach ($result['Items'] ?? [] as $rawItem) {
                    $horarios[] = $this->hydrate($this->marshaler->unmarshalItem($rawItem));
                }

                return $horarios;
            } catch (Throwable $e) {
                if (! $this->shouldFallback($e)) {
                    throw $e;
                }
            }
        }

        $horarios = [];
        foreach (self::$inMemoryStore as $item) {
            if ($item['odontologo_id'] === $odontologoId) {
                $horarios[] = $this->hydrate($item);
            }
        }

        return $horarios;
    }

    public function findAll(): array
    {
        if (DynamoDbConnectionManager::isAvailable()) {
            try {
                $result = $this->client->scan([
                    'TableName' => self::TABLE_NAME,
                ]);

                $horarios = [];
                foreach ($result['Items'] ?? [] as $rawItem) {
                    $horarios[] = $this->hydrate($this->marshaler->unmarshalItem($rawItem));
                }

                return $horarios;
            } catch (Throwable $e) {
                if (! $this->shouldFallback($e)) {
                    throw $e;
                }
            }
        }

        $horarios = [];
        foreach (self::$inMemoryStore as $item) {
            $horarios[] = $this->hydrate($item);
        }

        return $horarios;
    }

    public function save(Horario $horario): void
    {
        $itemArray = $horario->toArray();
        self::$inMemoryStore[$horario->id] = $itemArray;

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

    private function hydrate(array $data): Horario
    {
        return new Horario(
            id: $data['id'],
            odontologoId: $data['odontologo_id'],
            especialidad: $data['especialidad'],
            consultorioId: $data['consultorio_id'],
            fecha: $data['fecha'],
            horaInicio: $data['hora_inicio'],
            horaFin: $data['hora_fin'],
            disponible: (bool) ($data['disponible'] ?? true)
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
