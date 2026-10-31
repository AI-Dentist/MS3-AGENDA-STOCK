<?php

namespace Tests\Feature;

use Firebase\JWT\JWT;
use Tests\TestCase;

class KeycloakAuthMiddlewareTest extends TestCase
{
    private string $devSecret = 'ms3-agenda-stock-local-jwt-secret-key-2026';

    public function test_rechaza_suplantacion_de_cabecera_x_user_roles_sin_token(): void
    {
        $response = $this->withHeaders([
            'X-User-Roles' => 'ADMIN,ODONTOLOGO',
        ])->getJson('/api/agendamiento/stock');

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'code' => 'UNAUTHORIZED_SPOOF_ATTEMPT',
            ]);
    }

    public function test_rechaza_token_jwt_malformado_o_invalido(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer token-invalido-o-corrupto',
        ])->getJson('/api/agendamiento/stock');

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'code' => 'INVALID_TOKEN',
            ]);
    }

    public function test_permite_acceso_con_token_firmado_y_roles_validos(): void
    {
        $payload = [
            'iss' => 'http://localhost:8180/realms/dental-platform',
            'aud' => 'ms3-agenda-stock',
            'sub' => 'usr-12345',
            'preferred_username' => 'dr.garcia',
            'email' => 'garcia@dental.com',
            'realm_access' => [
                'roles' => ['ODONTOLOGO'],
            ],
            'exp' => time() + 3600,
        ];

        $token = JWT::encode($payload, $this->devSecret, 'HS256');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/agendamiento/citas');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
    }

    public function test_permite_acceso_con_alias_de_roles_dentista_y_prefijo_role(): void
    {
        $payload = [
            'iss' => 'http://localhost:8180/realms/dental-platform',
            'aud' => 'ms3-agenda-stock',
            'sub' => 'usr-dentist-789',
            'preferred_username' => 'dr.perez',
            'email' => 'perez@dental.com',
            'realm_access' => [
                'roles' => ['ROLE_DENTISTA'], // Debe normalizarse a ODONTOLOGO
            ],
            'exp' => time() + 3600,
        ];

        $token = JWT::encode($payload, $this->devSecret, 'HS256');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/agendamiento/citas');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
    }

    public function test_deniega_acceso_con_codigo_403_cuando_faltan_roles_requeridos(): void
    {
        // El usuario solo tiene rol PACIENTE, pero /citas requiere ADMIN, RECEPCIONISTA u ODONTOLOGO
        $payload = [
            'iss' => 'http://localhost:8180/realms/dental-platform',
            'aud' => 'ms3-agenda-stock',
            'sub' => 'usr-pac-99',
            'preferred_username' => 'paciente.juan',
            'realm_access' => [
                'roles' => ['PACIENTE'],
            ],
            'exp' => time() + 3600,
        ];

        $token = JWT::encode($payload, $this->devSecret, 'HS256');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/agendamiento/citas');

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'code' => 'FORBIDDEN_ROLE',
            ]);
    }

    public function test_rechaza_token_jwt_con_firma_falsificada(): void
    {
        $payload = [
            'sub' => 'usr-attacker',
            'preferred_username' => 'hacker',
            'realm_access' => [
                'roles' => ['ADMIN'],
            ],
            'exp' => time() + 3600,
        ];

        // Firmar con clave espuria / no autorizada (mínimo 256 bits / 32 bytes para HS256)
        $forgedToken = JWT::encode($payload, 'wrong-attacker-secret-1234567890-test-key-32bytes', 'HS256');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$forgedToken}",
        ])->getJson('/api/agendamiento/citas');

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'code' => 'INVALID_TOKEN',
            ]);
    }

    public function test_rechaza_token_sin_claim_iss(): void
    {
        $payload = [
            'sub' => 'usr-12345',
            'aud' => 'ms3-agenda-stock',
            'realm_access' => ['roles' => ['ODONTOLOGO']],
            'exp' => time() + 3600,
        ];
        $token = JWT::encode($payload, $this->devSecret, 'HS256');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/agendamiento/citas');

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'code' => 'INVALID_TOKEN',
            ]);
    }

    public function test_rechaza_token_con_iss_mismatch(): void
    {
        $payload = [
            'iss' => 'http://malicious-idp.com/realms/dental-platform',
            'sub' => 'usr-12345',
            'aud' => 'ms3-agenda-stock',
            'realm_access' => ['roles' => ['ODONTOLOGO']],
            'exp' => time() + 3600,
        ];
        $token = JWT::encode($payload, $this->devSecret, 'HS256');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/agendamiento/citas');

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'code' => 'INVALID_TOKEN',
            ]);
    }

    public function test_rechaza_token_sin_claim_aud(): void
    {
        $payload = [
            'iss' => 'http://localhost:8180/realms/dental-platform',
            'sub' => 'usr-12345',
            'realm_access' => ['roles' => ['ODONTOLOGO']],
            'exp' => time() + 3600,
        ];
        $token = JWT::encode($payload, $this->devSecret, 'HS256');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/agendamiento/citas');

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'code' => 'INVALID_TOKEN',
            ]);
    }

    public function test_rechaza_token_con_aud_mismatch_string(): void
    {
        // El recurso esperado es ms3-agenda-stock, no el nombre del realm ni otro servicio
        $payload = [
            'iss' => 'http://localhost:8180/realms/dental-platform',
            'aud' => 'dental-platform',
            'sub' => 'usr-12345',
            'realm_access' => ['roles' => ['ODONTOLOGO']],
            'exp' => time() + 3600,
        ];
        $token = JWT::encode($payload, $this->devSecret, 'HS256');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/agendamiento/citas');

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'code' => 'INVALID_TOKEN',
            ]);
    }

    public function test_rechaza_token_con_aud_mismatch_array(): void
    {
        // Token con lista de audiencias que no incluye ms3-agenda-stock
        $payload = [
            'iss' => 'http://localhost:8180/realms/dental-platform',
            'aud' => ['ms-erp', 'account'],
            'sub' => 'usr-12345',
            'realm_access' => ['roles' => ['ODONTOLOGO']],
            'exp' => time() + 3600,
        ];
        $token = JWT::encode($payload, $this->devSecret, 'HS256');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/agendamiento/citas');

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'code' => 'INVALID_TOKEN',
            ]);
    }

    public function test_permite_acceso_con_iss_valido_y_aud_string(): void
    {
        $payload = [
            'iss' => 'http://localhost:8180/realms/dental-platform',
            'aud' => 'ms3-agenda-stock',
            'sub' => 'usr-12345',
            'preferred_username' => 'dr.garcia',
            'realm_access' => ['roles' => ['ODONTOLOGO']],
            'exp' => time() + 3600,
        ];
        $token = JWT::encode($payload, $this->devSecret, 'HS256');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/agendamiento/citas');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
    }

    public function test_permite_acceso_con_iss_valido_y_aud_array(): void
    {
        $payload = [
            'iss' => 'http://localhost:8180/realms/dental-platform',
            'aud' => ['ms3-agenda-stock', 'account'],
            'sub' => 'usr-12345',
            'preferred_username' => 'dr.garcia',
            'realm_access' => ['roles' => ['ODONTOLOGO']],
            'exp' => time() + 3600,
        ];
        $token = JWT::encode($payload, $this->devSecret, 'HS256');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/agendamiento/citas');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
    }

    public function test_peticion_rechazada_por_iss_o_aud_invalido_no_modifica_datos_en_endpoint_mutacion(): void
    {
        // Enviar POST a creación de cita con token que tiene aud inválido
        $payload = [
            'iss' => 'http://localhost:8180/realms/dental-platform',
            'aud' => 'ms2-clinico-ia', // Mismatch
            'sub' => 'usr-12345',
            'realm_access' => ['roles' => ['ODONTOLOGO', 'ADMIN']],
            'exp' => time() + 3600,
        ];
        $token = JWT::encode($payload, $this->devSecret, 'HS256');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Idempotency-Key' => 'test-idem-unauthorized-mutation',
        ])->postJson('/api/agendamiento/citas', [
            'odontologo_id' => 'odo-test',
            'consultorio_id' => 'con-01',
            'paciente_id' => 'pac-test',
            'fecha_hora' => '2026-12-01 10:00:00',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'code' => 'INVALID_TOKEN',
            ]);
    }
}
