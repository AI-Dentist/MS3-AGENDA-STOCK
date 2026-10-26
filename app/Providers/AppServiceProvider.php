<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Domain\Repositories\CitaRepositoryInterface;
use App\Infrastructure\Persistence\DynamoDB\DynamoDbCitaRepository;

use App\Domain\Repositories\HorarioRepositoryInterface;
use App\Infrastructure\Persistence\DynamoDB\DynamoDbHorarioRepository;
use App\Domain\Repositories\InsumoRepositoryInterface;
use App\Infrastructure\Persistence\DynamoDB\DynamoDbInsumoRepository;

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
        
  
        
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
