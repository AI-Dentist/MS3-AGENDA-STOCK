<?php

namespace Tests\Feature;

use Firebase\JWT\JWT;
use Tests\TestCase;

class KeycloakAuthLeewayBoundaryTest extends TestCase
{
    private string $devSecret = 'ms3-agenda-stock-local-jwt-secret-key-2026';

    protected function setUp(): void
    {
        parent::setUp();
        // Reset static leeway to 0 and ensure clean env before each test
        JWT::$leeway = 0;
        putenv('KEYCLOAK_JWT_LEEWAY');
        unset($_ENV['KEYCLOAK_JWT_LEEWAY'], $_SERVER['KEYCLOAK_JWT_LEEWAY']);
    }

    protected function tearDown(): void
    {
        JWT::$leeway = 0;
        putenv('KEYCLOAK_JWT_LEEWAY');
        unset($_ENV['KEYCLOAK_JWT_LEEWAY'], $_SERVER['KEYCLOAK_JWT_LEEWAY']);
        parent::tearDown();
    }

    private function createToken(array $overrides = []): string
    {
        $payload = array_merge([
            'iss' => 'http://localhost:8180/realms/dental-platform',
            'aud' => 'ms3-agenda-stock',
            'sub' => 'usr-recep-01',
            'preferred_username' => 'recepcionista.ana',
            'email' => 'ana@dental.local',
            'realm_access' => [
                'roles' => ['RECEPCIONISTA'],
            ],
            'exp' => time() + 3600,
        ], $overrides);

        return JWT::encode($payload, $this->devSecret, 'HS256');
    }

    /**
     * Ajuste 1: JWT::$leeway es una propiedad estática global de firebase/php-jwt.
     * El middleware debe restaurar el valor previo en un bloque finally tras una request exitosa.
     */
    public function test_restaura_valor_previo_de_jwt_leeway_tras_request_exitosa(): void
    {
        JWT::$leeway = 0;
        $token = $this->createToken(['iat' => time() + 1]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/agendamiento/stock');

        $response->assertStatus(200);
        $this->assertSame(0, JWT::$leeway, 'JWT::$leeway global debe restaurarse a 0 tras la request');
    }

    /**
     * Ajuste 1 (variante con valor previo no nulo): Si el proceso tenía otro valor de leeway (ej: 15),
     * debe volver a 15 y no quedar en 60.
     */
    public function test_restaura_valor_personalizado_previo_de_jwt_leeway(): void
    {
        JWT::$leeway = 15;
        $token = $this->createToken(['iat' => time() + 1]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/agendamiento/stock');

        $response->assertStatus(200);
        $this->assertSame(15, JWT::$leeway, 'JWT::$leeway debe restaurarse al valor original (15)');
    }

    /**
     * Ajuste 1 (caso de fallo/excepción): El middleware debe restaurar JWT::$leeway incluso si
     * la decodificación o validación falla.
     */
    public function test_restaura_jwt_leeway_en_bloque_finally_incluso_ante_error(): void
    {
        JWT::$leeway = 0;
        $token = $this->createToken(['iat' => time() + 120]); // Fallará con 401

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/agendamiento/stock');

        $response->assertStatus(401);
        $this->assertSame(0, JWT::$leeway, 'JWT::$leeway debe restaurarse a 0 incluso tras fallo');
    }

    /**
     * Ajuste 2: Cotas de KEYCLOAK_JWT_LEEWAY acotado a 0..300.
     * Caso: KEYCLOAK_JWT_LEEWAY = -5 debe acotarse a 0.
     * Un token emitido en tiempo actual ('iat' = now) debe ser aceptado, pero no debe tolerar drift futuro.
     */
    public function test_configuracion_leeway_negativa_acota_a_cero(): void
    {
        putenv('KEYCLOAK_JWT_LEEWAY=-5');
        $_ENV['KEYCLOAK_JWT_LEEWAY'] = '-5';

        // Token actual emitido ahora mismo debe funcionar con leeway = 0
        $tokenActual = $this->createToken(['iat' => time()]);
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$tokenActual}",
        ])->getJson('/api/agendamiento/stock');
        $response->assertStatus(200);

