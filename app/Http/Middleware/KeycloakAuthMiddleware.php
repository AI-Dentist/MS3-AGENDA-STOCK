<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class KeycloakAuthMiddleware
{
    /**
     * Default public key / secret for local testing and development.
     */
    public const DEFAULT_DEV_SECRET = 'ms3-agenda-stock-local-jwt-secret-key-2026';

    /**
     * Default clock skew leeway in seconds for Keycloak JWT validation.
     */
    public const DEFAULT_JWT_LEEWAY = 60;

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $token = $request->bearerToken();
        $hasRolesHeader = $request->hasHeader('X-User-Roles');

        // Seguridad: Anti-Spoofing estricto.
        // Rechazar tajantemente cualquier intento de inyectar X-User-Roles sin un token Bearer criptográfico válido.
        if ($hasRolesHeader && ! $token) {
            return response()->json([
                'success' => false,
                'error' => 'No autorizado: Intento de suplantación (spoofing) de cabecera X-User-Roles sin token Bearer válido.',
                'code' => 'UNAUTHORIZED_SPOOF_ATTEMPT',
                'timestamp' => now()->toIso8601String(),
            ], 401);
        }

        // Si ya fue autenticado por un grupo de middleware superior en la misma petición
        if ($request->attributes->has('jwt_payload')) {
            $userRoles = $request->attributes->get('user_roles', []);

            return $this->authorizeRoles($request, $next, $roles, $userRoles);
        }

        // Si no hay token Bearer
        if (! $token) {
            // En entorno de pruebas automatizadas (PHPUnit), si no se especificó Authorization,
            // proveer un contexto seguro de test resolver para pruebas que no evalúen autenticación directamente.
            if (app()->environment('testing') && ! env('ENFORCE_KEYCLOAK_IN_TESTS', false)) {
                $testUserId = 'test-suite-user';
                $testRoles = ['ADMIN', 'RECEPCIONISTA', 'ODONTOLOGO', 'ASISTENTE'];

                $request->attributes->set('user_id', $testUserId);
                $request->attributes->set('user_roles', $testRoles);

                $request->setUserResolver(function () use ($testUserId, $testRoles) {
                    $user = new User;
                    $user->id = $testUserId;
                    $user->name = 'Test User';
                    $user->email = 'test@dental.local';
                    $user->roles = $testRoles;

                    return $user;
                });

                return $this->authorizeRoles($request, $next, $roles, $testRoles);
            }

            return response()->json([
                'success' => false,
                'error' => 'No autorizado: Token Bearer de Keycloak requerido.',
                'code' => 'UNAUTHORIZED',
                'realm' => 'dental-platform',
                'timestamp' => now()->toIso8601String(),
            ], 401);
        }

        // Verificación criptográfica estricta del Token JWT y validación de claims (iss, aud)
        try {
            $decoded = $this->verifyToken($token);
            $this->validateClaims($decoded);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => 'Token JWT de Keycloak inválido o no autorizado: '.$e->getMessage(),
                'code' => 'INVALID_TOKEN',
                'timestamp' => now()->toIso8601String(),
            ], 401);
        }

        // Extraer roles de claims estándar de Keycloak
        $userRoles = $this->extractRoles($decoded);
        $userId = $decoded->sub ?? $decoded->preferred_username ?? 'unknown-user';

        // Vincular el contexto del usuario autenticado a los atributos de Request y al UserResolver
        $request->attributes->set('jwt_payload', (array) $decoded);
        $request->attributes->set('user_id', $userId);
        $request->attributes->set('user_roles', $userRoles);

        $request->setUserResolver(function () use ($userId, $userRoles, $decoded) {
            $user = new User;
            $user->id = $userId;
            $user->name = $decoded->name ?? ($decoded->preferred_username ?? $userId);
            $user->email = $decoded->email ?? "{$userId}@dental.local";
            $user->roles = $userRoles;

            return $user;
        });

        return $this->authorizeRoles($request, $next, $roles, $userRoles);
    }

    /**
     * Valida si el usuario posee los roles requeridos para el endpoint (RBAC).
     */
    private function authorizeRoles(Request $request, Closure $next, array $roles, array $userRoles): Response
    {
        if (! empty($roles)) {
            $hasRequiredRole = false;
            // Normalizar roles: remover prefijo ROLE_ y homologar DENTISTA con ODONTOLOGO
            $userRolesUpper = array_map(function ($r) {
                $clean = strtoupper(str_replace('ROLE_', '', trim($r)));
                return $clean === 'DENTISTA' ? 'ODONTOLOGO' : $clean;
            }, $userRoles);

            foreach ($roles as $requiredRole) {
                $normalizedRequired = strtoupper(str_replace('ROLE_', '', trim($requiredRole)));
                if (in_array($normalizedRequired, $userRolesUpper, true)) {
                    $hasRequiredRole = true;
                    break;
                }
            }

            if (! $hasRequiredRole) {
                return response()->json([
                    'success' => false,
                    'error' => 'Acceso denegado: El usuario no cuenta con los roles requeridos ('.implode(', ', $roles).').',
                    'code' => 'FORBIDDEN_ROLE',
                    'required_roles' => $roles,
                    'user_roles' => $userRoles,
                    'timestamp' => now()->toIso8601String(),
                ], 403);
            }
        }

        return $next($request);
    }

    /**
     * Verifica la firma criptográfica del token usando la clave pública RS256 configurada o secreto local.
     */
    private function verifyToken(string $token): object
    {
        // Configurar tolerancia a desfasaje de reloj (clock skew) de 60s
        $leeway = (int) env('KEYCLOAK_JWT_LEEWAY', self::DEFAULT_JWT_LEEWAY);
        JWT::$leeway = $leeway;

        $rawKey = env('KEYCLOAK_PUBLIC_KEY');
        $algorithm = env('KEYCLOAK_ALGORITHM', 'RS256');

        if ($rawKey) {
            if ($algorithm === 'RS256' && ! str_starts_with(trim($rawKey), '-----BEGIN')) {
                $formattedKey = "-----BEGIN PUBLIC KEY-----\n".wordwrap(trim($rawKey), 64, "\n", true)."\n-----END PUBLIC KEY-----";
            } else {
                $formattedKey = $rawKey;
            }
            $key = new Key($formattedKey, $algorithm);

            return JWT::decode($token, $key);
        }

        // Si no se configuró clave pública en .env, verificar con secreto HMAC local de desarrollo
        $devSecret = env('KEYCLOAK_DEV_SECRET', self::DEFAULT_DEV_SECRET);

        return JWT::decode($token, new Key($devSecret, 'HS256'));
    }

    /**
     * Valida estrictamente los claims 'iss' (issuer) y 'aud' (audience) del token JWT.
     *
     * Reglas del ecosistema:
     * - Emisor confiable: rtrim(KEYCLOAK_BASE_URL)/realms/KEYCLOAK_REALM
     * - Audiencia del recurso: KEYCLOAK_AUDIENCE por defecto 'ms3-agenda-stock' (NO el nombre del realm).
     * - Pertenencia estricta para string o array.
     *
     * @throws InvalidArgumentException
     */
    private function validateClaims(object $decoded): void
    {
        // 0. Validar expiración estricta ('exp') sin tolerancia para tokens vencidos
        if (isset($decoded->exp) && $decoded->exp <= time()) {
            throw new \Firebase\JWT\ExpiredException('Expired token');
        }

        // 1. Validar claim 'iss' (Emisor confiable)
        $baseUrl = rtrim(env('KEYCLOAK_BASE_URL', 'http://localhost:8180'), '/');
        $realm = env('KEYCLOAK_REALM', 'dental-platform');
        $expectedIssuer = "{$baseUrl}/realms/{$realm}";

        if (empty($decoded->iss) || ! is_string($decoded->iss) || $decoded->iss !== $expectedIssuer) {
            throw new InvalidArgumentException(
                "Emisor (iss) no autorizado o ausente. Se esperaba '{$expectedIssuer}'."
            );
        }

        // 2. Validar claim 'aud' (Audiencia del recurso: ms3-agenda-stock)
        $expectedAudience = env('KEYCLOAK_AUDIENCE', 'ms3-agenda-stock');

        if (! isset($decoded->aud)) {
            throw new InvalidArgumentException(
                "El claim de audiencia (aud) es obligatorio y debe incluir '{$expectedAudience}'."
            );
        }

        $hasValidAudience = false;
        if (is_string($decoded->aud)) {
            $hasValidAudience = ($decoded->aud === $expectedAudience);
        } elseif (is_array($decoded->aud)) {
            $hasValidAudience = in_array($expectedAudience, $decoded->aud, true);
        }

        if (! $hasValidAudience) {
            throw new InvalidArgumentException(
                "La audiencia del token (aud) no contiene el recurso autorizado '{$expectedAudience}'."
            );
        }
    }

    /**
     * Extrae roles desde las estructuras habituales de Keycloak (realm_access, resource_access).
     */
    private function extractRoles(object $decoded): array
    {
        $roles = [];

        if (isset($decoded->realm_access->roles) && is_array($decoded->realm_access->roles)) {
            $roles = array_merge($roles, $decoded->realm_access->roles);
        }

        if (isset($decoded->roles) && is_array($decoded->roles)) {
            $roles = array_merge($roles, $decoded->roles);
        }

        if (isset($decoded->resource_access) && is_object($decoded->resource_access)) {
            foreach ((array) $decoded->resource_access as $clientAccess) {
                if (isset($clientAccess->roles) && is_array($clientAccess->roles)) {
                    $roles = array_merge($roles, $clientAccess->roles);
                }
            }
        }

        return array_values(array_unique($roles));
    }
}
