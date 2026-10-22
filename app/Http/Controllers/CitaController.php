<?php

namespace App\Http\Controllers;

use App\Application\UseCases\AgendarCitaUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CitaController extends Controller
{
    public function __construct(private AgendarCitaUseCase $agendarCitaUseCase) {}

    public function store(Request $request): JsonResponse
    {
        // 1. Obtener clave de idempotencia de la cabecera HTTP
        $idempotencyKey = $request->header('Idempotency-Key');

        if (!$idempotencyKey) {
            return response()->json([
                'error' => 'Falta la cabecera obligatoria Idempotency-Key'
            ], 400);
        }

        // 2. Validar payload de entrada
        $validated = $request->validate([
            'odontologo_id'  => 'required|string',
            'consultorio_id' => 'required|string',
            'paciente_id'    => 'required|string',
            'fecha_hora'     => 'required|date_format:Y-m-d H:i:s',
        ]);

        // 3. Delegar la ejecución al caso de uso
        $resultado = $this->agendarCitaUseCase->execute($validated, $idempotencyKey);

        $statusCode = $resultado['idempotente'] ? 200 : 201;

        return response()->json($resultado, $statusCode);
    }
}