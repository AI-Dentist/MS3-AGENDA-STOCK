<?php

namespace App\Application\UseCases;

use App\Domain\Entities\Cita;
use App\Domain\Entities\MovimientoStock;
use App\Domain\Repositories\CitaRepositoryInterface;
use App\Domain\Repositories\InsumoRepositoryInterface;
use App\Domain\Repositories\MovimientoStockRepositoryInterface;
use App\Domain\Services\CitaFeatureExtractor;
use App\Domain\Services\MlInferenceServiceInterface;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

class ConsultarReporteEntidadUseCase
{
    private CitaFeatureExtractor $featureExtractor;

    public function __construct(
        private CitaRepositoryInterface $citaRepository,
        private InsumoRepositoryInterface $insumoRepository,
        private MovimientoStockRepositoryInterface $movimientoStockRepository,
        private MlInferenceServiceInterface $mlService,
        ?CitaFeatureExtractor $featureExtractor = null
    ) {
        $this->featureExtractor = $featureExtractor ?? new CitaFeatureExtractor();
    }

    public function execute(string $entidad, ?string $desde = null, ?string $hasta = null, ?string $estado = null): array
    {
        $tz = new DateTimeZone('America/La_Paz');
        $start = null;
        $end = null;

        if ($desde !== null && $hasta !== null) {
            $start = DateTimeImmutable::createFromFormat('!Y-m-d', $desde, $tz);
            $end = DateTimeImmutable::createFromFormat('!Y-m-d', $hasta, $tz)->setTime(23, 59, 59, 999999);
        }

        if ($entidad === 'citas') {
            return $this->generateCitasReport($start, $end, $estado, $tz);
        }

        if ($entidad === 'insumos') {
            return $this->generateInsumosReport($start, $end, $tz);
        }

        throw new InvalidArgumentException("Entidad de reporte no soportada: {$entidad}");
    }

    private function generateCitasReport(?DateTimeImmutable $start, ?DateTimeImmutable $end, ?string $estado, DateTimeZone $tz): array
    {
        $columnas = [
            ['clave' => 'citaId', 'etiqueta' => 'ID Cita', 'tipo' => 'TEXTO'],
            ['clave' => 'pacienteId', 'etiqueta' => 'ID Paciente', 'tipo' => 'TEXTO'],
            ['clave' => 'odontologoId', 'etiqueta' => 'ID Odontólogo', 'tipo' => 'TEXTO'],
            ['clave' => 'consultorioId', 'etiqueta' => 'ID Consultorio', 'tipo' => 'TEXTO'],
            ['clave' => 'fechaHora', 'etiqueta' => 'Fecha y Hora', 'tipo' => 'FECHA_HORA'],
            ['clave' => 'duracionMin', 'etiqueta' => 'Duración (min)', 'tipo' => 'NUMERO'],
            ['clave' => 'tipoTratamiento', 'etiqueta' => 'Tipo Tratamiento', 'tipo' => 'TEXTO'],
            ['clave' => 'estado', 'etiqueta' => 'Estado', 'tipo' => 'TEXTO'],
            ['clave' => 'reservadaEn', 'etiqueta' => 'Reservada En', 'tipo' => 'FECHA_HORA'],
            ['clave' => 'probabilidadNoShow', 'etiqueta' => 'Probabilidad No-Show', 'tipo' => 'NUMERO'],
            ['clave' => 'nivelRiesgo', 'etiqueta' => 'Nivel de Riesgo', 'tipo' => 'TEXTO'],
        ];

        $allCitas = $this->citaRepository->findAll();
        $filteredCitas = [];

        foreach ($allCitas as $cita) {
            // Filtro por estado
            if ($estado !== null && $cita->estado !== $estado) {
                continue;
            }

            // Filtro por período
            if ($start !== null && $end !== null) {
                if (empty($cita->fechaHora)) {
                    continue;
                }

                try {
                    $dt = (new DateTimeImmutable($cita->fechaHora, $tz))->setTimezone($tz);
                    if ($dt < $start || $dt > $end) {
                        continue;
                    }
                } catch (Throwable) {
                    continue;
                }
            }

            $filteredCitas[] = $cita;
        }

        // Enriquecimiento con Machine Learning (No-Show)
        $batchPayload = [];
        foreach ($filteredCitas as $c) {
            $extracted = $this->featureExtractor->extract($c, $allCitas);
            $batchPayload[] = $extracted['payload'];
        }

        $predictionsByCitaId = [];
        try {
            $health = $this->mlService->checkHealth();
            if (($health['status'] ?? '') === 'UP' && ! empty($batchPayload)) {
                $batchResult = $this->mlService->predictNoShowBatch($batchPayload);
                foreach ($batchResult['predicciones'] ?? [] as $pred) {
                    $cid = $pred['cita_id'] ?? null;
                    if ($cid) {
                        $predictionsByCitaId[$cid] = $pred;
                    }
                }
            }
        } catch (Throwable) {
            // Resiliencia: si el sidecar no está disponible, los riesgos quedan en null (nunca inventados)
        }

        $filas = [];
        foreach ($filteredCitas as $c) {
            $pred = $predictionsByCitaId[$c->id] ?? [];
            $prob = isset($pred['probabilidadNoShow'])
                ? (float) $pred['probabilidadNoShow']
                : (isset($pred['probabilidad_inasistencia']) ? (float) $pred['probabilidad_inasistencia'] : null);
            $risk = $pred['nivelRiesgo'] ?? $pred['nivel_riesgo'] ?? null;

            $filas[] = [
                'citaId' => (string) $c->id,
                'pacienteId' => (string) $c->pacienteId,
                'odontologoId' => (string) $c->odontologoId,
                'consultorioId' => (string) $c->consultorioId,
                'fechaHora' => (string) $c->fechaHora,
                'duracionMin' => $c->duracionEstimadaMin !== null ? (int) $c->duracionEstimadaMin : null,
                'tipoTratamiento' => $c->tipoTratamiento,
                'estado' => (string) $c->estado,
                'reservadaEn' => $c->reservadaEn,
                'probabilidadNoShow' => $prob,
                'nivelRiesgo' => $risk,
            ];
        }

        return [
            'entidad' => 'citas',
            'columnas' => $columnas,
            'filas' => $filas,
            'total' => count($filas),
        ];
    }

