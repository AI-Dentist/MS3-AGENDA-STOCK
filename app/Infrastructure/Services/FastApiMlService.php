<?php

namespace App\Infrastructure\Services;

use App\Application\DTOs\PatientSpendingResult;
use App\Application\Ports\Ms1BillingPort;
use App\Domain\Entities\Cita;
use App\Domain\Repositories\CitaRepositoryInterface;
use App\Domain\Repositories\InsumoRepositoryInterface;
use App\Domain\Repositories\MovimientoStockRepositoryInterface;
use App\Domain\Services\MlInferenceServiceInterface;
use Illuminate\Support\Facades\Http;
use DateInterval;
use DateTimeImmutable;
use Throwable;

class FastApiMlService implements MlInferenceServiceInterface
{
    private string $baseUrl;

    public function __construct(
        private ?CitaRepositoryInterface $citaRepository = null,
        private ?InsumoRepositoryInterface $insumoRepository = null,
        private ?MovimientoStockRepositoryInterface $movimientoStockRepository = null,
        private ?Ms1BillingPort $ms1BillingClient = null
    ) {
        $this->baseUrl = rtrim(env('ML_SERVICE_URL', 'http://127.0.0.1:8005'), '/');
    }

    public function predictNoShow(array $data): array
    {
        try {
            $response = Http::timeout(4)->post("{$this->baseUrl}/predict/no-show", [
                'edad' => (int) ($data['edad'] ?? 35),
                'dias_anticipacion' => (int) ($data['dias_anticipacion'] ?? 7),
                'cancelaciones_previas' => (int) ($data['cancelaciones_previas'] ?? 0),
                'dia_semana' => (int) ($data['dia_semana'] ?? 1),
                'hora_cita' => (int) ($data['hora_cita'] ?? 10),
                'tipo_tratamiento_riesgo' => (int) ($data['tipo_tratamiento_riesgo'] ?? 1),
                'duracion_minutos' => isset($data['duracion_minutos']) ? (int) $data['duracion_minutos'] : null,
                'banda_horaria' => isset($data['banda_horaria']) ? (int) $data['banda_horaria'] : null,
            ]);

            if ($response->failed()) {
                throw new MlSidecarUnavailableException('Error en inferencia de No-Show: '.$response->body());
            }

            return $response->json();
        } catch (MlSidecarUnavailableException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new MlSidecarUnavailableException('No se pudo conectar con el servicio FastAPI de No-Show: '.$e->getMessage(), 0, $e);
        }
    }

    public function predictNoShowBatch(array $citas): array
    {
        try {
            $response = Http::timeout(5)->post("{$this->baseUrl}/predict/no-show/batch", [
                'citas' => $citas,
            ]);

            if ($response->failed()) {
                throw new MlSidecarUnavailableException('Error en inferencia batch de No-Show: '.$response->body());
            }

            return $response->json();
        } catch (MlSidecarUnavailableException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new MlSidecarUnavailableException('No se pudo conectar con el servicio FastAPI en batch: '.$e->getMessage(), 0, $e);
        }
    }

    public function clusterPatient(array $data): array
    {
        try {
            $response = Http::timeout(4)->post("{$this->baseUrl}/cluster/patient", [
                'paciente_id' => (string) ($data['paciente_id'] ?? 'pac-unknown'),
                'total_citas' => (int) ($data['total_citas'] ?? 0),
                'tasa_asistencia' => (float) ($data['tasa_asistencia'] ?? 1.0),
                'dias_promedio_entre_citas' => (float) ($data['dias_promedio_entre_citas'] ?? 90.0),
                'gasto_total_estimado' => (float) ($data['gasto_total_estimado'] ?? $data['gasto_total_usd'] ?? 150.0),
            ]);

            if ($response->failed()) {
                throw new MlSidecarUnavailableException('Error en clustering de paciente: '.$response->body());
            }

            return $response->json();
        } catch (MlSidecarUnavailableException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new MlSidecarUnavailableException('No se pudo conectar con el servicio FastAPI de clustering: '.$e->getMessage(), 0, $e);
        }
    }

