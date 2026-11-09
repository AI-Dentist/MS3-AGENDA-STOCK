<?php

namespace Tests\Feature;

use Firebase\JWT\JWT;
use Illuminate\Support\Env;
use Tests\TestCase;

class KeycloakProductionAuthTest extends TestCase
{
    private string $devSecret = 'ms3-agenda-stock-local-jwt-secret-key-2026';

    protected function tearDown(): void
    {
        Env::getRepository()->clear('KEYCLOAK_ISSUER_URI');
        Env::getRepository()->clear('KEYCLOAK_PUBLIC_KEY');
        Env::getRepository()->clear('ENFORCE_KEYCLOAK_IN_TESTS');
        parent::tearDown();
    }

    public function test_health_check_boots_and_returns_200_without_keycloak(): void
    {
        $response = $this->getJson('/health');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'status' => 'UP',
                'microservicio' => 'MS3 - Agenda & Stock & Machine Learning',
            ]);
    }

    public function test_protected_routes_return_401_when_enforced_without_bearer_token(): void
    {
        Env::getRepository()->set('ENFORCE_KEYCLOAK_IN_TESTS', 'true');

        $response = $this->getJson('/api/agendamiento/citas');

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'code' => 'UNAUTHORIZED',
            ]);
    }

    public function test_accepts_token_matching_keycloak_issuer_uri(): void
    {
        $customIssuer = 'https://custom-keycloak.domain.com/realms/dental-platform';
        Env::getRepository()->set('KEYCLOAK_ISSUER_URI', $customIssuer);

        $payload = [
            'iss' => $customIssuer,
            'aud' => 'ms3-agenda-stock',
            'sub' => 'usr-12345',
            'realm_access' => [
                'roles' => ['ODONTOLOGO'],
            ],
            'exp' => time() + 3600,
        ];

        $token = JWT::encode($payload, $this->devSecret, 'HS256');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/agendamiento/citas');

        $response->assertStatus(200);
    }

    public function test_rejects_token_when_issuer_does_not_match_keycloak_issuer_uri(): void
    {
        $customIssuer = 'https://custom-keycloak.domain.com/realms/dental-platform';
        Env::getRepository()->set('KEYCLOAK_ISSUER_URI', $customIssuer);

        $payload = [
            'iss' => 'https://mismatched-issuer.domain.com/realms/dental-platform',
            'aud' => 'ms3-agenda-stock',
            'sub' => 'usr-12345',
            'realm_access' => [
                'roles' => ['ODONTOLOGO'],
            ],
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
}
