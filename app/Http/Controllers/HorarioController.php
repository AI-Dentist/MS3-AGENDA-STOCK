<?php

namespace App\Http\Controllers;

use App\Application\UseCases\ConsultarHorariosUseCase;
use App\Application\UseCases\CrearHorarioUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class HorarioController extends Controller
{
    public function index(Request $request, ConsultarHorariosUseCase $useCase): JsonResponse
    {
        $odontologoId = $request->query('odontologo_id');
        $horarios = $useCase->execute($odontologoId);

        return response()->json([
            'success' => true,
            'data' => [
                'horarios' => $horarios,
                'total' => count($horarios),
            ],
            'horarios' => $horarios,
            'total' => count($horarios),
            'timestamp' => now()->toIso8601String(),
        ], 200);
    }

    public function store(Request $request, CrearHorarioUseCase $useCase): JsonResponse
    {
        $token = $request->bearerToken();
        if (! $token) {
            return response()->json([
                'success' => false,
                'error' => 'Token Bearer de Keycloak requerido para autenticar con el catálogo ERP.',
                'code' => 'UNAUTHORIZED',
                'timestamp' => now()->toIso8601String(),
            ], 401);
        }

        $validator = validator($request->all(), [
            'odontologo_id' => 'required|uuid',
            'consultorio_id' => 'required|uuid',
            'especialidad' => 'required|string',
            'fecha' => 'required|date_format:Y-m-d',
            'hora_inicio' => 'required|string',
            'hora_fin' => 'required|string',
            'disponible' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Datos de horario inválidos o incompletos.',
                'code' => 'INVALID_HORARIO_DATA',
                'errors' => $validator->errors(),
                'timestamp' => now()->toIso8601String(),
            ], 422);
        }

        $data = $validator->validated();

        if (strtotime($data['hora_inicio']) >= strtotime($data['hora_fin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Rango horario inválido: la hora_inicio debe ser anterior a la hora_fin.',
                'code' => 'INVALID_TIME_RANGE',
                'timestamp' => now()->toIso8601String(),
            ], 422);
        }

        try {
            $resultado = $useCase->execute($data, $token);

            return response()->json([
                'success' => true,
                'data' => $resultado['horario'],
                'mensaje' => $resultado['mensaje'],
                'timestamp' => now()->toIso8601String(),
            ], 201);
        } catch (InvalidArgumentException $e) {
            $code = str_contains($e->getMessage(), 'odontólogo')
                ? 'INVALID_ODONTOLOGO_REFERENCE'
                : (str_contains($e->getMessage(), 'consultorio') ? 'INVALID_CONSULTORIO_REFERENCE' : 'INVALID_HORARIO_DATA');

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'code' => $code,
                'timestamp' => now()->toIso8601String(),
            ], 422);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'code' => 'UPSTREAM_ERP_UNAVAILABLE',
                'timestamp' => now()->toIso8601String(),
            ], 502);
        }
    }
}

