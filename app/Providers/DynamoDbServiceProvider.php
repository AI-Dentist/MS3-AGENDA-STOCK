<?php

namespace App\Providers;

use Aws\DynamoDb\DynamoDbClient;
use Illuminate\Support\ServiceProvider;

class DynamoDbServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(DynamoDbClient::class, function () {
            $config = [
                'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
                'version' => 'latest',
                'credentials' => [
                    'key' => env('AWS_ACCESS_KEY_ID', 'local'),
                    'secret' => env('AWS_SECRET_ACCESS_KEY', 'local'),
                ],
                'http' => [
                    'connect_timeout' => (float) env('DYNAMODB_CONNECT_TIMEOUT', 0.5),
                    'timeout' => (float) env('DYNAMODB_TIMEOUT', 2.0),
                ],
                'retries' => (int) env('DYNAMODB_RETRIES', 1),
            ];

            if ($endpoint = env('DYNAMODB_ENDPOINT')) {
                $config['endpoint'] = $endpoint;
            }

            return new DynamoDbClient($config);
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
