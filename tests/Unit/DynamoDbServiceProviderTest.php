<?php

namespace Tests\Unit;

use App\Providers\DynamoDbServiceProvider;
use Aws\DynamoDb\DynamoDbClient;
use Illuminate\Support\Env;
use Tests\TestCase;

class DynamoDbServiceProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->forgetInstance(DynamoDbClient::class);
    }

    protected function tearDown(): void
    {
        $this->app->forgetInstance(DynamoDbClient::class);
        Env::getRepository()->clear('AWS_ACCESS_KEY_ID');
        Env::getRepository()->clear('AWS_SECRET_ACCESS_KEY');
        Env::getRepository()->clear('DYNAMODB_ENDPOINT');
        Env::getRepository()->clear('AWS_DEFAULT_REGION');
        parent::tearDown();
    }

    public function test_dynamodb_client_is_registered_as_singleton(): void
    {
        $client1 = $this->app->make(DynamoDbClient::class);
        $client2 = $this->app->make(DynamoDbClient::class);

        $this->assertInstanceOf(DynamoDbClient::class, $client1);
        $this->assertSame($client1, $client2);
    }

    public function test_omits_static_credentials_when_env_keys_are_empty_for_iam_role(): void
    {
        $this->app->forgetInstance(DynamoDbClient::class);
        Env::getRepository()->set('AWS_ACCESS_KEY_ID', '');
        Env::getRepository()->set('AWS_SECRET_ACCESS_KEY', '');
        Env::getRepository()->set('DYNAMODB_ENDPOINT', '');
        Env::getRepository()->set('AWS_DEFAULT_REGION', 'us-east-1');

        $provider = new DynamoDbServiceProvider($this->app);
        $provider->register();

        $client = $this->app->make(DynamoDbClient::class);
        $this->assertInstanceOf(DynamoDbClient::class, $client);
        $this->assertSame('us-east-1', $client->getRegion());
    }

    public function test_uses_static_credentials_when_env_keys_are_provided(): void
    {
        $this->app->forgetInstance(DynamoDbClient::class);
        Env::getRepository()->set('AWS_ACCESS_KEY_ID', 'test-key');
        Env::getRepository()->set('AWS_SECRET_ACCESS_KEY', 'test-secret');
        Env::getRepository()->set('DYNAMODB_ENDPOINT', 'http://127.0.0.1:8000');
        Env::getRepository()->set('AWS_DEFAULT_REGION', 'us-east-1');

        $provider = new DynamoDbServiceProvider($this->app);
        $provider->register();

        $client = $this->app->make(DynamoDbClient::class);
        $this->assertInstanceOf(DynamoDbClient::class, $client);
        $this->assertSame('http://127.0.0.1:8000', (string) $client->getEndpoint());
    }
}
