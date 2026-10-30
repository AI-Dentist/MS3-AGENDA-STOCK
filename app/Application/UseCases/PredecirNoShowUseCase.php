<?php

namespace App\Application\UseCases;

use App\Domain\Services\MlInferenceServiceInterface;

class PredecirNoShowUseCase
{
    public function __construct(private MlInferenceServiceInterface $mlService) {}

    public function execute(array $data): array
    {
        return $this->mlService->predictNoShow($data);
    }
}
