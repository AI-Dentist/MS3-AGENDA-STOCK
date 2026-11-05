<?php

namespace Tests\Support;

use App\Infrastructure\Persistence\DynamoDB\DynamoDbConnectionManager;
use Aws\DynamoDb\DynamoDbClient;
use Firebase\JWT\JWT;
use Illuminate\Contracts\Console\Kernel;

trait StockMovementTestEnvironment
{
    private string $scratch;
    private array $previousEnvironment = [];

    private function phase(string $phase): void
    {
        if (getenv('STOCK_MOVEMENT_DIAGNOSTIC') === '1') {
            fwrite(STDERR, gmdate('c').' '.$phase.PHP_EOL);
            fflush(STDERR);
        }
    }

    private function syntheticEnvironment(string $key, string $value): void
    {
        $this->previousEnvironment[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null,
            array_key_exists($key, $_ENV), array_key_exists($key, $_SERVER)];
        putenv($key.'='.$value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }

    public function createApplication()
    {
        $this->phase('createApplication: start');
        $this->scratch = sys_get_temp_dir().'/ms3-stock-'.bin2hex(random_bytes(8));
        mkdir($this->scratch);
        $this->phase('scratch: '.$this->scratch);
        foreach ([
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false',
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('s', 32)),
            'LOG_CHANNEL' => 'null', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'KEYCLOAK_BASE_URL' => 'http://localhost:8180', 'KEYCLOAK_REALM' => 'dental-platform',
            'KEYCLOAK_AUDIENCE' => 'ms3-agenda-stock', 'KEYCLOAK_ALGORITHM' => 'HS256',
            'KEYCLOAK_PUBLIC_KEY' => '', 'KEYCLOAK_DEV_SECRET' => 'ms3-agenda-stock-local-jwt-secret-key-2026',
            'ENFORCE_KEYCLOAK_IN_TESTS' => 'true', 'DYNAMODB_ENDPOINT' => 'http://127.0.0.1:9',
            'AWS_ACCESS_KEY_ID' => 'synthetic', 'AWS_SECRET_ACCESS_KEY' => 'synthetic',
            'AWS_DEFAULT_REGION' => 'us-east-1', 'AWS_EC2_METADATA_DISABLED' => 'true',
        ] as $key => $value) {
            $this->syntheticEnvironment($key, $value);
        }
        foreach (['APP_CONFIG_CACHE', 'APP_SERVICES_CACHE', 'APP_PACKAGES_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE'] as $key) {
            $this->syntheticEnvironment($key, $this->scratch.'/'.$key.'.php');
        }
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        // Preserve the Windows bootstrap fix: Laravel defaults to slash-prefixed paths.
        if (preg_match('/^[A-Za-z]:/', $this->scratch, $drive) === 1) {
            $app->addAbsoluteCachePathPrefix($drive[0]);
        }
        $app->useEnvironmentPath($this->scratch)->loadEnvironmentFrom('synthetic-empty');
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        $this->phase('kernel bootstrap: start');
        $app->make(Kernel::class)->bootstrap();
        $this->phase('kernel bootstrap: complete');
        $app['config']->set('logging.default', 'null');

        return $app;
    }

    private function useStockMovementDatabase(): StockMovementDatabase
    {
        $database = new StockMovementDatabase;
        $this->app->instance(DynamoDbClient::class, new DynamoDbClient([
            'version' => 'latest', 'region' => 'us-east-1', 'endpoint' => 'http://127.0.0.1:9',
            'credentials' => ['key' => 'synthetic', 'secret' => 'synthetic'],
            'retries' => 0, 'handler' => $database,
        ]));
        DynamoDbConnectionManager::setAvailable(true);

        return $database;
    }

    protected function actor(string $actor): void
    {
        $this->withToken(JWT::encode([
            'sub' => $actor, 'iss' => 'http://localhost:8180/realms/dental-platform',
            'aud' => 'ms3-agenda-stock', 'exp' => time() + 600, 'realm_access' => ['roles' => ['ADMIN']],
        ], 'ms3-agenda-stock-local-jwt-secret-key-2026', 'HS256'));
    }

    protected function tearDown(): void
    {
        DynamoDbConnectionManager::reset();
        try {
            parent::tearDown();
        } finally {
            if (isset($this->scratch)) {
                foreach (glob($this->scratch.'/*') as $file) {
                    unlink($file);
                }
                rmdir($this->scratch);
            }
            foreach ($this->previousEnvironment as $key => [$process, $env, $server, $hasEnv, $hasServer]) {
                putenv($process === false ? $key : $key.'='.$process);
                if (! $hasEnv) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $env;
                }
                if (! $hasServer) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $server;
                }
            }
        }
        $this->phase('tearDown: scratch removed and environment restored');
    }
}
