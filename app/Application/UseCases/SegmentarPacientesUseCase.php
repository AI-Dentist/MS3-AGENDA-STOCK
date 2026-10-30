<?php

namespace App\Application\UseCases;

use App\Domain\Services\MlInferenceServiceInterface;

class SegmentarPacientesUseCase
{
    public function __construct(private MlInferenceServiceInterface $mlService) {}

    public function execute(array $data): array
    {
        return $this->mlService->clusterPatient($data);
    }
}
