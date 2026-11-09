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
                'region' => env('AWS_DEFAULT_REGION', env('AWS_REGION', 'us-east-1')),
                'version' => 'latest',
                'http' => [
                    'connect_timeout' => (float) env('DYNAMODB_CONNECT_TIMEOUT', 0.5),
                    'timeout' => (float) env('DYNAMODB_TIMEOUT', 2.0),
                ],
                'retries' => (int) env('DYNAMODB_RETRIES', 1),
            ];

            // Solo inyectar credenciales estáticas si están explícitamente definidas y no están vacías (desarrollo local / CI).
            // En AWS producción (IAM Role por IMDSv2), se omite 'credentials' para que el SDK active la cadena por defecto.
            $key = env('AWS_ACCESS_KEY_ID');
            $secret = env('AWS_SECRET_ACCESS_KEY');
            if (! empty($key) && ! empty($secret)) {
                $config['credentials'] = [
                    'key' => $key,
                    'secret' => $secret,
                ];
            }

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
