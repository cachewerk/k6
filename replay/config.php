<?php

declare(strict_types=1);

/**
 * Replay executor configuration, loaded from environment variables.
 *
 * Reads an optional `.env` file at the repo root (real environment variables
 * always take precedence), then exposes typed, validated config. See
 * `.env.example` for the full list of variables.
 */
final class ReplayConfig
{
    public string $redisClient;      // phpredis | relay | predis
    public string $redisHost;
    public int $redisPort;
    public ?string $redisPassword;
    public int $redisDb;
    public bool $redisTls;
    public bool $redisPersistent;
    public float $redisTimeout;

    public string $corpusDir;

    public string $sqlMode;          // sleep | execute
    public string $dbHost;
    public int $dbPort;
    public string $dbName;
    public string $dbUser;
    public string $dbPassword;
    public string $dbCharset;

    public static function fromEnv(?string $envFile = null): self
    {
        self::loadEnvFile($envFile ?? __DIR__ . '/../.env');

        $c = new self();

        $c->redisClient = strtolower(self::env('REPLAY_REDIS_CLIENT', 'phpredis'));
        $c->redisHost = self::env('REPLAY_REDIS_HOST', '127.0.0.1');
        $c->redisPort = (int) self::env('REPLAY_REDIS_PORT', '6379');
        $c->redisPassword = self::env('REPLAY_REDIS_PASSWORD', '') ?: null;
        $c->redisDb = (int) self::env('REPLAY_REDIS_DB', '0');
        $c->redisTls = self::bool('REPLAY_REDIS_TLS', false);
        $c->redisPersistent = self::bool('REPLAY_REDIS_PERSISTENT', true);
        $c->redisTimeout = (float) self::env('REPLAY_REDIS_TIMEOUT', '2.0');

        $c->corpusDir = rtrim(self::env('REPLAY_CORPUS', __DIR__ . '/../corpus'), '/');

        $c->sqlMode = strtolower(self::env('REPLAY_SQL_MODE', 'sleep'));
        $c->dbHost = self::env('REPLAY_DB_HOST', '127.0.0.1');
        $c->dbPort = (int) self::env('REPLAY_DB_PORT', '3306');
        $c->dbName = self::env('REPLAY_DB_NAME', '');
        $c->dbUser = self::env('REPLAY_DB_USER', '');
        $c->dbPassword = self::env('REPLAY_DB_PASSWORD', '');
        $c->dbCharset = self::env('REPLAY_DB_CHARSET', 'utf8mb4');

        $c->validate();

        return $c;
    }

    private function validate(): void
    {
        if (! in_array($this->redisClient, ['phpredis', 'relay', 'predis'], true)) {
            throw new RuntimeException('REPLAY_REDIS_CLIENT must be one of: phpredis, relay, predis');
        }

        if (! in_array($this->sqlMode, ['sleep', 'execute'], true)) {
            throw new RuntimeException('REPLAY_SQL_MODE must be "sleep" or "execute"');
        }

        if ($this->sqlMode === 'execute' && $this->dbName === '') {
            throw new RuntimeException('REPLAY_SQL_MODE=execute requires REPLAY_DB_NAME (and likely REPLAY_DB_USER/PASSWORD)');
        }
    }

    private static function env(string $key, string $default): string
    {
        $value = getenv($key);

        return $value === false ? $default : $value;
    }

    private static function bool(string $key, bool $default): bool
    {
        $value = getenv($key);

        if ($value === false || $value === '') {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    private static function loadEnvFile(string $path): void
    {
        if (! is_readable($path)) {
            return;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#' || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Strip matching surrounding quotes.
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }

            // Real environment variables win over the .env file.
            if (getenv($key) === false) {
                putenv("{$key}={$value}");
            }
        }
    }
}
