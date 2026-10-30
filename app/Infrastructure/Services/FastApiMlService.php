<?php

namespace App\Infrastructure\Services;

use App\Domain\Services\MlInferenceServiceInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FastApiMlService implements MlInferenceServiceInterface
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim(env('ML_SERVICE_URL', 'http://127.0.0.1:8005'), '/');
    }

    public function predictNoShow(array $data): array
    {
        $response = Http::timeout(4)->post("{$this->baseUrl}/predict/no-show", [
            'edad' => (int)$data['edad'],
            'dias_anticipacion' => (int)$data['dias_anticipacion'],
            'cancelaciones_previas' => (int)$data['cancelaciones_previas'],
            'dia_semana' => (int)$data['dia_semana'],
            'hora_cita' => (int)$data['hora_cita'],
            'tipo_tratamiento_riesgo' => (int)$data['tipo_tratamiento_riesgo'],
        ]);

        if ($response->failed()) {
            throw new RuntimeException("Error en inferencia de No-Show: " . $response->body());
        }

        return $response->json();
    }

    public function clusterPatient(array $data): array
    {
        $response = Http::timeout(4)->post("{$this->baseUrl}/cluster/patient", [
            'total_citas' => (int)$data['total_citas'],
            'tasa_asistencia' => (float)$data['tasa_asistencia'],
            'dias_promedio_entre_citas' => (float)$data['dias_promedio_entre_citas'],
            'gasto_total_usd' => (float)$data['gasto_total_usd'],
        ]);

        if ($response->failed()) {
            throw new RuntimeException("Error en clustering de paciente: " . $response->body());
        }

        return $response->json();
    }

    public function clusterStock(array $data): array
    {
        $response = Http::timeout(4)->post("{$this->baseUrl}/cluster/stock", [
            'consumo_mensual' => (float)$data['consumo_mensual'],
            'costo_unitario' => (float)$data['costo_unitario'],
            'dias_reabastecimiento' => (int)$data['dias_reabastecimiento'],
        ]);

        if ($response->failed()) {
            throw new RuntimeException("Error en clustering de insumo: " . $response->body());
        }

        return $response->json();
    }

    public function checkHealth(): array
    {
        try {
            $response = Http::timeout(2)->get("{$this->baseUrl}/health");
            if ($response->successful()) {
                return $response->json();
            }
        } catch (\Throwable $e) {
            // Handled gracefully below
        }

        return [
            'status' => 'DOWN',
            'service' => 'MS3 - Machine Learning Sidecar',
            'error' => 'No se pudo conectar con el servicio FastAPI en ' . $this->baseUrl
        ];
    }
}
