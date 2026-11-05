<?php

namespace Tests\Feature;

use Firebase\JWT\JWT;
use Tests\TestCase;

class KeycloakAuthLeewayTest extends TestCase
{
    private string $devSecret = 'ms3-agenda-stock-local-jwt-secret-key-2026';

    protected function setUp(): void
    {
        parent::setUp();
        // Reset static leeway to default 0 before each test to avoid test pollution
        JWT::$leeway = 0;
    }

    protected function tearDown(): void
    {
        JWT::$leeway = 0;
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
     * Caso A1: Token emitido con iat = now + 1s (Keycloak en Docker adelantado 1s).
     * Debe ser aceptado dentro de la tolerancia de 60s.
     */
    public function test_acepta_token_con_iat_adelantado_un_segundo_por_clock_skew(): void
    {
        $token = $this->createToken([
            'iat' => time() + 1,
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/agendamiento/stock');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
    }

    /**
     * Caso A2: Token con nbf = now + 30s.
     * Debe ser aceptado dentro de la tolerancia de 60s.
     */
    public function test_acepta_token_con_nbf_adelantado_treinta_segundos_dentro_del_leeway(): void
    {
        $token = $this->createToken([
            'nbf' => time() + 30,
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/agendamiento/citas');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
    }

    /**
     * Caso B1: Token con iat = now + 61s (excede el leeway de 60s).
     * Debe ser rechazado con HTTP 401 INVALID_TOKEN.
     */
    public function test_rechaza_token_con_iat_adelantado_mas_de_sesenta_segundos(): void
    {
        $token = $this->createToken([
            'iat' => time() + 61,
        ]);

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
     * Caso B2: Token con nbf = now + 61s (excede el leeway de 60s).
     * Debe ser rechazado con HTTP 401 INVALID_TOKEN.
     */
    public function test_rechaza_token_con_nbf_adelantado_mas_de_sesenta_segundos(): void
    {
        $token = $this->createToken([
            'nbf' => time() + 61,
        ]);

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
     * Caso C1: Token con exp vencido recientemente (exp = now - 5s).
     * Debe ser rechazado estrictamente con HTTP 401 sin tolerancia.
     */
    public function test_rechaza_token_con_exp_vencido_reciente_sin_tolerancia(): void
    {
        $token = $this->createToken([
            'exp' => time() - 5,
        ]);

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
     * Caso C2: Token con exp vencido ampliamente (exp = now - 120s).
     * Debe ser rechazado con HTTP 401 INVALID_TOKEN.
     */
    public function test_rechaza_token_con_exp_vencido_ampliamente(): void
    {
        $token = $this->createToken([
            'exp' => time() - 120,
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/agendamiento/citas');

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'code' => 'INVALID_TOKEN',
            ]);
    }
}
