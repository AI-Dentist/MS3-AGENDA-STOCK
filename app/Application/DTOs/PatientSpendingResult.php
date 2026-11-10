<?php

namespace App\Application\DTOs;

class PatientSpendingResult
{
    public function __construct(
        public readonly string $pacienteId,
        public readonly ?float $gastoTotalReal,
        public readonly float $gastoTotalEstimado,
        public readonly string $gastoMoneda,
        public readonly string $gastoOrigen, // 'real_ms1_pagado' | 'estimado_fallback'
        public readonly bool $gastoRealObservado, // true if successfully verified from MS1
        public readonly bool $esEstimado // true if fallback
    ) {}

    public static function real(string $pacienteId, float $totalPagado, float $estimado, string $moneda = 'BOB'): self
    {
        return new self(
            pacienteId: $pacienteId,
            gastoTotalReal: round($totalPagado, 2),
            gastoTotalEstimado: round($estimado, 2),
            gastoMoneda: $moneda,
            gastoOrigen: 'real_ms1_pagado',
            gastoRealObservado: true,
            esEstimado: false
        );
    }

    public static function fallback(string $pacienteId, float $estimado, string $moneda = 'BOB'): self
    {
        return new self(
            pacienteId: $pacienteId,
            gastoTotalReal: null,
            gastoTotalEstimado: round($estimado, 2),
            gastoMoneda: $moneda,
            gastoOrigen: 'estimado_fallback',
            gastoRealObservado: false,
            esEstimado: true
        );
    }
}
