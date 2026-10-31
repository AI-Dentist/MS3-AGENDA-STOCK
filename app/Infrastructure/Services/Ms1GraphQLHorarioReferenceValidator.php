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

    public function validateReferences(string $odontologoId, string $consultorioId, string $callerBearerToken): array
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
            // No concatenar mensaje de excepción externa para evitar fugas de URLs, credenciales o canarios
            throw new RuntimeException('Fallo de conexión o timeout al consultar el catálogo maestro de MS1 ERP.', 502, $e);
        }

        if (! $response->successful()) {
            throw new RuntimeException('Respuesta no exitosa del catálogo maestro de MS1 ERP.', 502);
        }

        $body = $response->json();
        if (! is_array($body)) {
            throw new RuntimeException('Respuesta no válida del catálogo maestro de MS1 ERP.', 502);
        }

        if (isset($body['errors']) && ! empty($body['errors'])) {
            // Enmascarar detalles internos de errores GraphQL para preservar privacidad de canarios/upstream
            throw new RuntimeException('El catálogo maestro de MS1 ERP retornó errores en la consulta GraphQL.', 502);
        }

        if (! isset($body['data']) || ! is_array($body['data'])) {
            throw new RuntimeException('Estructura de respuesta inválida desde el catálogo maestro de MS1 ERP.', 502);
        }

        $data = $body['data'];
        if (! isset($data['listarOdontologos']) || ! is_array($data['listarOdontologos'])
            || ! isset($data['listarConsultorios']) || ! is_array($data['listarConsultorios'])) {
            throw new RuntimeException('Estructura de catálogo inválida desde el catálogo maestro de MS1 ERP.', 502);
        }

        $odontologos = $data['listarOdontologos'];
        $consultorios = $data['listarConsultorios'];

        // 1. Validar odontólogo activo y capturar su UUID canónico exacto retornado por MS1
        $canonicalOdontologoId = null;
        foreach ($odontologos as $odo) {
            if (is_array($odo) && isset($odo['id']) && is_string($odo['id']) && strcasecmp(trim($odo['id']), trim($odontologoId)) === 0) {
                if (($odo['activo'] ?? false) === true) {
                    $canonicalOdontologoId = trim($odo['id']);
                }
                break;
            }
        }

        if ($canonicalOdontologoId === null) {
            throw new InvalidArgumentException(
                "El odontólogo con ID '{$odontologoId}' no existe o no se encuentra activo en el catálogo del ERP (MS1)."
            );
        }

        // 2. Validar consultorio en estado ACTIVO (Consultorio usa estado=ACTIVO, sin columna activo) y capturar su UUID canónico exacto retornado por MS1
        $canonicalConsultorioId = null;
        foreach ($consultorios as $con) {
            if (is_array($con) && isset($con['id']) && is_string($con['id']) && strcasecmp(trim($con['id']), trim($consultorioId)) === 0) {
                if (strtoupper(trim((string) ($con['estado'] ?? ''))) === 'ACTIVO') {
                    $canonicalConsultorioId = trim($con['id']);
                }
                break;
            }
        }

        if ($canonicalConsultorioId === null) {
            throw new InvalidArgumentException(
                "El consultorio con ID '{$consultorioId}' no existe o no se encuentra en estado ACTIVO en el catálogo del ERP (MS1)."
            );
        }

        return [
            'odontologo_id' => $canonicalOdontologoId,
            'consultorio_id' => $canonicalConsultorioId,
        ];
    }
}