        // Token con iat adelantado 1s debe ser rechazado porque leeway es 0 (no acepta drift)
        $tokenFuturo = $this->createToken(['iat' => time() + 1]);
        $responseFuturo = $this->withHeaders([
            'Authorization' => "Bearer {$tokenFuturo}",
        ])->getJson('/api/agendamiento/stock');
        $responseFuturo->assertStatus(401)
            ->assertJson(['code' => 'INVALID_TOKEN']);
    }

    /**
     * Ajuste 2: Caso KEYCLOAK_JWT_LEEWAY = 999999999 debe acotarse a 300.
     * No debe permitir tokens con iat muy futuro (> 300s).
     */
    public function test_configuracion_leeway_excesiva_acota_a_trescientos_segundos(): void
    {
        putenv('KEYCLOAK_JWT_LEEWAY=999999999');
        $_ENV['KEYCLOAK_JWT_LEEWAY'] = '999999999';

        // Token dentro de la cota máxima de 300s (iat = now + 250) debe ser aceptado
        $tokenValido = $this->createToken(['iat' => time() + 250]);
        $responseValido = $this->withHeaders([
            'Authorization' => "Bearer {$tokenValido}",
        ])->getJson('/api/agendamiento/stock');
        $responseValido->assertStatus(200);

        // Token excediendo la cota máxima de 300s (iat = now + 350) debe ser rechazado
        $tokenInvalido = $this->createToken(['iat' => time() + 350]);
        $responseInvalido = $this->withHeaders([
            'Authorization' => "Bearer {$tokenInvalido}",
        ])->getJson('/api/agendamiento/stock');
        $responseInvalido->assertStatus(401)
            ->assertJson(['code' => 'INVALID_TOKEN']);
    }

    /**
     * Ajuste 2: Caso KEYCLOAK_JWT_LEEWAY no numérico ('abc') -> debe usar default 60.
     */
    public function test_configuracion_leeway_no_numerica_usa_default_sesenta(): void
    {
        putenv('KEYCLOAK_JWT_LEEWAY=abc');
        $_ENV['KEYCLOAK_JWT_LEEWAY'] = 'abc';

        // Token con iat = now + 1 debe ser aceptado con default 60
        $tokenValido = $this->createToken(['iat' => time() + 1]);
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$tokenValido}",
        ])->getJson('/api/agendamiento/stock');
        $response->assertStatus(200);

        // Token con iat = now + 65 debe ser rechazado
        $tokenInvalido = $this->createToken(['iat' => time() + 65]);
        $responseInvalido = $this->withHeaders([
            'Authorization' => "Bearer {$tokenInvalido}",
        ])->getJson('/api/agendamiento/stock');
        $responseInvalido->assertStatus(401)
            ->assertJson(['code' => 'INVALID_TOKEN']);
    }

    /**
     * Ajuste 2: Caso KEYCLOAK_JWT_LEEWAY = 60 explícito -> debe usar 60.
     */
    public function test_configuracion_leeway_sesenta_explicita_funciona_correctamente(): void
    {
        putenv('KEYCLOAK_JWT_LEEWAY=60');
        $_ENV['KEYCLOAK_JWT_LEEWAY'] = '60';

        $tokenValido = $this->createToken(['iat' => time() + 1]);
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$tokenValido}",
        ])->getJson('/api/agendamiento/stock');
        $response->assertStatus(200);

        $tokenInvalido = $this->createToken(['iat' => time() + 65]);
        $responseInvalido = $this->withHeaders([
            'Authorization' => "Bearer {$tokenInvalido}",
        ])->getJson('/api/agendamiento/stock');
        $responseInvalido->assertStatus(401)
            ->assertJson(['code' => 'INVALID_TOKEN']);
    }

    /**
     * Caso de borde del verificador: iat + 60s aceptado exactamente en el límite de leeway.
     */
    public function test_acepta_token_con_iat_exactamente_en_limite_de_sesenta_segundos(): void
    {
        $token = $this->createToken(['iat' => time() + 60]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/agendamiento/stock');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * Caso de borde del verificador: nbf + 60s aceptado exactamente en el límite de leeway.
     */
    public function test_acepta_token_con_nbf_exactamente_en_limite_de_sesenta_segundos(): void
    {
        $token = $this->createToken(['nbf' => time() + 60]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/agendamiento/citas');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * Caso de borde del verificador: iat + 120s rechazado con 401 INVALID_TOKEN.
     */
    public function test_rechaza_token_con_iat_adelantado_ciento_veinte_segundos(): void
    {
        $token = $this->createToken(['iat' => time() + 120]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/agendamiento/stock');

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'code' => 'INVALID_TOKEN',
            ]);
    }

    /**
     * Caso de borde del verificador: nbf + 120s rechazado con 401 INVALID_TOKEN.
     */
    public function test_rechaza_token_con_nbf_adelantado_ciento_veinte_segundos(): void
    {
        $token = $this->createToken(['nbf' => time() + 120]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/agendamiento/citas');

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'code' => 'INVALID_TOKEN',
            ]);
    }

    /**
     * Caso de borde del verificador: exp == now rechazado sin tolerancia.
     */
    public function test_rechaza_token_con_exp_igual_al_tiempo_actual(): void
    {
        $token = $this->createToken(['exp' => time()]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/agendamiento/stock');

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'code' => 'INVALID_TOKEN',
            ]);
    }

    /**
     * Caso de borde del verificador: exp ausente aceptado igual que antes.
     */
    public function test_acepta_token_sin_claim_exp_preservando_comportamiento_previo(): void
    {
        $payload = [
            'iss' => 'http://localhost:8180/realms/dental-platform',
            'aud' => 'ms3-agenda-stock',
            'sub' => 'usr-recep-01',
            'preferred_username' => 'recepcionista.ana',
            'realm_access' => [
                'roles' => ['RECEPCIONISTA'],
            ],
            // 'exp' omitido intencionalmente
        ];
        $token = JWT::encode($payload, $this->devSecret, 'HS256');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/agendamiento/stock');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }
}
