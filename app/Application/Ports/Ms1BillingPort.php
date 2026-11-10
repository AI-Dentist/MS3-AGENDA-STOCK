<?php

namespace App\Application\Ports;

use App\Application\DTOs\PatientSpendingResult;

interface Ms1BillingPort
{
    /**
     * Consulta el gasto pagado real de un paciente en MS1 ERP vía GraphQL.
     * Si no se proporciona token, o si falla (401, 403, timeout, etc.), retorna fallback explícito estimado.
     * Un historial exitoso vacío retorna cero observado (0.0 Bs), no fallback estimado.
     *
     * @param string $pacienteId
     * @param float $fallbackEstimado
     * @param string|null $callerBearerToken
     * @return PatientSpendingResult
     */
    public function getPatientSpending(string $pacienteId, float $fallbackEstimado, ?string $callerBearerToken): PatientSpendingResult;
}
