<?php

namespace App\Domain\Services;

interface MlInferenceServiceInterface
{
    /**
     * Predice la probabilidad de inasistencia (No-Show) usando Random Forest.
     *
     * @param array{
     *     edad: int,
     *     dias_anticipacion: int,
     *     cancelaciones_previas: int,
     *     dia_semana: int,
     *     hora_cita: int,
     *     tipo_tratamiento_riesgo: int
     * } $data
     */
    public function predictNoShow(array $data): array;

    /**
     * Segmenta el perfil de un paciente usando K-Means Clustering.
     *
     * @param array{
     *     total_citas: int,
     *     tasa_asistencia: float,
     *     dias_promedio_entre_citas: float,
     *     gasto_total_usd: float
     * } $data
     */
    public function clusterPatient(array $data): array;

    /**
     * Clasifica un insumo de inventario según rotación y criticidad (K-Means / ABC).
     *
     * @param array{
     *     consumo_mensual: float,
     *     costo_unitario: float,
     *     dias_reabastecimiento: int
     * } $data
     */
    public function clusterStock(array $data): array;

    /**
     * Verifica el estado del micro-servicio de Machine Learning.
     */
    public function checkHealth(): array;
}
