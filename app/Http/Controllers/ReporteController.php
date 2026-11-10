<?php

namespace App\Http\Controllers;

use App\Application\UseCases\ConsultarReporteEntidadUseCase;
use App\Domain\Entities\Cita;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class ReporteController extends Controller
{
    private const ENTIDADES_PERMITIDAS = ['citas', 'insumos'];

    public function __construct(
        private ConsultarReporteEntidadUseCase $consultarReporteEntidadUseCase
    ) {}

    public function show(Request $request, string $entidad): JsonResponse
    {
        // 1. Validación de entidad en lista blanca
        if (! in_array($entidad, self::ENTIDADES_PERMITIDAS, true)) {
            return response()->json([
                'success' => false,
                'code' => 'NOT_FOUND',
                'error' => "Entidad de reporte no encontrada. Permitidas: ".implode(', ', self::ENTIDADES_PERMITIDAS),
                'timestamp' => now()->toIso8601String(),
            ], 404);
        }

        // 2. Validación de período opcional (ambos o ninguno)
        $desde = $request->query('desde');
        $hasta = $request->query('hasta');

        // Rechazar tipos no escalares (ej. arrays ?desde[]=x) con 422
        if (($request->has('desde') && ! is_string($desde)) || ($request->has('hasta') && ! is_string($hasta))) {
            return response()->json([
                'success' => false,
                'code' => 'INVALID_PERIOD',
                'error' => "Los parámetros 'desde' y 'hasta' deben ser cadenas de texto con formato YYYY-MM-DD.",
                'timestamp' => now()->toIso8601String(),
            ], 422);
        }

        $hasDesde = $desde !== null && trim($desde) !== '';
        $hasHasta = $hasta !== null && trim($hasta) !== '';

        if (($hasDesde && ! $hasHasta) || (! $hasDesde && $hasHasta)) {
            return response()->json([
                'success' => false,
                'code' => 'INVALID_PERIOD',
                'error' => "Los parámetros 'desde' y 'hasta' deben enviarse juntos o ninguno.",
                'timestamp' => now()->toIso8601String(),
            ], 422);
        }

        $dtDesde = null;
        $dtHasta = null;

        if ($hasDesde && $hasHasta) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
                return response()->json([
                    'success' => false,
                    'code' => 'INVALID_PERIOD',
                    'error' => "Formato de fecha inválido. Utilice YYYY-MM-DD.",
                    'timestamp' => now()->toIso8601String(),
                ], 422);
            }

            $tz = new DateTimeZone('America/La_Paz');
            $dtDesde = DateTimeImmutable::createFromFormat('!Y-m-d', $desde, $tz);
            $dtHasta = DateTimeImmutable::createFromFormat('!Y-m-d', $hasta, $tz);

            if (! $dtDesde || $dtDesde->format('Y-m-d') !== $desde || ! $dtHasta || $dtHasta->format('Y-m-d') !== $hasta) {
                return response()->json([
                    'success' => false,
                    'code' => 'INVALID_PERIOD',
                    'error' => "Fecha de calendario inválida para 'desde' o 'hasta'.",
                    'timestamp' => now()->toIso8601String(),
                ], 422);
            }

            if ($dtDesde > $dtHasta) {
                return response()->json([
                    'success' => false,
                    'code' => 'INVALID_PERIOD',
                    'error' => "La fecha 'desde' no puede ser posterior a 'hasta'.",
                    'timestamp' => now()->toIso8601String(),
                ], 422);
            }

            $diasInclusive = (int) $dtDesde->diff($dtHasta)->days + 1;
            if ($diasInclusive > 366) {
                return response()->json([
                    'success' => false,
                    'code' => 'PERIOD_TOO_LONG',
                    'error' => 'El período no puede ser superior a 366 días.',
                    'timestamp' => now()->toIso8601String(),
                ], 422);
            }
        }

        // 3. Validación de filtro 'estado'
        $estado = null;
        if ($request->has('estado')) {
            $rawEstado = $request->query('estado');

            if ($entidad === 'insumos') {
                return response()->json([
                    'success' => false,
                    'code' => 'INVALID_FILTER',
                    'error' => "El filtro 'estado' solo aplica para la entidad 'citas'.",
                    'timestamp' => now()->toIso8601String(),
                ], 422);
            }

            if (! is_string($rawEstado)) {
                return response()->json([
                    'success' => false,
                    'code' => 'INVALID_ESTADO',
                    'error' => "El parámetro 'estado' debe ser una cadena de texto.",
                    'timestamp' => now()->toIso8601String(),
                ], 422);
            }

            $trimmedEstado = trim($rawEstado);
            if ($trimmedEstado !== '') {
                if (! in_array($trimmedEstado, Cita::ESTADOS_VALIDOS, true)) {
                    return response()->json([
                        'success' => false,
                        'code' => 'INVALID_ESTADO',
                        'error' => "Estado de cita inválido: {$trimmedEstado}. Permitidos: ".implode(', ', Cita::ESTADOS_VALIDOS),
                        'timestamp' => now()->toIso8601String(),
                    ], 422);
                }
                $estado = $trimmedEstado;
            }
        }

        try {
            $data = $this->consultarReporteEntidadUseCase->execute(
                $entidad,
                $hasDesde ? $desde : null,
                $hasHasta ? $hasta : null,
                $estado
            );

            return response()->json([
                'success' => true,
                'data' => $data,
                'timestamp' => now()->toIso8601String(),
            ], 200);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'code' => 'REPORT_GENERATION_ERROR',
                'error' => 'Error al generar reporte de entidad.',
                'timestamp' => now()->toIso8601String(),
            ], 500);
        }
    }
}