    private function generateInsumosReport(?DateTimeImmutable $start, ?DateTimeImmutable $end, DateTimeZone $tz): array
    {
        $columnas = [
            ['clave' => 'insumoId', 'etiqueta' => 'ID Insumo', 'tipo' => 'TEXTO'],
            ['clave' => 'nombre', 'etiqueta' => 'Nombre', 'tipo' => 'TEXTO'],
            ['clave' => 'unidad', 'etiqueta' => 'Unidad de Medida', 'tipo' => 'TEXTO'],
            ['clave' => 'stockActual', 'etiqueta' => 'Stock Actual', 'tipo' => 'NUMERO'],
            ['clave' => 'stockMinimo', 'etiqueta' => 'Stock Mínimo', 'tipo' => 'NUMERO'],
            ['clave' => 'bajoMinimo', 'etiqueta' => 'Bajo Mínimo', 'tipo' => 'BOOLEANO'],
            ['clave' => 'salidasPeriodo', 'etiqueta' => 'Salidas del Período', 'tipo' => 'NUMERO'],
        ];

        $allInsumos = $this->insumoRepository->findAll();
        $allMovimientos = $this->movimientoStockRepository->findAll();

        $salidasPorInsumo = [];
        foreach ($allMovimientos as $mov) {
            if ($mov->tipoMovimiento !== MovimientoStock::TIPO_SALIDA_PROCEDIMIENTO) {
                continue;
            }

            if ($start !== null && $end !== null) {
                if (empty($mov->fechaHora)) {
                    continue;
                }

                try {
                    $dtMov = (new DateTimeImmutable($mov->fechaHora, $tz))->setTimezone($tz);
                    if ($dtMov < $start || $dtMov > $end) {
                        continue;
                    }
                } catch (Throwable) {
                    continue;
                }
            }

            $iid = $mov->insumoId;
            $salidasPorInsumo[$iid] = ($salidasPorInsumo[$iid] ?? 0) + (int) $mov->cantidad;
        }

        $filas = [];
        foreach ($allInsumos as $insumo) {
            $stockActual = (int) $insumo->stockActual;
            $stockMinimo = (int) $insumo->stockMinimo;
            $salidas = (int) ($salidasPorInsumo[$insumo->id] ?? 0);
            $bajoMinimo = $stockActual <= $stockMinimo;

            $filas[] = [
                'insumoId' => (string) $insumo->id,
                'nombre' => (string) $insumo->nombre,
                'unidad' => $insumo->unidadMedida ?? null,
                'stockActual' => $stockActual,
                'stockMinimo' => $stockMinimo,
                'bajoMinimo' => (bool) $bajoMinimo,
                'salidasPeriodo' => $salidas,
            ];
        }

        usort($filas, function ($a, $b) {
            if ($a['bajoMinimo'] !== $b['bajoMinimo']) {
                return $b['bajoMinimo'] <=> $a['bajoMinimo'];
            }
            if ($b['salidasPeriodo'] !== $a['salidasPeriodo']) {
                return $b['salidasPeriodo'] <=> $a['salidasPeriodo'];
            }

            return strcmp($a['nombre'], $b['nombre']);
        });

        return [
            'entidad' => 'insumos',
            'columnas' => $columnas,
            'filas' => $filas,
            'total' => count($filas),
        ];
    }
}
