<?php

namespace App\Application\Ports;

interface Ms1HorarioReferenceValidatorPort
{
    /**
     * Valida que el odontólogo exista y esté activo, y que el consultorio exista y esté en estado ACTIVO en MS1.
     * Retorna los identificadores canónicos exactos retornados por el catálogo maestro de MS1 ERP.
     *
     * @param  string  $odontologoId  UUID del odontólogo canónico en MS1
     * @param  string  $consultorioId  UUID del consultorio canónico en MS1
     * @param  string  $callerBearerToken  Token Bearer del usuario ADMIN autenticado
     * @return array{odontologo_id: string, consultorio_id: string}
     *
     * @throws \InvalidArgumentException si la referencia no existe o está inactiva
     * @throws \RuntimeException si el servicio MS1 no está disponible o responde con error
     */
    public function validateReferences(string $odontologoId, string $consultorioId, string $callerBearerToken): array;
}
