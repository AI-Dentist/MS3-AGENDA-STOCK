<?php

namespace App\Domain\Exceptions;

use Exception;

class CitaCollisionException extends Exception
{
    public function __construct(
        string $message = 'El odontólogo o el consultorio ya cuentan con una reserva en el horario seleccionado.',
        int $code = 409
    ) {
        parent::__construct($message, $code);
    }
}
