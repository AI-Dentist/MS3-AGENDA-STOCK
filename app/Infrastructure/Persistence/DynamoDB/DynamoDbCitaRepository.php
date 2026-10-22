<?php

namespace App\Infrastructure\Persistence\DynamoDB;

use App\Domain\Entities\Cita;
use App\Domain\Repositories\CitaRepositoryInterface;
use Illuminate\Support\Facades\File;

class DynamoDbCitaRepository implements CitaRepositoryInterface
{
    private string $filePath;

    public function __construct()
    {
        $this->filePath = storage_path('app/dynamodb_citas.json');

        if (!File::exists($this->filePath)) {
            File::ensureDirectoryExists(dirname($this->filePath));
            File::put($this->filePath, json_encode([]));
        }
    }

    public function findByIdempotencyKey(string $key): ?Cita
    {
        $items = json_decode(File::get($this->filePath), true) ?? [];

        foreach ($items as $item) {
            if (isset($item['idempotency_key']) && $item['idempotency_key'] === $key) {
                return new Cita(
                    id: $item['id'],
                    idempotencyKey: $item['idempotency_key'],
                    odontologoId: $item['odontologo_id'],
                    consultorioId: $item['consultorio_id'],
                    pacienteId: $item['paciente_id'],
                    fechaHora: $item['fecha_hora'],
                    estado: $item['estado'] ?? 'RESERVADA'
                );
            }
        }

        return null;
    }

    public function save(Cita $cita): void
    {
        $items = json_decode(File::get($this->filePath), true) ?? [];
        $items[] = $cita->toArray();
        File::put($this->filePath, json_encode($items, JSON_PRETTY_PRINT));
    }
}