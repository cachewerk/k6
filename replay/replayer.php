<?php

declare(strict_types=1);

/**
 * Replays a single captured trace and returns measured metrics.
 *
 * Walks the ordered `timeline`: `php` gaps become `usleep`, `redis` events fire
 * the captured commands (honoring single/pipeline/multi round-trips and
 * decoding `{b64}` values), and `sql` events either sleep for the captured
 * duration (default) or run the query against MySQL (REPLAY_SQL_MODE=execute).
 *
 * Reusable outside HTTP — the PhpBench subject (see TODO.md) drives the same
 * `replay()` method.
 */
final class Replayer
{
    /** Commands treated as writes for read/write + hit/miss accounting. */
    private const WRITE_COMMANDS = [
        'SET', 'SETEX', 'PSETEX', 'SETNX', 'MSET', 'MSETNX', 'APPEND', 'GETSET', 'SETRANGE',
        'HSET', 'HMSET', 'HSETNX', 'HDEL', 'HINCRBY', 'HINCRBYFLOAT',
        'DEL', 'UNLINK', 'EXPIRE', 'PEXPIRE', 'EXPIREAT', 'PERSIST', 'RENAME', 'COPY', 'RESTORE',
        'INCR', 'DECR', 'INCRBY', 'DECRBY', 'INCRBYFLOAT',
        'LPUSH', 'RPUSH', 'LPOP', 'RPOP', 'LSET', 'LREM', 'LTRIM',
        'SADD', 'SREM', 'SPOP', 'SMOVE',
        'ZADD', 'ZREM', 'ZINCRBY', 'ZPOPMIN', 'ZPOPMAX',
        'FLUSHDB', 'FLUSHALL',
    ];

    public function __construct(
        private ReplayRedis $redis,
        private ReplayConfig $config,
        private ?PDO $pdo = null
    ) {
    }

    /** @return array<string,int|float> measured metrics for one trace */
    public function replay(array $trace): array
    {
        $reads = $writes = $hits = $misses = $bytes = $sqlCount = 0;
        $redisNs = $sqlNs = $phpNs = 0;

        $started = hrtime(true);

        foreach ($trace['timeline'] ?? [] as $event) {
            switch ($event['op'] ?? '') {
                case 'php':
                    $us = (int) ($event['us'] ?? 0);
                    if ($us > 0) {
                        usleep($us);
                    }
                    $phpNs += $us * 1000;
                    break;

                case 'sql':
                    $sqlCount++;
                    $t0 = hrtime(true);
                    $this->replaySql($event);
                    $sqlNs += hrtime(true) - $t0;
                    break;

                case 'redis':
                    $t0 = hrtime(true);
                    [$r, $w, $h, $m, $b] = $this->replayRedis($event);
                    $redisNs += hrtime(true) - $t0;
                    $reads += $r;
                    $writes += $w;
                    $hits += $h;
                    $misses += $m;
                    $bytes += $b;
                    break;
            }
        }

        $totalNs = hrtime(true) - $started;
        $lookups = $hits + $misses;

        return [
            'ms_total' => $totalNs / 1e6,
            'ms_redis' => $redisNs / 1e6,
            'ms_sql' => $sqlNs / 1e6,
            'ms_php' => $phpNs / 1e6,
            'reads' => $reads,
            'writes' => $writes,
            'hits' => $hits,
            'misses' => $misses,
            'hit_ratio' => $lookups > 0 ? round($hits / ($lookups / 100), 1) : 100.0,
            'bytes' => $bytes,
            'sql_queries' => $sqlCount,
        ];
    }

    /** @return array{0:int,1:int,2:int,3:int,4:int} [reads, writes, hits, misses, bytes] */
    private function replayRedis(array $event): array
    {
        $cmds = $event['cmds'] ?? [];

        $batch = [];
        foreach ($cmds as $c) {
            $batch[] = [strtoupper($c['cmd']), $this->decodeArgs($c['args'] ?? [])];
        }

        switch ($event['mode'] ?? 'single') {
            case 'pipeline':
                $replies = $this->redis->pipeline($batch);
                break;
            case 'multi':
                $replies = $this->redis->transaction($batch);
                break;
            default:
                $replies = [];
                foreach ($batch as [$cmd, $args]) {
                    $replies[] = $this->redis->command($cmd, $args);
                }
        }

        $reads = $writes = $hits = $misses = $bytes = 0;

        foreach ($cmds as $i => $c) {
            $cmd = strtoupper($c['cmd']);
            $reply = $replies[$i] ?? null;

            if (in_array($cmd, self::WRITE_COMMANDS, true)) {
                $writes++;
                $bytes += (int) ($c['value_bytes'] ?? 0);
            } else {
                $reads++;
                if ($this->replyIsHit($reply)) {
                    $hits++;
                    $bytes += is_string($reply) ? strlen($reply) : (int) ($c['reply_bytes'] ?? 0);
                } else {
                    $misses++;
                }
            }
        }

        return [$reads, $writes, $hits, $misses, $bytes];
    }

    private function replyIsHit($reply): bool
    {
        return $reply !== false && $reply !== null && $reply !== [];
    }

    /** @return list<string> args with `{b64}` elements decoded to raw bytes */
    private function decodeArgs(array $args): array
    {
        return array_map(static function ($arg) {
            if (is_array($arg) && isset($arg['b64'])) {
                return (string) base64_decode((string) $arg['b64'], true);
            }

            return (string) $arg;
        }, $args);
    }

    private function replaySql(array $event): void
    {
        if ($this->config->sqlMode === 'execute' && $this->pdo !== null) {
            try {
                $this->pdo->query((string) ($event['query'] ?? ''))->fetchAll();
            } catch (\Throwable $e) {
                // The replay DB may not match the captured schema/data; the goal
                // is to exercise the query cost, so swallow result errors.
            }

            return;
        }

        $us = (int) ($event['us'] ?? 0);
        if ($us > 0) {
            usleep($us);
        }
    }
}
