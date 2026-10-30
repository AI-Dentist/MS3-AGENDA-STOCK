<?php

namespace App\Providers;

use App\Domain\Repositories\CitaRepositoryInterface;
use App\Domain\Repositories\HorarioRepositoryInterface;
use App\Domain\Repositories\InsumoRepositoryInterface;
use App\Domain\Repositories\MovimientoStockRepositoryInterface;
use App\Domain\Services\MlInferenceServiceInterface;
use App\Infrastructure\Persistence\DynamoDB\DynamoDbCitaRepository;
use App\Infrastructure\Persistence\DynamoDB\DynamoDbHorarioRepository;
use App\Infrastructure\Persistence\DynamoDB\DynamoDbInsumoRepository;
use App\Infrastructure\Persistence\DynamoDB\DynamoDbMovimientoStockRepository;
use App\Infrastructure\Services\FastApiMlService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(CitaRepositoryInterface::class, DynamoDbCitaRepository::class);
        $this->app->bind(HorarioRepositoryInterface::class, DynamoDbHorarioRepository::class);
        $this->app->bind(InsumoRepositoryInterface::class, DynamoDbInsumoRepository::class);
        $this->app->bind(MovimientoStockRepositoryInterface::class, DynamoDbMovimientoStockRepository::class);
        $this->app->bind(MlInferenceServiceInterface::class, FastApiMlService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
