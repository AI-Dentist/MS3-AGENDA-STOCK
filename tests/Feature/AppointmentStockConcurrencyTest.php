<?php

namespace Tests\Feature;

use App\Domain\Entities\Cita;
use App\Domain\Entities\Insumo;
use App\Infrastructure\Persistence\DynamoDB\DynamoDbConnectionManager;
use Aws\DynamoDb\DynamoDbClient;
use Firebase\JWT\JWT;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\AppointmentStockDatabase;
use Tests\TestCase;

class AppointmentStockConcurrencyTest extends TestCase
{
    private string $scratch;
    private array $previousEnvironment = [];
    private AppointmentStockDatabase $database;

    private function syntheticEnvironment(string $key, string $value): void
    {
        $this->previousEnvironment[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null,
            array_key_exists($key, $_ENV), array_key_exists($key, $_SERVER)];
        putenv($key.'='.$value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }

    public function createApplication()
    {
        $this->scratch = sys_get_temp_dir().'/ms3-appt-'.bin2hex(random_bytes(8));
        mkdir($this->scratch);
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
        if (preg_match('/^[A-Za-z]:/', $this->scratch, $drive) === 1) {
            $app->addAbsoluteCachePathPrefix($drive[0]);
        }
        $app->useEnvironmentPath($this->scratch)->loadEnvironmentFrom('synthetic-empty');
        $app->make(Kernel::class)->bootstrap();
        $app['config']->set('logging.default', 'null');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = new AppointmentStockDatabase;
        $this->app->instance(DynamoDbClient::class, new DynamoDbClient([
            'version' => 'latest', 'region' => 'us-east-1', 'endpoint' => 'http://127.0.0.1:9',
            'credentials' => ['key' => 'synthetic', 'secret' => 'synthetic'],
            'retries' => 0, 'handler' => $this->database,
        ]));
        DynamoDbConnectionManager::setAvailable(true);

        // Sembrar cita inicial e insumo inicial en la base sintética
        $this->database->tables['citas']['cita-test-1'] = [
            'id' => 'cita-test-1',
            'odontologo_id' => 'odo-1',
            'consultorio_id' => 'con-1',
            'paciente_id' => 'pac-1',
            'fecha_hora' => '2026-11-20 10:00:00',
            'estado' => Cita::ESTADO_RESERVADA,
        ];

        $this->database->tables['insumos']['ins-test-1'] = [
            'id' => 'ins-test-1',
            'codigo' => 'INS-01',
            'nombre' => 'Guantes Nitrilo',
            'stock_actual' => 20,
            'stock_minimo' => 5,
            'unidad_medida' => 'Cajas',
        ];

        // Autenticar como ODONTOLOGO
        $this->withToken(JWT::encode([
            'sub' => 'dr-smith', 'iss' => 'http://localhost:8180/realms/dental-platform',
            'aud' => 'ms3-agenda-stock', 'exp' => time() + 600, 'realm_access' => ['roles' => ['ODONTOLOGO']],
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
    }

    public function test_completes_appointment_and_decrements_stock_atomically(): void
    {
        $response = $this->patchJson('/api/agendamiento/citas/cita-test-1/completar', [
            'insumos' => [
                ['insumo_id' => 'ins-test-1', 'cantidad' => 3],
            ],
            'motivo' => 'Atención clínica normal',
        ])->assertOk();

        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.estado', Cita::ESTADO_COMPLETADA);

        // Verificar estado persistente en la base de datos
        $this->assertEquals(Cita::ESTADO_COMPLETADA, $this->database->tables['citas']['cita-test-1']['estado']);
        $this->assertEquals(17, $this->database->tables['insumos']['ins-test-1']['stock_actual']);

        // Verificar registro de kardex generado
        $this->assertCount(1, $this->database->tables['movimientos_stock']);
        $mov = array_values($this->database->tables['movimientos_stock'])[0];
        $this->assertEquals(20, $mov['stock_anterior']);
        $this->assertEquals(17, $mov['stock_posterior']);
        $this->assertEquals(3, $mov['cantidad']);
    }

    public function test_concurrent_movement_between_transaction_and_saves_does_not_lose_delta(): void
    {
        // Simulamos una mutación concurrente que ocurre justo después de la transacción
        // Si el use case ejecuta un repository->save() posterior incondicional, sobreescribirá
        // este stock y perderá el delta concurrente de +5.
        $this->database->afterTransaction = function (): void {
            // Un movimiento concurrente entra y suma 5 unidades
            $this->database->tables['insumos']['ins-test-1']['stock_actual'] += 5;
        };

        $response = $this->patchJson('/api/agendamiento/citas/cita-test-1/completar', [
            'insumos' => [
                ['insumo_id' => 'ins-test-1', 'cantidad' => 3],
            ],
        ])->assertOk();

        // 20 inicial - 3 (cita) + 5 (concurrente) = 22
        // Si hay sobreescritura post-transacción, volvería erróneamente a 17!
        $this->assertEquals(22, $this->database->tables['insumos']['ins-test-1']['stock_actual']);
        // Ningún PutItem incondicional debió haber sido emitido contra insumos
        foreach ($this->database->putItems as $put) {
            $this->assertNotEquals('insumos', $put['table'], 'No se deben emitir PutItems incondicionales a insumos tras la transacción.');
        }
    }

    public function test_concurrent_stock_change_before_transaction_fails_cas_and_does_not_corrupt_ledger(): void
    {
        // Simulamos que el stock cambia entre la lectura inicial del use case y la transacción
        $this->database->beforeTransaction = function (): void {
            // El stock cambia de 20 a 15 concurrentemente
            $this->database->tables['insumos']['ins-test-1']['stock_actual'] = 15;
        };

        // Debe fallar con error 400 por CAS mismatch y NO registrar nada en kardex ni completar la cita
        $response = $this->patchJson('/api/agendamiento/citas/cita-test-1/completar', [
            'insumos' => [
                ['insumo_id' => 'ins-test-1', 'cantidad' => 3],
            ],
        ])->assertStatus(400);

        // La cita NO debe quedar completada
        $this->assertEquals(Cita::ESTADO_RESERVADA, $this->database->tables['citas']['cita-test-1']['estado']);
        // El stock no debe haberse modificado por la cita
        $this->assertEquals(15, $this->database->tables['insumos']['ins-test-1']['stock_actual']);
        // No debe haber registros en kardex
        $this->assertCount(0, $this->database->tables['movimientos_stock']);
    }

    public function test_failed_transaction_does_not_fallback_to_memory_and_leaves_zero_side_effects(): void
    {
        $this->database->failure = 'unavailable';

        $response = $this->patchJson('/api/agendamiento/citas/cita-test-1/completar', [
            'insumos' => [
                ['insumo_id' => 'ins-test-1', 'cantidad' => 3],
            ],
        ])->assertStatus(500);

        // La cita NO debe haber sido completada en fallback a memoria
        $this->assertEquals(Cita::ESTADO_RESERVADA, $this->database->tables['citas']['cita-test-1']['estado']);
        $this->assertEquals(20, $this->database->tables['insumos']['ins-test-1']['stock_actual']);
        $this->assertCount(0, $this->database->tables['movimientos_stock']);
    }

    public function test_dynamodb_unreachable_fails_closed_without_in_memory_writes_unless_explicitly_configured(): void
    {
        // Simular caída de DynamoDB sin fallback en memoria (comportamiento por defecto fuera de testing)
        DynamoDbConnectionManager::setAvailable(false);
        putenv('DYNAMODB_FALLBACK_MEMORY=false');
        $_ENV['DYNAMODB_FALLBACK_MEMORY'] = 'false';

        try {
            $response = $this->patchJson('/api/agendamiento/citas/cita-test-1/completar', [
                'insumos' => [
                    ['insumo_id' => 'ins-test-1', 'cantidad' => 3],
                ],
            ])->assertStatus(500);

            $response->assertJsonPath('code', 'INTERNAL_TRANSACTION_ERROR');

            // Habilitar explícitamente modo en memoria
            putenv('DYNAMODB_FALLBACK_MEMORY=true');
            $_ENV['DYNAMODB_FALLBACK_MEMORY'] = 'true';
            $citaRepo = $this->app->make(\App\Domain\Repositories\CitaRepositoryInterface::class);
            $insumoRepo = $this->app->make(\App\Domain\Repositories\InsumoRepositoryInterface::class);
            $citaRepo->save(new Cita('cita-test-1', 'idem-test-1', 'odo-1', 'con-1', 'pac-1', '2026-11-20 10:00:00', Cita::ESTADO_RESERVADA));
            $insumoRepo->save(new Insumo('ins-test-1', 'INS-01', 'Guantes Nitrilo', 20, 5, 'Cajas'));

            $this->patchJson('/api/agendamiento/citas/cita-test-1/completar', [
                'insumos' => [
                    ['insumo_id' => 'ins-test-1', 'cantidad' => 3],
                ],
            ])->assertOk();
        } finally {
            putenv('DYNAMODB_FALLBACK_MEMORY');
            unset($_ENV['DYNAMODB_FALLBACK_MEMORY']);
            DynamoDbConnectionManager::setAvailable(true);
        }
    }
}
