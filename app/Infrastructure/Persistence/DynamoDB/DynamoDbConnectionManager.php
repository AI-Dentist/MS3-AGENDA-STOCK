<?php

namespace App\Infrastructure\Persistence\DynamoDB;

class DynamoDbConnectionManager
{
    private static ?bool $isAvailable = null;

    /**
     * Determine whether the local/remote DynamoDB endpoint is currently reachable.
     * Caches the result to prevent high latency on subsequent calls.
     */
    public static function isAvailable(): bool
    {
        if (self::$isAvailable !== null) {
            return self::$isAvailable;
        }

        // In automated testing environment, default to in-memory store for instant execution
        // unless explicitly testing against a live DynamoDB instance.
        $isTesting = env('APP_ENV') === 'testing'
            || (function_exists('app') && method_exists(app(), 'environment') && app()->environment('testing'));

        if ($isTesting && ! env('DYNAMODB_TEST_LIVE', false)) {
            self::$isAvailable = false;

            return false;
        }

        $endpoint = env('DYNAMODB_ENDPOINT', 'http://127.0.0.1:8002');
        if (! $endpoint) {
            self::$isAvailable = false;

            return false;
        }

        $parts = parse_url($endpoint);
        $host = $parts['host'] ?? '127.0.0.1';
        $port = $parts['port'] ?? (($parts['scheme'] ?? 'http') === 'https' ? 443 : 80);

        $fp = @fsockopen($host, (int) $port, $errno, $errstr, 0.05);
        if ($fp) {
            fclose($fp);
            self::$isAvailable = true;
        } else {
            self::$isAvailable = false;
        }

        return self::$isAvailable;
    }

    public static function setAvailable(?bool $available): void
    {
        self::$isAvailable = $available;
    }

    public static function reset(): void
    {
        self::$isAvailable = null;
    }
}
