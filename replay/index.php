<?php

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/redis.php';
require __DIR__ . '/replayer.php';

/**
 * Trace replay executor (HTTP endpoint).
 *
 * Replays a captured trace (corpus/NNNNN.json) against a real Redis using the
 * configured client (phpredis/relay), reproducing SQL as a sleep by default or
 * executing it against MySQL when REPLAY_SQL_MODE=execute. Emits an Object
 * Cache Pro-style footnote so `replay.js` records real, measured metrics — the
 * same metric pipeline the other scripts use (see lib/metrics.js).
 *
 * Stateless w.r.t. which trace to run: k6 hands it `?id=N`. Configure via
 * environment variables (see .env.example).
 *
 *   php -S 0.0.0.0:8080 replay/index.php
 *   k6 run replay.js --env SITE_URL=http://localhost:8080
 *
 * For real benchmarking, serve via nginx + PHP-FPM so the corpus, Redis
 * connection, and PDO handle are preloaded/persisted per worker.
 */

$config = ReplayConfig::fromEnv();

// Preloaded once per worker process.
$corpus = load_corpus($config->corpusDir);

$id = isset($_GET['id']) ? (int) $_GET['id'] : -1;

if (! isset($corpus[$id])) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo "unknown trace id: {$id}";
    return;
}

$redis = connect_redis($config);
$pdo = $config->sqlMode === 'execute' ? connect_pdo($config) : null;

$metrics = (new Replayer($redis, $config, $pdo))->replay($corpus[$id]);

header('Content-Type: text/html; charset=UTF-8');
echo "<!DOCTYPE html>\n<html><head><title>Trace {$id}</title></head><body>\n";
echo '<p>Replayed trace ' . $id . "</p>\n";
echo build_footnote($config, $redis, $metrics) . "\n";
echo "</body></html>\n";

/** Load the corpus once per worker (manifest first, else scan NNNNN.json). */
function load_corpus(string $dir): array
{
    static $corpus = null;

    if ($corpus !== null) {
        return $corpus;
    }

    $corpus = [];
    $manifest = $dir . '/manifest.json';

    if (is_readable($manifest)) {
        $index = json_decode((string) file_get_contents($manifest), true);

        foreach ($index['traces'] ?? [] as $entry) {
            $file = $dir . '/' . $entry['file'];
            if (is_readable($file)) {
                $corpus[(int) $entry['id']] = json_decode((string) file_get_contents($file), true);
            }
        }
    } else {
        foreach (glob($dir . '/[0-9]*.json') ?: [] as $file) {
            $trace = json_decode((string) file_get_contents($file), true);
            if (isset($trace['id'])) {
                $corpus[(int) $trace['id']] = $trace;
            }
        }
    }

    return $corpus;
}

/** Connect once per worker (persistent), reused across requests. */
function connect_redis(ReplayConfig $config): ReplayRedis
{
    static $redis = null;

    if ($redis === null) {
        $redis = new ReplayRedis($config);
    }

    return $redis;
}

function connect_pdo(ReplayConfig $config): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config->dbHost,
            $config->dbPort,
            $config->dbName,
            $config->dbCharset
        );

        $pdo = new PDO($dsn, $config->dbUser, $config->dbPassword, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_PERSISTENT => true,
        ]);
    }

    return $pdo;
}

/** Build an OCP-style footnote from measured + server metrics (parsed by lib/metrics.js). */
function build_footnote(ReplayConfig $config, ReplayRedis $redis, array $m): string
{
    $parts = [
        'plugin=replay',
        'client=' . $config->redisClient,
        sprintf('metric#hits=%d', $m['hits']),
        sprintf('metric#misses=%d', $m['misses']),
        sprintf('metric#hit-ratio=%s', $m['hit_ratio']),
        sprintf('metric#bytes=%d', $m['bytes']),
        sprintf('metric#sql-queries=%d', $m['sql_queries']),
        sprintf('metric#ms-total=%s', round($m['ms_total'], 2)),
        sprintf('sample#ms-cache=%s', round($m['ms_redis'], 2)),
        sprintf('sample#store-reads=%d', $m['reads']),
        sprintf('sample#store-writes=%d', $m['writes']),
        sprintf('sample#store-hits=%d', $m['hits']),
        sprintf('sample#store-misses=%d', $m['misses']),
    ];

    $info = $redis->info();

    if ($info) {
        $kh = (int) ($info['keyspace_hits'] ?? 0);
        $km = (int) ($info['keyspace_misses'] ?? 0);
        $kt = $kh + $km;

        $parts[] = sprintf('sample#redis-hits=%d', $kh);
        $parts[] = sprintf('sample#redis-hit-ratio=%s', $kt > 0 ? round($kh / ($kt / 100), 2) : 100);
        $parts[] = sprintf('sample#redis-ops-per-sec=%d', (int) ($info['instantaneous_ops_per_sec'] ?? 0));
        $parts[] = sprintf('sample#redis-used-memory=%d', (int) ($info['used_memory'] ?? 0));
        $parts[] = sprintf('sample#redis-memory-fragmentation-ratio=%s', $info['mem_fragmentation_ratio'] ?? 0);
    }

    $stats = $redis->stats();

    if ($stats) {
        $rh = (int) ($stats['stats']['hits'] ?? 0);
        $rm = (int) ($stats['stats']['misses'] ?? 0);
        $rt = $rh + $rm;
        $used = (int) ($stats['memory']['used'] ?? 0);
        $total = (int) ($stats['memory']['total'] ?? 0);

        $parts[] = sprintf('sample#relay-hit-ratio=%s', $rt > 0 ? round($rh / ($rt / 100), 2) : 100);
        $parts[] = sprintf('sample#relay-ops-per-sec=%d', (int) ($stats['stats']['ops_per_sec'] ?? 0));
        $parts[] = sprintf('sample#relay-memory-active=%d', $used);
        $parts[] = sprintf('sample#relay-memory-ratio=%s', $total > 0 ? round($used / $total * 100, 2) : 0);
    }

    return '<!-- ' . implode(' ', $parts) . ' -->';
}
