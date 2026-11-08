<?php

namespace App\Infrastructure\Services;

use App\Domain\Entities\Cita;
use App\Domain\Repositories\CitaRepositoryInterface;
use App\Domain\Repositories\InsumoRepositoryInterface;
use App\Domain\Services\MlInferenceServiceInterface;
use Illuminate\Support\Facades\Http;
use Throwable;

class FastApiMlService implements MlInferenceServiceInterface
{
    private string $baseUrl;

    public function __construct(
        private ?CitaRepositoryInterface $citaRepository = null,
        private ?InsumoRepositoryInterface $insumoRepository = null
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

    public function getClusters(): array
    {
        try {
            // Recolectar métricas de pacientes desde el repositorio de citas
            $pacientesData = [];
            if ($this->citaRepository !== null) {
                $citas = $this->citaRepository->findAll();
                $stats = [];
                foreach ($citas as $c) {
                    $pid = $c->pacienteId;
                    if (! isset($stats[$pid])) {
                        $stats[$pid] = ['total' => 0, 'asistidas' => 0];
                    }
                    $stats[$pid]['total']++;
                    if ($c->estado === Cita::ESTADO_COMPLETADA) {
                        $stats[$pid]['asistidas']++;
                    }
                }
                foreach ($stats as $pid => $s) {
                    $total = $s['total'];
                    $tasa = $total > 0 ? round($s['asistidas'] / $total, 2) : 1.0;
                    $pacientesData[] = [
                        'paciente_id' => (string) $pid,
                        'total_citas' => (int) $total,
                        'tasa_asistencia' => (float) $tasa,
                        'dias_promedio_entre_citas' => 90.0,
                        'gasto_total_estimado' => (float) ($total * 60.0),
                    ];
                }
            }

            // Recolectar datos de insumos desde el repositorio de inventario
            $insumosData = [];
            if ($this->insumoRepository !== null) {
                $insumos = $this->insumoRepository->findAll();
                foreach ($insumos as $ins) {
                    $insumosData[] = [
                        'insumo_id' => (string) $ins->id,
                        'consumo_mensual' => (float) max(1.0, $ins->stockMinimo * 2),
                        'costo_unitario' => (float) max(1.0, $ins->precioUnitario ?? 15.0),
                        'dias_reabastecimiento' => 7,
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

            return $response->json();
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
}
