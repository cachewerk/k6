<?php

declare(strict_types=1);

/**
 * Thin Redis client wrapper for the replay executor.
 *
 * Wraps phpredis (`\Redis`) or Relay (`\Relay\Relay`) — both expose the same
 * `rawCommand` / `pipeline` / `multi` API, so the same code benchmarks either.
 * Commands are issued via `rawCommand` so the captured wire arguments (verbatim,
 * already-serialized values) are sent as-is while the client library's own
 * encoding and reply parsing are exercised.
 */
final class ReplayRedis
{
    /** @var \Redis|\Relay\Relay */
    private object $redis;

    private bool $isRelay;

    public function __construct(ReplayConfig $config)
    {
        if ($config->redisClient === 'predis') {
            throw new RuntimeException('Predis is not supported by the executor yet; use phpredis or relay.');
        }

        $this->isRelay = $config->redisClient === 'relay';

        if ($this->isRelay && ! class_exists('\Relay\Relay')) {
            throw new RuntimeException('REPLAY_REDIS_CLIENT=relay but the Relay extension is not installed.');
        }

        if (! $this->isRelay && ! class_exists('\Redis')) {
            throw new RuntimeException('REPLAY_REDIS_CLIENT=phpredis but the phpredis extension is not installed.');
        }

        $this->redis = $this->isRelay ? new \Relay\Relay() : new \Redis();

        $host = ($config->redisTls ? 'tls://' : '') . $config->redisHost;

        $connected = $config->redisPersistent
            ? $this->redis->pconnect($host, $config->redisPort, $config->redisTimeout)
            : $this->redis->connect($host, $config->redisPort, $config->redisTimeout);

        if (! $connected) {
            throw new RuntimeException("Could not connect to Redis at {$config->redisHost}:{$config->redisPort}");
        }

        if ($config->redisPassword !== null) {
            $this->redis->auth($config->redisPassword);
        }

        if ($config->redisDb !== 0) {
            $this->redis->select($config->redisDb);
        }
    }

    /** Execute a single command in its own round-trip; returns the raw reply. */
    public function command(string $cmd, array $args)
    {
        return $this->redis->rawCommand($cmd, ...$args);
    }

    /**
     * Execute a batch of [cmd, args] in one pipelined round-trip.
     * Returns replies aligned with the batch order.
     */
    public function pipeline(array $batch): array
    {
        $pipe = $this->redis->pipeline();

        foreach ($batch as [$cmd, $args]) {
            $pipe->rawCommand($cmd, ...$args);
        }

        return (array) $pipe->exec();
    }

    /** Execute a batch of [cmd, args] inside MULTI/EXEC. */
    public function transaction(array $batch): array
    {
        $multi = $this->redis->multi();

        foreach ($batch as [$cmd, $args]) {
            $multi->rawCommand($cmd, ...$args);
        }

        return (array) $multi->exec();
    }

    public function info(): array
    {
        return (array) $this->redis->info();
    }

    /** Relay in-memory cache stats, or null for phpredis. */
    public function stats(): ?array
    {
        if ($this->isRelay && method_exists($this->redis, 'stats')) {
            return $this->redis->stats();
        }

        return null;
    }
}
