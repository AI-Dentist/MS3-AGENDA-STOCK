<?php

namespace Tests\Feature;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\CommandInterface;
use Mockery;
use Tests\TestCase;

class DynamoDbSetupCommandTest extends TestCase
{
    public function test_dynamodb_setup_reports_existing_tables_idempotently(): void
    {
        $mockClient = Mockery::mock(DynamoDbClient::class);
        $mockClient->shouldReceive('listTables')
            ->once()
            ->andReturn([
                'TableNames' => ['citas', 'horarios', 'insumos', 'movimientos_stock'],
            ]);

        $this->app->instance(DynamoDbClient::class, $mockClient);

        $this->artisan('dynamodb:setup')
            ->expectsOutputToContain("Tabla 'citas' ya existe en DynamoDB")
            ->expectsOutputToContain("Tabla 'horarios' ya existe en DynamoDB")
            ->expectsOutputToContain("Tabla 'insumos' ya existe en DynamoDB")
            ->expectsOutputToContain("Tabla 'movimientos_stock' ya existe en DynamoDB")
            ->assertExitCode(0);
    }

    public function test_dynamodb_setup_creates_tables_and_handles_resource_in_use_exception(): void
    {
        $mockClient = Mockery::mock(DynamoDbClient::class);
        $mockClient->shouldReceive('listTables')
            ->once()
            ->andReturn([
                'TableNames' => [],
            ]);

        $awsCommand = Mockery::mock(CommandInterface::class);
        $resourceInUseException = new DynamoDbException(
            'Cannot create existing table',
            $awsCommand,
            ['code' => 'ResourceInUseException']
        );

        $mockClient->shouldReceive('createTable')
            ->times(4)
            ->andThrow($resourceInUseException);

        $this->app->instance(DynamoDbClient::class, $mockClient);

        $this->artisan('dynamodb:setup')
            ->expectsOutputToContain("ResourceInUseException")
            ->assertExitCode(0);
    }
}
