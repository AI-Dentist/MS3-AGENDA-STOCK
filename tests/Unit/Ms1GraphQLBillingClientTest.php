<?php

namespace Tests\Unit;

use App\Application\DTOs\PatientSpendingResult;
use App\Infrastructure\Services\Ms1GraphQLBillingClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Ms1GraphQLBillingClientTest extends TestCase
{
    private Ms1GraphQLBillingClient $client;
    private string $validUuid = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new Ms1GraphQLBillingClient();
        config(['services.ms1.graphql_url' => 'http://127.0.0.1:8081/graphql']);
        config(['services.ms1.timeout_ms' => 2000]);
    }

    public function test_real_totals_when_completo_true_with_positive_amount(): void
    {
        Http::fake([
            'http://127.0.0.1:8081/graphql' => Http::response([
                'data' => [
                    'obtenerGastoPagadoPaciente' => [
                        'pacienteId' => $this->validUuid,
                        'moneda' => 'BOB',
                        'completo' => true,
                        'totalPagadoBs' => 240.00,
                    ],
                ],
            ], 200),
        ]);

        $result = $this->client->getPatientSpending($this->validUuid, 180.0, 'valid-bearer-token');

        $this->assertInstanceOf(PatientSpendingResult::class, $result);
        $this->assertFalse($result->esEstimado);
        $this->assertTrue($result->gastoRealObservado);
        $this->assertSame('real_ms1_pagado', $result->gastoOrigen);
        $this->assertSame('BOB', $result->gastoMoneda);
        $this->assertEquals(240.0, $result->gastoTotalReal);
        $this->assertEquals(180.0, $result->gastoTotalEstimado);
    }

    public function test_observed_zero_when_completo_true_with_zero_amount(): void
    {
        Http::fake([
            'http://127.0.0.1:8081/graphql' => Http::response([
                'data' => [
                    'obtenerGastoPagadoPaciente' => [
                        'pacienteId' => $this->validUuid,
                        'moneda' => 'BOB',
                        'completo' => true,
                        'totalPagadoBs' => 0.00,
                    ],
                ],
            ], 200),
        ]);

        $result = $this->client->getPatientSpending($this->validUuid, 180.0, 'valid-bearer-token');

        $this->assertFalse($result->esEstimado);
        $this->assertTrue($result->gastoRealObservado);
        $this->assertSame('real_ms1_pagado', $result->gastoOrigen);
        $this->assertSame('BOB', $result->gastoMoneda);
        $this->assertEquals(0.0, $result->gastoTotalReal);
        $this->assertEquals(180.0, $result->gastoTotalEstimado);
    }

    public function test_completo_false_falls_back_to_estimated(): void
    {
        // QR or unverified demo payments in MS1 return completo=false
        Http::fake([
            'http://127.0.0.1:8081/graphql' => Http::response([
                'data' => [
                    'obtenerGastoPagadoPaciente' => [
                        'pacienteId' => $this->validUuid,
                        'moneda' => 'BOB',
                        'completo' => false,
                        'totalPagadoBs' => null,
                    ],
                ],
            ], 200),
        ]);

        $result = $this->client->getPatientSpending($this->validUuid, 180.0, 'valid-bearer-token');

        $this->assertTrue($result->esEstimado);
        $this->assertFalse($result->gastoRealObservado);
        $this->assertSame('estimado_fallback', $result->gastoOrigen);
        $this->assertNull($result->gastoTotalReal);
        $this->assertEquals(180.0, $result->gastoTotalEstimado);
    }

    public function test_missing_completo_falls_back_to_estimated(): void
    {
        Http::fake([
            'http://127.0.0.1:8081/graphql' => Http::response([
                'data' => [
                    'obtenerGastoPagadoPaciente' => [
                        'pacienteId' => $this->validUuid,
                        'moneda' => 'BOB',
                        'totalPagadoBs' => 100.00,
                    ],
                ],
            ], 200),
        ]);

        $result = $this->client->getPatientSpending($this->validUuid, 150.0, 'valid-token');

        $this->assertTrue($result->esEstimado);
        $this->assertFalse($result->gastoRealObservado);
        $this->assertNull($result->gastoTotalReal);
    }

    public function test_mismatched_patient_uuid_falls_back_to_estimated(): void
    {
        Http::fake([
            'http://127.0.0.1:8081/graphql' => Http::response([
                'data' => [
                    'obtenerGastoPagadoPaciente' => [
                        'pacienteId' => 'ffffffff-eeee-dddd-cccc-bbbbbbbbbbbb',
                        'moneda' => 'BOB',
                        'completo' => true,
                        'totalPagadoBs' => 500.00,
                    ],
                ],
            ], 200),
        ]);

        $result = $this->client->getPatientSpending($this->validUuid, 150.0, 'valid-token');

        $this->assertTrue($result->esEstimado);
        $this->assertFalse($result->gastoRealObservado);
        $this->assertNull($result->gastoTotalReal);
    }

    public function test_missing_patient_id_falls_back_to_estimated(): void
    {
        Http::fake([
            'http://127.0.0.1:8081/graphql' => Http::response([
                'data' => [
                    'obtenerGastoPagadoPaciente' => [
                        'pacienteId' => null,
                        'moneda' => 'BOB',
                        'completo' => true,
                        'totalPagadoBs' => 100.00,
                    ],
                ],
            ], 200),
        ]);

        $result = $this->client->getPatientSpending($this->validUuid, 150.0, 'valid-token');

        $this->assertTrue($result->esEstimado);
        $this->assertFalse($result->gastoRealObservado);
        $this->assertNull($result->gastoTotalReal);
    }

    public function test_unexpected_currency_falls_back_to_estimated(): void
    {
        Http::fake([
            'http://127.0.0.1:8081/graphql' => Http::response([
                'data' => [
                    'obtenerGastoPagadoPaciente' => [
                        'pacienteId' => $this->validUuid,
                        'moneda' => 'USD', // not BOB
                        'completo' => true,
                        'totalPagadoBs' => 200.00,
                    ],
                ],
            ], 200),
        ]);

        $result = $this->client->getPatientSpending($this->validUuid, 150.0, 'valid-token');

        $this->assertTrue($result->esEstimado);
        $this->assertFalse($result->gastoRealObservado);
        $this->assertNull($result->gastoTotalReal);
    }

    public function test_negative_total_amount_falls_back_to_estimated(): void
    {
        Http::fake([
            'http://127.0.0.1:8081/graphql' => Http::response([
                'data' => [
                    'obtenerGastoPagadoPaciente' => [
                        'pacienteId' => $this->validUuid,
                        'moneda' => 'BOB',
                        'completo' => true,
                        'totalPagadoBs' => -50.00,
                    ],
                ],
            ], 200),
        ]);

        $result = $this->client->getPatientSpending($this->validUuid, 150.0, 'valid-token');

        $this->assertTrue($result->esEstimado);
        $this->assertFalse($result->gastoRealObservado);
        $this->assertNull($result->gastoTotalReal);
    }

    public function test_non_numeric_total_amount_falls_back_to_estimated(): void
    {
        Http::fake([
            'http://127.0.0.1:8081/graphql' => Http::response([
                'data' => [
                    'obtenerGastoPagadoPaciente' => [
                        'pacienteId' => $this->validUuid,
                        'moneda' => 'BOB',
                        'completo' => true,
                        'totalPagadoBs' => 'NaN-or-invalid',
                    ],
                ],
            ], 200),
        ]);

        $result = $this->client->getPatientSpending($this->validUuid, 150.0, 'valid-token');

        $this->assertTrue($result->esEstimado);
        $this->assertFalse($result->gastoRealObservado);
        $this->assertNull($result->gastoTotalReal);
    }

    public function test_null_total_amount_with_completo_true_falls_back_to_estimated(): void
    {
        Http::fake([
            'http://127.0.0.1:8081/graphql' => Http::response([
                'data' => [
                    'obtenerGastoPagadoPaciente' => [
                        'pacienteId' => $this->validUuid,
                        'moneda' => 'BOB',
                        'completo' => true,
                        'totalPagadoBs' => null,
                    ],
                ],
            ], 200),
        ]);

        $result = $this->client->getPatientSpending($this->validUuid, 150.0, 'valid-token');

        $this->assertTrue($result->esEstimado);
        $this->assertFalse($result->gastoRealObservado);
        $this->assertNull($result->gastoTotalReal);
    }

    public function test_missing_bearer_token_falls_back_to_estimated_without_calling_ms1(): void
    {
        Http::fake();

        $result = $this->client->getPatientSpending($this->validUuid, 180.0, null);

        Http::assertNothingSent();
        $this->assertTrue($result->esEstimado);
        $this->assertFalse($result->gastoRealObservado);
        $this->assertSame('estimado_fallback', $result->gastoOrigen);
        $this->assertNull($result->gastoTotalReal);
        $this->assertEquals(180.0, $result->gastoTotalEstimado);
    }

    public function test_empty_string_bearer_token_falls_back_to_estimated_without_calling_ms1(): void
    {
        Http::fake();

        $result = $this->client->getPatientSpending($this->validUuid, 180.0, '   ');

        Http::assertNothingSent();
        $this->assertTrue($result->esEstimado);
        $this->assertFalse($result->gastoRealObservado);
        $this->assertNull($result->gastoTotalReal);
    }

    public function test_invalid_uuid_falls_back_to_estimated_without_calling_ms1(): void
    {
        Http::fake();

        $result = $this->client->getPatientSpending('non-uuid-patient-id', 180.0, 'valid-token');

        Http::assertNothingSent();
        $this->assertTrue($result->esEstimado);
        $this->assertFalse($result->gastoRealObservado);
        $this->assertNull($result->gastoTotalReal);
    }

    public function test_ms1_401_unauthorized_falls_back_to_estimated(): void
    {
        Http::fake([
            'http://127.0.0.1:8081/graphql' => Http::response(['error' => 'Unauthorized'], 401),
        ]);

        $result = $this->client->getPatientSpending($this->validUuid, 240.0, 'expired-token');

        $this->assertTrue($result->esEstimado);
        $this->assertFalse($result->gastoRealObservado);
        $this->assertSame('estimado_fallback', $result->gastoOrigen);
        $this->assertNull($result->gastoTotalReal);
        $this->assertEquals(240.0, $result->gastoTotalEstimado);
    }

    public function test_ms1_403_forbidden_falls_back_to_estimated(): void
    {
        Http::fake([
            'http://127.0.0.1:8081/graphql' => Http::response(['error' => 'Forbidden'], 403),
        ]);

        $result = $this->client->getPatientSpending($this->validUuid, 240.0, 'low-privilege-token');

        $this->assertTrue($result->esEstimado);
        $this->assertFalse($result->gastoRealObservado);
        $this->assertNull($result->gastoTotalReal);
    }

    public function test_ms1_timeout_falls_back_to_estimated_without_exception_leak(): void
    {
        Http::fake([
            'http://127.0.0.1:8081/graphql' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Connection timed out to http://secret-host:8081');
            },
        ]);

        $result = $this->client->getPatientSpending($this->validUuid, 300.0, 'valid-token');

        $this->assertTrue($result->esEstimado);
        $this->assertFalse($result->gastoRealObservado);
        $this->assertNull($result->gastoTotalReal);
        $this->assertEquals(300.0, $result->gastoTotalEstimado);
    }

    public function test_ms1_graphql_errors_falls_back_to_estimated(): void
    {
        Http::fake([
            'http://127.0.0.1:8081/graphql' => Http::response([
                'errors' => [
                    ['message' => 'Internal server error resolving field obtenerGastoPagadoPaciente'],
                ],
            ], 200),
        ]);

        $result = $this->client->getPatientSpending($this->validUuid, 300.0, 'valid-token');

        $this->assertTrue($result->esEstimado);
        $this->assertFalse($result->gastoRealObservado);
        $this->assertNull($result->gastoTotalReal);
    }

    public function test_malformed_response_shape_falls_back_to_estimated(): void
    {
        Http::fake([
            'http://127.0.0.1:8081/graphql' => Http::response([
                'data' => [
                    'wrongField' => [],
                ],
            ], 200),
        ]);

        $result = $this->client->getPatientSpending($this->validUuid, 300.0, 'valid-token');

        $this->assertTrue($result->esEstimado);
        $this->assertFalse($result->gastoRealObservado);
        $this->assertNull($result->gastoTotalReal);
    }

    public function test_malformed_errors_member_boolean_false_falls_back_to_estimated(): void
    {
        Http::fake([
            'http://127.0.0.1:8081/graphql' => Http::response([
                'errors' => false,
                'data' => [
                    'obtenerGastoPagadoPaciente' => [
                        'pacienteId' => $this->validUuid,
                        'moneda' => 'BOB',
                        'completo' => true,
                        'totalPagadoBs' => 240.50,
                    ],
                ],
            ], 200),
        ]);

        $result = $this->client->getPatientSpending($this->validUuid, 180.0, 'valid-token');

        $this->assertTrue($result->esEstimado);
        $this->assertFalse($result->gastoRealObservado);
        $this->assertSame('estimado_fallback', $result->gastoOrigen);
        $this->assertNull($result->gastoTotalReal);
        $this->assertEquals(180.0, $result->gastoTotalEstimado);
    }

    public function test_valid_empty_errors_array_allows_real_total(): void
    {
        Http::fake([
            'http://127.0.0.1:8081/graphql' => Http::response([
                'errors' => [],
                'data' => [
                    'obtenerGastoPagadoPaciente' => [
                        'pacienteId' => $this->validUuid,
                        'moneda' => 'BOB',
                        'completo' => true,
                        'totalPagadoBs' => 240.50,
                    ],
                ],
            ], 200),
        ]);

        $result = $this->client->getPatientSpending($this->validUuid, 180.0, 'valid-token');

        $this->assertFalse($result->esEstimado);
        $this->assertTrue($result->gastoRealObservado);
        $this->assertSame('real_ms1_pagado', $result->gastoOrigen);
        $this->assertEquals(240.50, $result->gastoTotalReal);
    }

    public function test_query_and_separate_callers_never_share_authorization_or_results(): void
    {
        config(['services.ms1.graphql_url' => 'http://billing.invalid/graphql']);
        Http::fakeSequence()
            ->push(['data' => ['obtenerGastoPagadoPaciente' => [
                'pacienteId' => $this->validUuid,
                'moneda' => 'BOB',
                'completo' => true,
                'totalPagadoBs' => 240.5,
            ]]], 200)
            ->push(['errors' => [['message' => 'private-canary']]], 403);

        $client = new Ms1GraphQLBillingClient();
        $this->assertSame(240.5, $client->getPatientSpending($this->validUuid, 180, 'caller-A')->gastoTotalReal);
        $this->assertNull($client->getPatientSpending($this->validUuid, 180, 'caller-B')->gastoTotalReal);

        $sent = Http::recorded();
        $this->assertCount(2, $sent);
        foreach (['caller-A', 'caller-B'] as $i => $token) {
            $request = $sent[$i][0];
            $this->assertSame('http://billing.invalid/graphql', $request->url());
            $this->assertSame(['Bearer '.$token], $request->header('Authorization'));
            $this->assertSame(['pacienteId' => $this->validUuid], $request['variables']);
            $this->assertStringNotContainsString($token, $request->body());
        }
    }
}
