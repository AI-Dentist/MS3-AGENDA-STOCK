<?php

namespace App\Http\Controllers;

use App\Application\UseCases\AgendarCitaUseCase;
use App\Domain\Repositories\CitaRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CitaController extends Controller
{
    public function __construct(
        private AgendarCitaUseCase $agendarCitaUseCase,
        private CitaRepositoryInterface $citaRepository
    ) {}

    public function index(): JsonResponse
    {
        $citas = $this->citaRepository->findAll();

        return response()->json([
            'citas' => array_map(fn($cita) => $cita->toArray(), $citas),
            'total' => count($citas)
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');

        if (!$idempotencyKey) {
            return response()->json([
                'error' => 'El encabezado Idempotency-Key es obligatorio.'
            ], 400);
        }

        $validated = $request->validate([
            'odontologo_id'  => 'required|string',
            'consultorio_id' => 'required|string',
            'paciente_id'    => 'required|string',
            'fecha_hora'     => 'required|string'
        ]);

        $resultado = $this->agendarCitaUseCase->ejecutar(
            idempotencyKey: $idempotencyKey,
            odontologoId: $validated['odontologo_id'],
            consultorioId: $validated['consultorio_id'],
            pacienteId: $validated['paciente_id'],
            fechaHora: $validated['fecha_hora']
        );

        return response()->json($resultado, $resultado['idempotente'] ? 200 : 201);
    }
}