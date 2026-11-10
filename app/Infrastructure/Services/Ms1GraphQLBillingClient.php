<?php

namespace App\Infrastructure\Services;

use App\Application\DTOs\PatientSpendingResult;
use App\Application\Ports\Ms1BillingPort;
use Illuminate\Support\Facades\Http;
use Throwable;

class Ms1GraphQLBillingClient implements Ms1BillingPort
{
    private const GRAPHQL_QUERY = <<<'GRAPHQL'
query ObtenerGastoPagadoPaciente($pacienteId: UUID!) {
  obtenerGastoPagadoPaciente(pacienteId: $pacienteId) {
    pacienteId
    moneda
    completo
    totalPagadoBs
  }
}
GRAPHQL;

    public function getPatientSpending(string $pacienteId, float $fallbackEstimado, ?string $callerBearerToken): PatientSpendingResult
    {
        // 1. Sin token portador de usuario autenticado -> No realizar llamada anónima ni bypass de seguridad
        if ($callerBearerToken === null || trim($callerBearerToken) === '') {
            return PatientSpendingResult::fallback($pacienteId, $fallbackEstimado);
        }

        // 2. Validación de UUID de paciente para el schema GraphQL de MS1
        $trimmedId = trim($pacienteId);
        if (! preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $trimmedId)) {
            return PatientSpendingResult::fallback($pacienteId, $fallbackEstimado);
        }

        $graphqlUrl = config('services.ms1.graphql_url', 'http://127.0.0.1:8081/graphql');
        $timeoutMs = (int) config('services.ms1.timeout_ms', 2000);
        $timeoutSec = max(1, (int) ceil($timeoutMs / 1000));

        try {
            $response = Http::withToken(trim($callerBearerToken))
                ->timeout($timeoutSec)
                ->acceptJson()
                ->asJson()
                ->post($graphqlUrl, [
                    'query' => self::GRAPHQL_QUERY,
                    'variables' => [
                        'pacienteId' => $trimmedId,
                    ],
                ]);
        } catch (Throwable $e) {
            // No registrar tokens, detalles de conexión o datos privados
            return PatientSpendingResult::fallback($pacienteId, $fallbackEstimado);
        }

        if (! $response->successful()) {
            return PatientSpendingResult::fallback($pacienteId, $fallbackEstimado);
        }

        $body = $response->json();
        if (! is_array($body)) {
            return PatientSpendingResult::fallback($pacienteId, $fallbackEstimado);
        }

        if (array_key_exists('errors', $body)) {
            if (! is_array($body['errors']) || ! empty($body['errors'])) {
                return PatientSpendingResult::fallback($pacienteId, $fallbackEstimado);
            }
        }

        if (! isset($body['data']['obtenerGastoPagadoPaciente']) || ! is_array($body['data']['obtenerGastoPagadoPaciente'])) {
            return PatientSpendingResult::fallback($pacienteId, $fallbackEstimado);
        }

        $data = $body['data']['obtenerGastoPagadoPaciente'];

        // 3. Validación estricta de identidad: pacienteId retornado debe coincidir exactamente con el consultado
        $retrievedPatientId = $data['pacienteId'] ?? null;
        if (empty($retrievedPatientId) || ! is_string($retrievedPatientId) || strcasecmp(trim($retrievedPatientId), $trimmedId) !== 0) {
            return PatientSpendingResult::fallback($pacienteId, $fallbackEstimado);
        }

        // 4. Validación de moneda: debe ser estrictamente BOB
        $moneda = $data['moneda'] ?? null;
        if ($moneda !== 'BOB') {
            return PatientSpendingResult::fallback($pacienteId, $fallbackEstimado);
        }

        // 5. Validación de completitud: completo debe ser estrictamente boolean true
        // completo === false (ej. pagos con demo QR no verificados) o ausente -> fallback estimado
        if (($data['completo'] ?? null) !== true) {
            return PatientSpendingResult::fallback($pacienteId, $fallbackEstimado);
        }

        // 6. Validación de monto pagado: debe estar presente, ser numérico, finito y >= 0
        if (! isset($data['totalPagadoBs']) || ! is_numeric($data['totalPagadoBs'])) {
            return PatientSpendingResult::fallback($pacienteId, $fallbackEstimado);
        }

        $montoFloat = (float) $data['totalPagadoBs'];
        if (! is_finite($montoFloat) || $montoFloat < 0) {
            return PatientSpendingResult::fallback($pacienteId, $fallbackEstimado);
        }

        // 7. Éxito: monto verificado (si es 0.0 -> cero observado; si > 0 -> gasto real verificado)
        return PatientSpendingResult::real($pacienteId, $montoFloat, $fallbackEstimado, 'BOB');
    }
}
