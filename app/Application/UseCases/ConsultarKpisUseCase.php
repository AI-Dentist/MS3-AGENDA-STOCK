<?php

namespace App\Application\UseCases;

use App\Domain\Entities\Cita;
use App\Domain\Entities\MovimientoStock;
use App\Domain\Repositories\CitaRepositoryInterface;
use App\Domain\Repositories\InsumoRepositoryInterface;
use App\Domain\Repositories\MovimientoStockRepositoryInterface;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

class ConsultarKpisUseCase
{
    public function __construct(
        private CitaRepositoryInterface $citaRepository,
        private InsumoRepositoryInterface $insumoRepository,
        private MovimientoStockRepositoryInterface $movimientoStockRepository
    ) {}

    public function execute(string $desde, string $hasta): array
    {
        $tz = new DateTimeZone('America/La_Paz');
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $desde, $tz);
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $hasta, $tz)->setTime(23, 59, 59, 999999);

        // 1. Filtrar citas en el período (inclusive, en America/La_Paz)
        $allCitas = $this->citaRepository->findAll();
        $citasEnPeriodo = [];

        foreach ($allCitas as $cita) {
            if (empty($cita->fechaHora)) {
                continue;
            }

            try {
                $dt = new DateTimeImmutable($cita->fechaHora, $tz);
                $dtLaPaz = $dt->setTimezone($tz);
            } catch (Throwable) {
                continue;
            }

            if ($dtLaPaz >= $start && $dtLaPaz <= $end) {
                $citasEnPeriodo[] = [
                    'entity' => $cita,
                    'dt' => $dtLaPaz,
                ];
            }
        }

        // 2. KPI 3: Pacientes más frecuentes (ranking top 10)
        $byPaciente = [];
        foreach ($citasEnPeriodo as $item) {
            /** @var Cita $c */
            $c = $item['entity'];
            $pid = $c->pacienteId;
            if (! isset($byPaciente[$pid])) {
                $byPaciente[$pid] = [
                    'pacienteId' => $pid,
                    'totalCitas' => 0,
                    'completadas' => 0,
                ];
            }
            $byPaciente[$pid]['totalCitas']++;
            if ($c->estado === Cita::ESTADO_COMPLETADA) {
                $byPaciente[$pid]['completadas']++;
            }
        }

        usort($byPaciente, function ($a, $b) {
            if ($b['totalCitas'] !== $a['totalCitas']) {
                return $b['totalCitas'] <=> $a['totalCitas'];
            }
            if ($b['completadas'] !== $a['completadas']) {
                return $b['completadas'] <=> $a['completadas'];
            }

            return strcmp($a['pacienteId'], $b['pacienteId']);
        });

        $pacientesFrecuentes = array_slice(array_values($byPaciente), 0, 10);

        // 3. KPI 5: Tasa de inasistencia observada
        $citasEvaluadas = 0;
        $noAsistio = 0;
        $detalleInasistencia = [];

        foreach ($citasEnPeriodo as $item) {
            /** @var Cita $c */
            $c = $item['entity'];
            if ($c->estado === Cita::ESTADO_COMPLETADA) {
                $citasEvaluadas++;
            } elseif ($c->estado === Cita::ESTADO_NO_ASISTIO) {
                $citasEvaluadas++;
                $noAsistio++;
                $detalleInasistencia[] = [
                    'citaId' => (string) $c->id,
                    'pacienteId' => (string) $c->pacienteId,
                    'odontologoId' => (string) $c->odontologoId,
                    'fechaHora' => (string) $c->fechaHora,
                ];
            }
        }

        $porcentajeInasistencia = $citasEvaluadas > 0
            ? round(($noAsistio / $citasEvaluadas) * 100, 2)
            : 0.0;

        // 4. KPI 6: Horarios pico (por día-hora ISO 1-7 y top 5)
        $buckets = [];
        foreach ($citasEnPeriodo as $item) {
            /** @var DateTimeImmutable $dtLaPaz */
            $dtLaPaz = $item['dt'];
            $diaSemana = (int) $dtLaPaz->format('N'); // 1=Lunes .. 7=Domingo (ISO-8601)
            $hora = (int) $dtLaPaz->format('G');      // 0 .. 23
            $key = "{$diaSemana}_{$hora}";

            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'diaSemana' => $diaSemana,
                    'hora' => $hora,
                    'citas' => 0,
                ];
            }
            $buckets[$key]['citas']++;
        }

        $porDiaHora = array_values($buckets);
        usort($porDiaHora, function ($a, $b) {
            if ($a['diaSemana'] !== $b['diaSemana']) {
                return $a['diaSemana'] <=> $b['diaSemana'];
            }

            return $a['hora'] <=> $b['hora'];
        });

        $topBuckets = $porDiaHora;
        usort($topBuckets, function ($a, $b) {
            if ($b['citas'] !== $a['citas']) {
                return $b['citas'] <=> $a['citas'];
            }
            if ($a['diaSemana'] !== $b['diaSemana']) {
                return $a['diaSemana'] <=> $b['diaSemana'];
            }

            return $a['hora'] <=> $b['hora'];
        });
        $top = array_slice($topBuckets, 0, 5);

        // 5. KPI 7: Insumos críticos (stock actual vs mínimo y salidas en el período)
        $allInsumos = $this->insumoRepository->findAll();
        $allMovimientos = $this->movimientoStockRepository->findAll();

        $salidasPorInsumo = [];
        foreach ($allMovimientos as $mov) {
            if ($mov->tipoMovimiento !== MovimientoStock::TIPO_SALIDA_PROCEDIMIENTO) {
                continue;
            }
            if (empty($mov->fechaHora)) {
                continue;
            }

            try {
                $dtMov = (new DateTimeImmutable($mov->fechaHora, $tz))->setTimezone($tz);
            } catch (Throwable) {
                continue;
            }

            if ($dtMov >= $start && $dtMov <= $end) {
                $iid = $mov->insumoId;
                $salidasPorInsumo[$iid] = ($salidasPorInsumo[$iid] ?? 0) + (int) $mov->cantidad;
            }
        }

        $insumosCriticos = [];
        foreach ($allInsumos as $insumo) {
            $stockActual = (int) $insumo->stockActual;
            $stockMinimo = (int) $insumo->stockMinimo;
            $salidas = (int) ($salidasPorInsumo[$insumo->id] ?? 0);
            $bajoMinimo = $stockActual <= $stockMinimo;

            $insumosCriticos[] = [
                'insumoId' => (string) $insumo->id,
                'nombre' => (string) $insumo->nombre,
                'stockActual' => $stockActual,
                'stockMinimo' => $stockMinimo,
                'bajoMinimo' => (bool) $bajoMinimo,
                'salidasPeriodo' => $salidas,
            ];
        }

        usort($insumosCriticos, function ($a, $b) {
            if ($a['bajoMinimo'] !== $b['bajoMinimo']) {
                return $b['bajoMinimo'] <=> $a['bajoMinimo'];
            }
            if ($b['salidasPeriodo'] !== $a['salidasPeriodo']) {
                return $b['salidasPeriodo'] <=> $a['salidasPeriodo'];
            }

            return strcmp($a['nombre'], $b['nombre']);
        });

        // 6. KPI 10: Productividad por odontólogo
        $byOdontologo = [];
        foreach ($citasEnPeriodo as $item) {
            /** @var Cita $c */
            $c = $item['entity'];
            $oid = $c->odontologoId;
            if (! isset($byOdontologo[$oid])) {
                $byOdontologo[$oid] = [
                    'odontologoId' => $oid,
                    'citasTotales' => 0,
                    'citasCompletadas' => 0,
                ];
            }
            $byOdontologo[$oid]['citasTotales']++;
            if ($c->estado === Cita::ESTADO_COMPLETADA) {
                $byOdontologo[$oid]['citasCompletadas']++;
            }
        }

        $productividadOdontologos = [];
        foreach ($byOdontologo as $oid => $data) {
            $totales = (int) $data['citasTotales'];
            $completadas = (int) $data['citasCompletadas'];
            $tasa = $totales > 0 ? round(($completadas / $totales) * 100, 2) : 0.0;

            $productividadOdontologos[] = [
                'odontologoId' => (string) $oid,
                'citasTotales' => $totales,
                'citasCompletadas' => $completadas,
                'tasaCompletadas' => (float) $tasa,
            ];
        }

        usort($productividadOdontologos, function ($a, $b) {
            if ($b['citasCompletadas'] !== $a['citasCompletadas']) {
                return $b['citasCompletadas'] <=> $a['citasCompletadas'];
            }
            if ($b['citasTotales'] !== $a['citasTotales']) {
                return $b['citasTotales'] <=> $a['citasTotales'];
            }

            return strcmp($a['odontologoId'], $b['odontologoId']);
        });

        return [
            'periodo' => [
                'desde' => $desde,
                'hasta' => $hasta,
            ],
            'pacientesFrecuentes' => $pacientesFrecuentes,
            'inasistencia' => [
                'citasEvaluadas' => $citasEvaluadas,
                'noAsistio' => $noAsistio,
                'porcentaje' => (float) $porcentajeInasistencia,
                'detalle' => $detalleInasistencia,
            ],
            'horariosPico' => [
                'porDiaHora' => $porDiaHora,
                'top' => $top,
            ],
            'insumosCriticos' => $insumosCriticos,
            'productividadOdontologos' => $productividadOdontologos,
        ];
    }
}
