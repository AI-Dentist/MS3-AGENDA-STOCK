<?php

namespace Tests\Feature;

use App\Domain\Repositories\HorarioRepositoryInterface;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HorarioApiTest extends TestCase
{
    private string $devSecret = 'ms3-agenda-stock-local-jwt-secret-key-2026';

    private string $validDoctorUuid = 'e1a2b3c4-1111-2222-3333-444455556666';

    private string $validConsultorioUuid = 'f9e8d7c6-9999-8888-7777-666655554444';

    private function generateJwt(array $roles = ['ADMIN'], string $subject = 'admin-user'): string
    {
        $payload = [
            'iss' => 'http://localhost:8180/realms/dental-platform',
            'aud' => 'ms3-agenda-stock',
            'sub' => $subject,
            'preferred_username' => 'admin.test',
            'realm_access' => [
                'roles' => $roles,
            ],
            'exp' => time() + 3600,
        ];

        return JWT::encode($payload, $this->devSecret, 'HS256');
    }

    public function test_rechaza_creacion_sin_autenticacion(): void
    {
        $response = $this->postJson('/api/agendamiento/horarios', [
            'odontologo_id' => $this->validDoctorUuid,
            'consultorio_id' => $this->validConsultorioUuid,
            'especialidad' => 'Ortodoncia',
            'fecha' => '2026-11-20',
            'hora_inicio' => '08:00',
            'hora_fin' => '12:00',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_rechaza_creacion_con_rol_no_admin(): void
    {
        $token = $this->generateJwt(['ODONTOLOGO']);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/agendamiento/horarios', [
            'odontologo_id' => $this->validDoctorUuid,
            'consultorio_id' => $this->validConsultorioUuid,
            'especialidad' => 'Ortodoncia',
            'fecha' => '2026-11-20',
            'hora_inicio' => '08:00',
            'hora_fin' => '12:00',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'code' => 'FORBIDDEN_ROLE',
            ]);
    }

    public function test_rechaza_campos_faltantes_o_invalidos(): void
    {
        $token = $this->generateJwt(['ADMIN']);

        // Falta odontologo_id y fecha
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/agendamiento/horarios', [
            'consultorio_id' => $this->validConsultorioUuid,
            'especialidad' => 'Ortodoncia',
            'hora_inicio' => '08:00',
            'hora_fin' => '12:00',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'code' => 'INVALID_HORARIO_DATA',
            ]);
    }

    public function test_rechaza_rango_horario_invalido(): void
    {
        $token = $this->generateJwt(['ADMIN']);

        // hora_inicio mayor o igual que hora_fin
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/agendamiento/horarios', [
            'odontologo_id' => $this->validDoctorUuid,
            'consultorio_id' => $this->validConsultorioUuid,
            'especialidad' => 'Ortodoncia',
            'fecha' => '2026-11-20',
            'hora_inicio' => '14:00',
            'hora_fin' => '10:00',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'code' => 'INVALID_TIME_RANGE',
            ]);
    }

    public function test_rechaza_odontologo_no_existente_o_inactivo_en_ms1(): void
    {
        $token = $this->generateJwt(['ADMIN']);

        // Fake MS1 GraphQL devolviendo odontólogo con activo=false
        Http::fake([
            '*/graphql' => Http::response([
                'data' => [
                    'listarOdontologos' => [
                        ['id' => $this->validDoctorUuid, 'activo' => false],
                    ],
                    'listarConsultorios' => [
                        ['id' => $this->validConsultorioUuid, 'estado' => 'DISPONIBLE'],
                    ],
                ],
            ], 200),
        ]);

        $repo = app(HorarioRepositoryInterface::class);
        $totalAntes = count($repo->findAll());

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/agendamiento/horarios', [
            'odontologo_id' => $this->validDoctorUuid,
            'consultorio_id' => $this->validConsultorioUuid,
            'especialidad' => 'Ortodoncia',
            'fecha' => '2026-11-20',
            'hora_inicio' => '08:00',
            'hora_fin' => '12:00',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'code' => 'INVALID_ODONTOLOGO_REFERENCE',
            ]);

        // Asegurar que no se guardó ningún registro
        $this->assertCount($totalAntes, $repo->findAll());
    }

    public function test_rechaza_consultorio_no_existente_o_no_activo_en_ms1(): void
    {
        $token = $this->generateJwt(['ADMIN']);

        // Fake MS1 GraphQL devolviendo consultorio con estado=INACTIVO
        Http::fake([
            '*/graphql' => Http::response([
                'data' => [
                    'listarOdontologos' => [
                        ['id' => $this->validDoctorUuid, 'activo' => true],
                    ],
                    'listarConsultorios' => [
                        ['id' => $this->validConsultorioUuid, 'estado' => 'INACTIVO'],
                    ],
                ],
            ], 200),
        ]);

        $repo = app(HorarioRepositoryInterface::class);
        $totalAntes = count($repo->findAll());

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/agendamiento/horarios', [
            'odontologo_id' => $this->validDoctorUuid,
            'consultorio_id' => $this->validConsultorioUuid,
            'especialidad' => 'Ortodoncia',
            'fecha' => '2026-11-20',
            'hora_inicio' => '08:00',
            'hora_fin' => '12:00',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'code' => 'INVALID_CONSULTORIO_REFERENCE',
            ]);

        // Asegurar que no se guardó ningún registro
        $this->assertCount($totalAntes, $repo->findAll());
    }

    public function test_rechaza_cuando_ms1_falla_o_timeout(): void
    {
        $token = $this->generateJwt(['ADMIN']);

        // Fake MS1 GraphQL con error 500
        Http::fake([
            '*/graphql' => Http::response(['errors' => [['message' => 'Internal ERP Error']]], 500),
        ]);

        $repo = app(HorarioRepositoryInterface::class);
        $totalAntes = count($repo->findAll());

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/agendamiento/horarios', [
            'odontologo_id' => $this->validDoctorUuid,
            'consultorio_id' => $this->validConsultorioUuid,
            'especialidad' => 'Ortodoncia',
            'fecha' => '2026-11-20',
            'hora_inicio' => '08:00',
            'hora_fin' => '12:00',
        ]);

        $response->assertStatus(502)
            ->assertJson([
                'success' => false,
                'code' => 'UPSTREAM_ERP_UNAVAILABLE',
            ]);

        $this->assertCount($totalAntes, $repo->findAll());
    }

    public function test_permite_creacion_exitosa_por_admin_con_referencias_validas(): void
    {
        $token = $this->generateJwt(['ADMIN']);

        Http::fake([
            '*/graphql' => Http::response([
                'data' => [
                    'listarOdontologos' => [
                        ['id' => $this->validDoctorUuid, 'activo' => true],
                    ],
                    'listarConsultorios' => [
                        ['id' => $this->validConsultorioUuid, 'estado' => 'DISPONIBLE'],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/agendamiento/horarios', [
            'odontologo_id' => $this->validDoctorUuid,
            'consultorio_id' => $this->validConsultorioUuid,
            'especialidad' => 'Ortodoncia',
            'fecha' => '2026-11-25',
            'hora_inicio' => '09:00',
            'hora_fin' => '13:00',
            'disponible' => true,
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'odontologo_id' => $this->validDoctorUuid,
                    'consultorio_id' => $this->validConsultorioUuid,
                    'especialidad' => 'Ortodoncia',
                    'fecha' => '2026-11-25',
                    'hora_inicio' => '09:00',
                    'hora_fin' => '13:00',
                    'disponible' => true,
                ],
            ]);

        $createdId = $response->json('data.id');
        $this->assertNotNull($createdId);
        $this->assertStringStartsWith('hor-', $createdId);

        // Verificar lectura pública posterior a través de GET /api/agendamiento/horarios
        $getResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/agendamiento/horarios?odontologo_id='.$this->validDoctorUuid);

        $getResponse->assertStatus(200);
        $items = $getResponse->json('data.horarios');
        $this->assertNotEmpty($items);
        $this->assertEquals($this->validDoctorUuid, $items[0]['odontologo_id']);
    }

    public function test_aislamiento_de_tokens_entre_llamadas_concurrentes(): void
    {
        $tokenA = $this->generateJwt(['ADMIN'], 'admin-alpha');
        $tokenB = $this->generateJwt(['ADMIN'], 'admin-beta');

        $capturedTokens = [];

        Http::fake(function ($request) use (&$capturedTokens) {
            $capturedTokens[] = $request->header('Authorization')[0] ?? '';

            return Http::response([
                'data' => [
                    'listarOdontologos' => [
                        ['id' => $this->validDoctorUuid, 'activo' => true],
                    ],
                    'listarConsultorios' => [
                        ['id' => $this->validConsultorioUuid, 'estado' => 'DISPONIBLE'],
                    ],
                ],
            ], 200);
        });

        // Llamada de Caller A
        $this->withHeaders(['Authorization' => "Bearer {$tokenA}"])
            ->postJson('/api/agendamiento/horarios', [
                'odontologo_id' => $this->validDoctorUuid,
                'consultorio_id' => $this->validConsultorioUuid,
                'especialidad' => 'Periodoncia',
                'fecha' => '2026-11-26',
                'hora_inicio' => '10:00',
                'hora_fin' => '12:00',
            ])->assertStatus(201);

        // Llamada de Caller B
        $this->withHeaders(['Authorization' => "Bearer {$tokenB}"])
            ->postJson('/api/agendamiento/horarios', [
                'odontologo_id' => $this->validDoctorUuid,
                'consultorio_id' => $this->validConsultorioUuid,
                'especialidad' => 'Endodoncia',
                'fecha' => '2026-11-27',
                'hora_inicio' => '14:00',
                'hora_fin' => '18:00',
            ])->assertStatus(201);

        // Verificar que cada llamada envió exactamente su propio token Bearer
        $this->assertCount(2, $capturedTokens);
        $this->assertEquals("Bearer {$tokenA}", $capturedTokens[0]);
        $this->assertEquals("Bearer {$tokenB}", $capturedTokens[1]);
        $this->assertNotEquals($capturedTokens[0], $capturedTokens[1]);
    }

    public function test_rechaza_hora_inicio_invalida_no_formato_reproduccion_clock(): void
    {
        $token = $this->generateJwt(['ADMIN']);

        Http::fake([
            '*/graphql' => Http::response([
                'data' => [
                    'listarOdontologos' => [
                        ['id' => $this->validDoctorUuid, 'activo' => true],
                    ],
                    'listarConsultorios' => [
                        ['id' => $this->validConsultorioUuid, 'estado' => 'DISPONIBLE'],
                    ],
                ],
            ], 200),
        ]);

        $repo = app(HorarioRepositoryInterface::class);
        $totalAntes = count($repo->findAll());

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/agendamiento/horarios', [
            'odontologo_id' => $this->validDoctorUuid,
            'consultorio_id' => $this->validConsultorioUuid,
            'especialidad' => 'Ortodoncia',
            'fecha' => '2026-11-20',
            'hora_inicio' => 'not-a-time',
            'hora_fin' => '17:00',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'code' => 'INVALID_HORARIO_DATA',
            ]);

        $this->assertCount($totalAntes, $repo->findAll());
    }

    public function test_enmascara_errores_upstream_con_canario_sintetico_y_json_malformado_privacy(): void
    {
        $token = $this->generateJwt(['ADMIN']);
        $syntheticCanary = 'SYNTHETIC_CANARY_SECRET_LEAK_XYZ_999';

        $repo = app(HorarioRepositoryInterface::class);
        $totalAntes = count($repo->findAll());

        // Sub-caso 1: GraphQL error con canario sintético
        Http::fake([
            '*/graphql' => Http::response([
                'errors' => [
                    ['message' => "Upstream error details containing {$syntheticCanary}"],
                ],
            ], 200),
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/agendamiento/horarios', [
            'odontologo_id' => $this->validDoctorUuid,
            'consultorio_id' => $this->validConsultorioUuid,
            'especialidad' => 'Ortodoncia',
            'fecha' => '2026-11-20',
            'hora_inicio' => '08:00',
            'hora_fin' => '12:00',
        ]);

        $response->assertStatus(502)
            ->assertJson([
                'success' => false,
                'code' => 'UPSTREAM_ERP_UNAVAILABLE',
            ]);

        $this->assertStringNotContainsString($syntheticCanary, (string) $response->getContent());
        $this->assertCount($totalAntes, $repo->findAll());

        // Sub-caso 2: Respuesta con catálogo malformado (no array) debe responder 502 enmascarado y no uncaught 500
        Http::fake([
            '*/graphql' => Http::response([
                'data' => [
                    'listarOdontologos' => 'malformed-string-not-an-array',
                    'listarConsultorios' => null,
                ],
            ], 200),
        ]);

        $responseMalformed = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/agendamiento/horarios', [
            'odontologo_id' => $this->validDoctorUuid,
            'consultorio_id' => $this->validConsultorioUuid,
            'especialidad' => 'Ortodoncia',
            'fecha' => '2026-11-20',
            'hora_inicio' => '08:00',
            'hora_fin' => '12:00',
        ]);

        $responseMalformed->assertStatus(502)
            ->assertJson([
                'success' => false,
                'code' => 'UPSTREAM_ERP_UNAVAILABLE',
            ]);

        $this->assertCount($totalAntes, $repo->findAll());
    }

    public function test_persiste_exact_bytes_canonicos_de_master_cuando_input_es_mayuscula_canonical(): void
    {
        $token = $this->generateJwt(['ADMIN']);

        $masterDoctorUuid = strtolower($this->validDoctorUuid);
        $masterConsultorioUuid = strtolower($this->validConsultorioUuid);

        $inputDoctorUuid = strtoupper($this->validDoctorUuid);
        $inputConsultorioUuid = strtoupper($this->validConsultorioUuid);

        Http::fake([
            '*/graphql' => Http::response([
                'data' => [
                    'listarOdontologos' => [
                        ['id' => $masterDoctorUuid, 'activo' => true],
                    ],
                    'listarConsultorios' => [
                        ['id' => $masterConsultorioUuid, 'estado' => 'DISPONIBLE'],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/agendamiento/horarios', [
            'odontologo_id' => $inputDoctorUuid,
            'consultorio_id' => $inputConsultorioUuid,
            'especialidad' => 'Ortodoncia',
            'fecha' => '2026-11-20',
            'hora_inicio' => '08:00',
            'hora_fin' => '12:00',
        ]);

        $response->assertStatus(201);
        $this->assertSame($masterDoctorUuid, $response->json('data.odontologo_id'));
        $this->assertSame($masterConsultorioUuid, $response->json('data.consultorio_id'));

        // Verificar que GET /api/agendamiento/horarios retorna exactamente los bytes canónicos del master
        $getResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/agendamiento/horarios?odontologo_id='.$masterDoctorUuid);

        $getResponse->assertStatus(200);
        $items = $getResponse->json('data.horarios');
        $this->assertNotEmpty($items);
        $this->assertSame($masterDoctorUuid, $items[0]['odontologo_id']);
        $this->assertSame($masterConsultorioUuid, $items[0]['consultorio_id']);
    }

    public function test_permite_creacion_exitosa_con_consultorio_en_estado_disponible_reproduccion_red(): void
    {
        $token = $this->generateJwt(['ADMIN']);

        Http::fake([
            '*/graphql' => Http::response([
                'data' => [
                    'listarOdontologos' => [
                        ['id' => $this->validDoctorUuid, 'activo' => true],
                    ],
                    'listarConsultorios' => [
                        ['id' => $this->validConsultorioUuid, 'estado' => 'DISPONIBLE'],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/agendamiento/horarios', [
            'odontologo_id' => $this->validDoctorUuid,
            'consultorio_id' => $this->validConsultorioUuid,
            'especialidad' => 'Ortodoncia',
            'fecha' => '2026-11-20',
            'hora_inicio' => '08:00',
            'hora_fin' => '12:00',
        ]);

        $response->assertStatus(201);
    }

    public function test_permite_creacion_con_consultorio_en_mantenimiento_activo_en_erp(): void
    {
        $token = $this->generateJwt(['ADMIN']);

        Http::fake([
            '*/graphql' => Http::response([
                'data' => [
                    'listarOdontologos' => [
                        ['id' => $this->validDoctorUuid, 'activo' => true],
                    ],
                    'listarConsultorios' => [
                        ['id' => $this->validConsultorioUuid, 'estado' => 'MANTENIMIENTO'],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/agendamiento/horarios', [
            'odontologo_id' => $this->validDoctorUuid,
            'consultorio_id' => $this->validConsultorioUuid,
            'especialidad' => 'Ortodoncia',
            'fecha' => '2026-11-21',
            'hora_inicio' => '14:00',
            'hora_fin' => '18:00',
        ]);

        $response->assertStatus(201);
        $this->assertEquals($this->validConsultorioUuid, $response->json('data.consultorio_id'));
    }

    public function test_rechaza_consultorio_con_estado_inventado_activo_o_desconocido(): void
    {
        $token = $this->generateJwt(['ADMIN']);
        $repo = app(HorarioRepositoryInterface::class);
        $totalAntes = count($repo->findAll());

        // Caso A: Estado inventado 'ACTIVO' (no existe en ERP Consultorio.ESTADOS)
        Http::fake([
            '*/graphql' => Http::response([
                'data' => [
                    'listarOdontologos' => [
                        ['id' => $this->validDoctorUuid, 'activo' => true],
                    ],
                    'listarConsultorios' => [
                        ['id' => $this->validConsultorioUuid, 'estado' => 'ACTIVO'],
                    ],
                ],
            ], 200),
        ]);

        $responseActivo = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/agendamiento/horarios', [
            'odontologo_id' => $this->validDoctorUuid,
            'consultorio_id' => $this->validConsultorioUuid,
            'especialidad' => 'Ortodoncia',
            'fecha' => '2026-11-22',
            'hora_inicio' => '08:00',
            'hora_fin' => '12:00',
        ]);

        $responseActivo->assertStatus(422)
            ->assertJson([
                'success' => false,
                'code' => 'INVALID_CONSULTORIO_REFERENCE',
            ]);
        $this->assertCount($totalAntes, $repo->findAll());

        // Caso B: Estado desconocido 'DESCONOCIDO'
        Http::fake([
            '*/graphql' => Http::response([
                'data' => [
                    'listarOdontologos' => [
                        ['id' => $this->validDoctorUuid, 'activo' => true],
                    ],
                    'listarConsultorios' => [
                        ['id' => $this->validConsultorioUuid, 'estado' => 'DESCONOCIDO'],
                    ],
                ],
            ], 200),
        ]);

        $responseUnknown = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/agendamiento/horarios', [
            'odontologo_id' => $this->validDoctorUuid,
            'consultorio_id' => $this->validConsultorioUuid,
            'especialidad' => 'Ortodoncia',
            'fecha' => '2026-11-22',
            'hora_inicio' => '08:00',
            'hora_fin' => '12:00',
        ]);

        $responseUnknown->assertStatus(422)
            ->assertJson([
                'success' => false,
                'code' => 'INVALID_CONSULTORIO_REFERENCE',
            ]);
        $this->assertCount($totalAntes, $repo->findAll());
    }
}
