<?php

namespace App\Domain\Exceptions;

use RuntimeException;
use Throwable;

class StockMovementException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function unavailable(?Throwable $previous = null): self
    {
        return new self(
            'STOCK_WRITE_UNAVAILABLE',
            'No se pudo confirmar el movimiento de stock. Consulte el resultado antes de reintentar.',
            503,
            $previous
        );
    }
}
