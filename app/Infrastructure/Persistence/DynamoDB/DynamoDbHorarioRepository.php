<?php

namespace App\Infrastructure\Persistence\DynamoDB;

use App\Domain\Entities\Horario;
use App\Domain\Repositories\HorarioRepositoryInterface;
use Illuminate\Support\Facades\File;

class DynamoDbHorarioRepository implements HorarioRepositoryInterface
{
    private string $filePath;

    public function __construct()
    {
        $this->filePath = storage_path('app/dynamodb_horarios.json');

        if (!File::exists($this->filePath)) {
            File::ensureDirectoryExists(dirname($this->filePath));
            $seedData = [
                [
                    'id'             => 'hor-001',
                    'odontologo_id'  => 'odo-123',
                    'especialidad'   => 'Ortodoncia',
                    'consultorio_id' => 'con-01',
                    'fecha'          => '2026-11-15',
                    'hora_inicio'    => '08:00',
                    'hora_fin'       => '12:00',
                    'disponible'     => true
                ],
                [
                    'id'             => 'hor-002',
                    'odontologo_id'  => 'odo-456',
                    'especialidad'   => 'Endodoncia',
                    'consultorio_id' => 'con-02',
                    'fecha'          => '2026-11-15',
                    'hora_inicio'    => '14:00',
                    'hora_fin'       => '18:00',
                    'disponible'     => true
                ]
            ];
            File::put($this->filePath, json_encode($seedData, JSON_PRETTY_PRINT));
        }
    }

    public function findByOdontologo(string $odontologoId): array
    {
        $items = json_decode(File::get($this->filePath), true) ?? [];
        $horarios = [];

        foreach ($items as $item) {
            if ($item['odontologo_id'] === $odontologoId) {
                $horarios[] = new Horario(
                    id: $item['id'],
                    odontologoId: $item['odontologo_id'],
                    especialidad: $item['especialidad'],
                    consultorioId: $item['consultorio_id'],
                    fecha: $item['fecha'],
                    horaInicio: $item['hora_inicio'],
                    horaFin: $item['hora_fin'],
                    disponible: $item['disponible'] ?? true
                );
            }
        }

        return $horarios;
    }

    public function findAll(): array
    {
        $items = json_decode(File::get($this->filePath), true) ?? [];
        $horarios = [];

        foreach ($items as $item) {
            $horarios[] = new Horario(
                id: $item['id'],
                odontologoId: $item['odontologo_id'],
                especialidad: $item['especialidad'],
                consultorioId: $item['consultorio_id'],
                fecha: $item['fecha'],
                horaInicio: $item['hora_inicio'],
                horaFin: $item['hora_fin'],
                disponible: $item['disponible'] ?? true
            );
        }

        return $horarios;
    }

    public function save(Horario $horario): void
    {
        $items = json_decode(File::get($this->filePath), true) ?? [];
        $items[] = $horario->toArray();
        File::put($this->filePath, json_encode($items, JSON_PRETTY_PRINT));
    }
}