    public function clusterStock(array $data): array
    {
        try {
            $response = Http::timeout(4)->post("{$this->baseUrl}/cluster/stock", [
                'insumo_id' => (string) ($data['insumo_id'] ?? 'ins-unknown'),
                'consumo_mensual' => (float) ($data['consumo_mensual'] ?? 10.0),
                'costo_unitario' => (float) ($data['costo_unitario'] ?? 15.0),
                'dias_reabastecimiento' => (int) ($data['dias_reabastecimiento'] ?? 7),
            ]);

            if ($response->failed()) {
                throw new MlSidecarUnavailableException('Error en clustering de insumo: '.$response->body());
            }

            return $response->json();
        } catch (MlSidecarUnavailableException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new MlSidecarUnavailableException('No se pudo conectar con el servicio FastAPI de stock: '.$e->getMessage(), 0, $e);
        }
    }

    public function getClusters(?string $callerBearerToken = null): array
    {
        try {
            // 1. Recolectar métricas de pacientes desde el repositorio de citas (S8) y gasto MS1 (S2)
            $pacientesData = [];
            if ($this->citaRepository !== null) {
                $citas = $this->citaRepository->findAll();
                $citasByPaciente = [];
                foreach ($citas as $c) {
                    $citasByPaciente[$c->pacienteId][] = $c;
                }

                foreach ($citasByPaciente as $pid => $pCitas) {
                    $total = count($pCitas);
                    $asistidas = 0;
                    $fechas = [];
                    foreach ($pCitas as $c) {
                        if ($c->estado === Cita::ESTADO_COMPLETADA) {
                            $asistidas++;
                        }
                        if (! empty($c->fechaHora)) {
                            try {
                                $fechas[] = new DateTimeImmutable($c->fechaHora);
                            } catch (Throwable $e) {}
                        }
                    }

                    $tasa = $total > 0 ? round($asistidas / $total, 2) : 1.0;
                    $imputadas = [];
                    // gasto_total_estimado se preserva siempre como estimado si se conserva dicho campo
                    $estimadas = ['gasto_total_estimado'];

                    if (count($fechas) >= 2) {
                        usort($fechas, fn ($a, $b) => $a->getTimestamp() <=> $b->getTimestamp());
                        $totalDiffDays = 0;
                        for ($i = 1; $i < count($fechas); $i++) {
                            $totalDiffDays += (int) $fechas[$i - 1]->diff($fechas[$i])->days;
                        }
                        $diasPromedio = round($totalDiffDays / (count($fechas) - 1), 1);
                    } else {
                        $diasPromedio = 90.0;
                        $imputadas[] = 'dias_promedio_entre_citas';
                    }

                    $gastoEstimado = (float) ($total * 60.0);
                    $spending = $this->ms1BillingClient !== null
                        ? $this->ms1BillingClient->getPatientSpending((string) $pid, $gastoEstimado, $callerBearerToken)
                        : PatientSpendingResult::fallback((string) $pid, $gastoEstimado);

                    $pacientesData[] = [
                        'paciente_id' => (string) $pid,
                        'total_citas' => (int) $total,
                        'tasa_asistencia' => (float) $tasa,
                        'dias_promedio_entre_citas' => (float) $diasPromedio,
                        'gasto_total_estimado' => $gastoEstimado,
                        'gasto_total_real' => $spending->gastoTotalReal,
                        'gasto_moneda' => $spending->gastoMoneda,
                        'gasto_origen' => $spending->gastoOrigen,
                        'gasto_real_observado' => $spending->gastoRealObservado,
                        'featuresImputadas' => $imputadas,
                        'featuresEstimadas' => $estimadas,
                    ];
                }
            }

            // 2. Recolectar datos de insumos desde inventario y movimientos_stock (S8)
            $insumosData = [];
            if ($this->insumoRepository !== null) {
                $insumos = $this->insumoRepository->findAll();
                $allMovimientos = $this->movimientoStockRepository !== null
                    ? $this->movimientoStockRepository->findAll()
                    : [];

                $movimientosByInsumo = [];
                foreach ($allMovimientos as $m) {
                    $movimientosByInsumo[$m->insumoId][] = $m;
                }

                $now = new DateTimeImmutable();
                $hace30Dias = $now->sub(new DateInterval('P30D'));

                foreach ($insumos as $ins) {
                    $insImputadas = [];
                    $insEstimadas = [];

                    $movs = $movimientosByInsumo[$ins->id] ?? [];

                    // a. consumo_mensual = suma de SALIDA_PROCEDIMIENTO en ultimos 30 dias
                    $salidas30d = 0;
                    $tieneSalidas30d = false;
                    foreach ($movs as $m) {
                        $tipo = $m->tipoMovimiento ?? null;
                        if ($tipo === 'SALIDA_PROCEDIMIENTO' || $tipo === 'SALIDA') {
                            try {
                                $dtM = new DateTimeImmutable($m->fechaHora);
                                if ($dtM >= $hace30Dias) {
                                    $salidas30d += abs($m->cantidad);
                                    $tieneSalidas30d = true;
                                }
                            } catch (Throwable $e) {}
                        }
                    }

                    if ($tieneSalidas30d && $salidas30d > 0) {
                        $consumoMensual = (float) $salidas30d;
                    } else {
                        $consumoMensual = (float) max(1.0, $ins->stockMinimo * 2);
                        $insImputadas[] = 'consumo_mensual';
                    }

                    // b. costo_unitario: siempre imputado (Insumo no tiene precio unitario)
                    $costoUnitario = (float) max(1.0, $ins->precioUnitario ?? 15.0);
                    $insImputadas[] = 'costo_unitario';

                    // c. dias_reabastecimiento = media de dias entre ENTRADA_COMPRA cuando >= 2
                    $entradasFechas = [];
                    foreach ($movs as $m) {
                        $tipo = $m->tipoMovimiento ?? null;
                        if ($tipo === 'ENTRADA_COMPRA' || $tipo === 'ENTRADA') {
                            try {
                                $entradasFechas[] = new DateTimeImmutable($m->fechaHora);
                            } catch (Throwable $e) {}
                        }
                    }

                    if (count($entradasFechas) >= 2) {
                        usort($entradasFechas, fn ($a, $b) => $a->getTimestamp() <=> $b->getTimestamp());
                        $diffEntradas = 0;
                        for ($i = 1; $i < count($entradasFechas); $i++) {
                            $diffEntradas += (int) $entradasFechas[$i - 1]->diff($entradasFechas[$i])->days;
                        }
                        $diasReabastecimiento = max(1, (int) round($diffEntradas / (count($entradasFechas) - 1)));
                    } else {
                        $diasReabastecimiento = 7;
                        $insImputadas[] = 'dias_reabastecimiento';
                    }

                    $insumosData[] = [
                        'insumo_id' => (string) $ins->id,
                        'consumo_mensual' => $consumoMensual,
                        'costo_unitario' => $costoUnitario,
                        'dias_reabastecimiento' => $diasReabastecimiento,
                        'featuresImputadas' => $insImputadas,
                        'featuresEstimadas' => $insEstimadas,
                    ];
                }
            }

            $response = Http::timeout(5)->post("{$this->baseUrl}/batch-cluster", [
                'pacientes' => $pacientesData,
                'insumos' => $insumosData,
            ]);

            if ($response->failed()) {
                throw new MlSidecarUnavailableException('Error en batch-cluster: '.$response->body());
            }

            $result = $response->json();
            $result['datosEntrenamientoSinteticos'] = true;

            return $result;
        } catch (MlSidecarUnavailableException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new MlSidecarUnavailableException('No se pudo conectar con el servicio FastAPI de clusters: '.$e->getMessage(), 0, $e);
        }
    }

    public function checkHealth(): array
    {
        try {
            $response = Http::timeout(2)->get("{$this->baseUrl}/health");
            if ($response->successful()) {
                return $response->json();
            }
        } catch (Throwable $e) {
            // Handled gracefully below
        }

        return [
            'status' => 'DOWN',
            'service' => 'MS3 - Machine Learning Sidecar',
            'error' => 'No se pudo conectar con el servicio FastAPI en '.$this->baseUrl,
        ];
    }

    public function getModelCard(): array
    {
        try {
            $response = Http::timeout(4)->get("{$this->baseUrl}/model-card");
            if ($response->failed()) {
                throw new MlSidecarUnavailableException('Error al obtener model card: '.$response->body());
            }

            return $response->json();
        } catch (MlSidecarUnavailableException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new MlSidecarUnavailableException('No se pudo conectar con el servicio FastAPI de model card: '.$e->getMessage(), 0, $e);
        }
    }
}
