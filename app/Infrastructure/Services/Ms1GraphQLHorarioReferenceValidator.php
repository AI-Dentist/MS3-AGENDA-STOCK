<?php

namespace App\Infrastructure\Services;

use App\Application\Ports\Ms1HorarioReferenceValidatorPort;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class Ms1GraphQLHorarioReferenceValidator implements Ms1HorarioReferenceValidatorPort
{
    private const GRAPHQL_QUERY = <<<'GRAPHQL'
query ValidarReferenciasHorario {
  listarOdontologos(soloActivos: true) {
    id
    activo
  }
  listarConsultorios {
    id
    estado
  }
}
GRAPHQL;

    public function validateReferences(string $odontologoId, string $consultorioId, string $callerBearerToken): void
    {
        $graphqlUrl = config('services.ms1.graphql_url', 'http://127.0.0.1:8081/graphql');
        $timeoutMs = (int) config('services.ms1.timeout_ms', 2000);
        $timeoutSec = max(1, (int) ceil($timeoutMs / 1000));

        try {
            $response = Http::withToken($callerBearerToken)
                ->timeout($timeoutSec)
                ->acceptJson()
                ->asJson()
                ->post($graphqlUrl, [
                    'query' => self::GRAPHQL_QUERY,
                ]);
        } catch (Throwable $e) {
            throw new RuntimeException('No se pudo conectar con el catálogo maestro de MS1 ERP: '.$e->getMessage(), 502, $e);
        }

        if (! $response->successful()) {
            throw new RuntimeException("El catálogo maestro de MS1 ERP respondió con código HTTP {$response->status()}.", 502);
        }

        $body = $response->json();
        if (isset($body['errors']) && ! empty($body['errors'])) {
            $firstError = $body['errors'][0]['message'] ?? 'Error desconocido';
            throw new RuntimeException("Error en consulta GraphQL de MS1: {$firstError}", 502);
        }

        $data = $body['data'] ?? [];
        $odontologos = $data['listarOdontologos'] ?? [];
        $consultorios = $data['listarConsultorios'] ?? [];

        // 1. Validar odontólogo activo
        $odontologoEncontrado = false;
        foreach ($odontologos as $odo) {
            if (isset($odo['id']) && strcasecmp(trim($odo['id']), trim($odontologoId)) === 0) {
                if (($odo['activo'] ?? false) === true) {
                    $odontologoEncontrado = true;
                }
                break;
            }
        }

        if (! $odontologoEncontrado) {
            throw new InvalidArgumentException(
                "El odontólogo con ID '{$odontologoId}' no existe o no se encuentra activo en el catálogo del ERP (MS1)."
            );
        }

        // 2. Validar consultorio en estado ACTIVO (Consultorio usa estado=ACTIVO, sin columna activo)
        $consultorioEncontrado = false;
        foreach ($consultorios as $con) {
            if (isset($con['id']) && strcasecmp(trim($con['id']), trim($consultorioId)) === 0) {
                if (strtoupper(trim($con['estado'] ?? '')) === 'ACTIVO') {
                    $consultorioEncontrado = true;
                }
                break;
            }
        }

        if (! $consultorioEncontrado) {
            throw new InvalidArgumentException(
                "El consultorio con ID '{$consultorioId}' no existe o no se encuentra en estado ACTIVO en el catálogo del ERP (MS1)."
            );
        }
    }
}
