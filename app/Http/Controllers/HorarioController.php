<?php

namespace App\Http\Controllers;

use App\Application\UseCases\ConsultarHorariosUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HorarioController extends Controller
{
    public function index(Request $request, ConsultarHorariosUseCase $useCase): JsonResponse
    {
        $odontologoId = $request->query('odontologo_id');
        $horarios = $useCase->execute($odontologoId);

        return response()->json([
            'horarios' => $horarios,
            'total' => count($horarios)
        ], 200);
    }
}