<?php

namespace App\Infrastructure\Persistence\DynamoDB;

use App\Domain\Entities\Insumo;
use App\Domain\Repositories\InsumoRepositoryInterface;
use Illuminate\Support\Facades\File;

class DynamoDbInsumoRepository implements InsumoRepositoryInterface
{
    private string $filePath;

    public function __construct()
    {
        $this->filePath = storage_path('app/dynamodb_insumos.json');

        if (!File::exists($this->filePath)) {
            File::ensureDirectoryExists(dirname($this->filePath));
            // Insumos de prueba iniciales
            $defaultData = [
                [
                    'id' => 'ins-001',
                    'codigo' => 'GUAN-LAT-M',
                    'nombre' => 'Guantes de Látex Talla M',
                    'stock_actual' => 12,
                    'stock_minimo' => 20,
                    'unidad_medida' => 'Cajas'
                ],
                [
                    'id' => 'ins-002',
                    'codigo' => 'ANES-LIDO-2',
                    'nombre' => 'Lidocaína 2% con Epinefrina',
                    'stock_actual' => 50,
                    'stock_minimo' => 15,
                    'unidad_medida' => 'Cartuchos'
                ]
            ];
            File::put($this->filePath, json_encode($defaultData, JSON_PRETTY_PRINT));
        }
    }

    public function findAll(): array
    {
        $items = json_decode(File::get($this->filePath), true) ?? [];
        $insumos = [];

        foreach ($items as $item) {
            $insumos[] = new Insumo(
                id: $item['id'],
                codigo: $item['codigo'],
                nombre: $item['nombre'],
                stockActual: (int)$item['stock_actual'],
                stockMinimo: (int)$item['stock_minimo'],
                unidadMedida: $item['unidad_medida']
            );
        }

        return $insumos;
    }

    public function findById(string $id): ?Insumo
    {
        $items = json_decode(File::get($this->filePath), true) ?? [];
        foreach ($items as $item) {
            if ($item['id'] === $id) {
                return new Insumo(
                    id: $item['id'],
                    codigo: $item['codigo'],
                    nombre: $item['nombre'],
                    stockActual: (int)$item['stock_actual'],
                    stockMinimo: (int)$item['stock_minimo'],
                    unidadMedida: $item['unidad_medida']
                );
            }
        }
        return null;
    }

    public function save(Insumo $insumo): void
    {
        $items = json_decode(File::get($this->filePath), true) ?? [];
        $items[] = $insumo->toArray();
        File::put($this->filePath, json_encode($items, JSON_PRETTY_PRINT));
    }
}