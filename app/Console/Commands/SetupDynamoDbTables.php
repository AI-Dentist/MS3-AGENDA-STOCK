<?php

namespace App\Console\Commands;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\DynamoDb\Marshaler;
use Illuminate\Console\Command;
use Throwable;

class SetupDynamoDbTables extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dynamodb:setup {--seed : Cargar datos iniciales de prueba tras crear las tablas}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crea las tablas DynamoDB y sus GSIs requeridos para MS3 (citas, horarios, insumos, movimientos_stock)';

    public function handle(DynamoDbClient $client, Marshaler $marshaler): int
    {
        $this->info('Iniciando configuración de tablas en DynamoDB...');

        try {
            $existingTables = [];
            $lastEvaluatedTableName = null;
            do {
                $params = [];
                if ($lastEvaluatedTableName) {
                    $params['ExclusiveStartTableName'] = $lastEvaluatedTableName;
                }
                $result = $client->listTables($params);
                $existingTables = array_merge($existingTables, $result['TableNames'] ?? []);
                $lastEvaluatedTableName = $result['LastEvaluatedTableName'] ?? null;
            } while ($lastEvaluatedTableName);
        } catch (Throwable $e) {
            $this->error('No se pudo conectar a DynamoDB: '.$e->getMessage());
            $endpoint = env('DYNAMODB_ENDPOINT') ?: 'AWS DynamoDB ('.env('AWS_DEFAULT_REGION', 'us-east-1').')';
            $this->warn('Verifica la conectividad con DynamoDB en '.$endpoint);

            return 1;
        }

        $tables = [
            'citas' => [
                'TableName' => 'citas',
                'KeySchema' => [
                    ['AttributeName' => 'id', 'KeyType' => 'HASH'],
                ],
                'AttributeDefinitions' => [
                    ['AttributeName' => 'id', 'AttributeType' => 'S'],
                    ['AttributeName' => 'idempotency_key', 'AttributeType' => 'S'],
                    ['AttributeName' => 'odontologo_id', 'AttributeType' => 'S'],
                    ['AttributeName' => 'fecha_hora', 'AttributeType' => 'S'],
                ],
                'GlobalSecondaryIndexes' => [
                    [
                        'IndexName' => 'idempotency_key-index',
                        'KeySchema' => [
                            ['AttributeName' => 'idempotency_key', 'KeyType' => 'HASH'],
                        ],
                        'Projection' => ['ProjectionType' => 'ALL'],
                    ],
                    [
                        'IndexName' => 'odontologo_fecha-index',
                        'KeySchema' => [
                            ['AttributeName' => 'odontologo_id', 'KeyType' => 'HASH'],
                            ['AttributeName' => 'fecha_hora', 'KeyType' => 'RANGE'],
                        ],
                        'Projection' => ['ProjectionType' => 'ALL'],
                    ],
                ],
                'BillingMode' => 'PAY_PER_REQUEST',
            ],
            'horarios' => [
                'TableName' => 'horarios',
                'KeySchema' => [
                    ['AttributeName' => 'id', 'KeyType' => 'HASH'],
                ],
                'AttributeDefinitions' => [
                    ['AttributeName' => 'id', 'AttributeType' => 'S'],
                    ['AttributeName' => 'odontologo_id', 'AttributeType' => 'S'],
                ],
                'GlobalSecondaryIndexes' => [
                    [
                        'IndexName' => 'odontologo_id-index',
                        'KeySchema' => [
                            ['AttributeName' => 'odontologo_id', 'KeyType' => 'HASH'],
                        ],
                        'Projection' => ['ProjectionType' => 'ALL'],
                    ],
                ],
                'BillingMode' => 'PAY_PER_REQUEST',
            ],
            'insumos' => [
                'TableName' => 'insumos',
                'KeySchema' => [
                    ['AttributeName' => 'id', 'KeyType' => 'HASH'],
                ],
                'AttributeDefinitions' => [
                    ['AttributeName' => 'id', 'AttributeType' => 'S'],
                    ['AttributeName' => 'codigo', 'AttributeType' => 'S'],
                ],
                'GlobalSecondaryIndexes' => [
                    [
                        'IndexName' => 'codigo-index',
                        'KeySchema' => [
                            ['AttributeName' => 'codigo', 'KeyType' => 'HASH'],
                        ],
                        'Projection' => ['ProjectionType' => 'ALL'],
                    ],
                ],
                'BillingMode' => 'PAY_PER_REQUEST',
            ],
            'movimientos_stock' => [
                'TableName' => 'movimientos_stock',
                'KeySchema' => [
                    ['AttributeName' => 'id', 'KeyType' => 'HASH'],
                ],
                'AttributeDefinitions' => [
                    ['AttributeName' => 'id', 'AttributeType' => 'S'],
                    ['AttributeName' => 'insumo_id', 'AttributeType' => 'S'],
                ],
                'GlobalSecondaryIndexes' => [
                    [
                        'IndexName' => 'insumo_id-index',
                        'KeySchema' => [
                            ['AttributeName' => 'insumo_id', 'KeyType' => 'HASH'],
                        ],
                        'Projection' => ['ProjectionType' => 'ALL'],
                    ],
                ],
                'BillingMode' => 'PAY_PER_REQUEST',
            ],
        ];

        foreach ($tables as $name => $schema) {
            if (in_array($name, $existingTables, true)) {
                $this->line("  -> Tabla '{$name}' ya existe en DynamoDB. Omitiendo creación.");

                continue;
            }

            try {
                $client->createTable($schema);
                $this->info("  [CREADA] Tabla '{$name}' creada con éxito.");
            } catch (DynamoDbException $e) {
                if ($e->getAwsErrorCode() === 'ResourceInUseException') {
                    $this->line("  -> Tabla '{$name}' ya existe en DynamoDB (ResourceInUseException). Omitiendo creación.");
                } else {
                    $this->error("  [ERROR] Error al crear tabla '{$name}': ".$e->getMessage());
                }
            }
        }

        if ($this->option('seed')) {
            $this->seedInitialData($client, $marshaler);
        }

        $this->info('Configuración de DynamoDB completada.');

        return 0;
    }

    private function seedInitialData(DynamoDbClient $client, Marshaler $marshaler): void
    {
        $this->info('Sembrando datos iniciales en DynamoDB...');

        // Horarios iniciales
        $horarios = [
            [
                'id' => 'hor-001',
                'odontologo_id' => 'odo-123',
                'especialidad' => 'Ortodoncia',
                'consultorio_id' => 'con-01',
                'fecha' => '2026-11-15',
                'hora_inicio' => '08:00',
                'hora_fin' => '12:00',
                'disponible' => true,
            ],
            [
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

        foreach ($horarios as $h) {
            $client->putItem([
                'TableName' => 'horarios',
                'Item' => $marshaler->marshalItem($h),
            ]);
        }

        // Insumos iniciales
        $insumos = [
            [
                'id' => 'ins-001',
                'codigo' => 'GUAN-LAT-M',
                'nombre' => 'Guantes de Látex Talla M',
                'stock_actual' => 12,
                'stock_minimo' => 20,
                'unidad_medida' => 'Cajas',
            ],
            [
                'id' => 'ins-002',
                'codigo' => 'ANES-LIDO-2',
                'nombre' => 'Lidocaína 2% con Epinefrina',
                'stock_actual' => 50,
                'stock_minimo' => 15,
                'unidad_medida' => 'Cartuchos',
            ],
        ];

        foreach ($insumos as $ins) {
            $client->putItem([
                'TableName' => 'insumos',
                'Item' => $marshaler->marshalItem($ins),
            ]);
        }

        $this->info('  Datos iniciales sembrados con éxito.');
    }
}
