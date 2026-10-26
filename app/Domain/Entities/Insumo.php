<?php

namespace App\Domain\Entities;

class Insumo
{
    public function __construct(
        public readonly string $id,
        public readonly string $codigo,
        public readonly string $nombre,
        public readonly int $stockActual,
        public readonly int $stockMinimo,
        public readonly string $unidadMedida
    ) {}

    public function requiereReposicion(): bool
    {
        return $this->stockActual <= $this->stockMinimo;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'codigo' => $this->codigo,
            'nombre' => $this->nombre,
            'stock_actual' => $this->stockActual,
            'stock_minimo' => $this->stockMinimo,
            'unidad_medida' => $this->unidadMedida,
            'alerta_reposicion' => $this->requiereReposicion(),
        ];
    }
}