<?php

namespace App\Domain\Services;

interface MlInferenceServiceInterface
{
    /**
     * Predice la probabilidad de inasistencia (No-Show) usando Random Forest.
     *
     * @param array $data
     * @return array
     */
    public function predictNoShow(array $data): array;

    /**
     * Predice en lote la probabilidad de inasistencia (No-Show) para una lista de citas.
     *
     * @param array<int, array> $citas
     * @return array
     */
    public function predictNoShowBatch(array $citas): array;

    /**
     * Segmenta el perfil de un paciente usando K-Means Clustering.
     *
     * @param array $data
     * @return array
     */
    public function clusterPatient(array $data): array;

    /**
     * Clasifica un insumo de inventario según rotación y criticidad (K-Means / ABC).
     *
     * @param array $data
     * @return array
     */
    public function clusterStock(array $data): array;

    /**
     * Obtiene los clusters completos (pacientes e insumos) con centroides, distribución y asignaciones.
     *
     * @param string|null $callerBearerToken Token Bearer del usuario autenticado para consultar MS1 si aplica
     * @return array
     */
    public function getClusters(?string $callerBearerToken = null): array;

    /**
     * Verifica el estado del micro-servicio de Machine Learning.
     *
     * @return array
     */
    public function checkHealth(): array;

    /**
     * Obtiene el Model Card con métricas de evaluación offline y metadata de modelos (S9).
     *
     * @return array
     */
    public function getModelCard(): array;
}
