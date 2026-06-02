<?php

declare(strict_types=1);

/**
 * Dummy replay endpoint — a STUB to drive `replay.js` end-to-end.
 *
 * This does NOT talk to Redis. Payload execution (replaying the captured
 * Redis command traces via Relay/PhpRedis, including the `sleep` for the
 * captured PHP compute time) is handled separately and drops in where the
 * "simulate the request" block is below.
 *
 * What it demonstrates:
 *   - the endpoint is stateless: k6 hands it `?id=N`, it executes trace N
 *   - the corpus is preloaded once at startup (no per-request file I/O noise)
 *   - it prints a fake Object Cache Pro footnote comment, the same shape the
 *     real plugin emits (see `k6-metrics.php`) and that `lib/metrics.js` parses
 *
 * The advertised client can be set with `?client=relay|phpredis|predis|apcu`
 * (default: relay), so the `using-*` metrics in `replay.js` get populated.
 *
 * Run it with PHP's built-in server (use real nginx + PHP-FPM for a realistic
 * process model when benchmarking for real):
 *
 *   php -S 0.0.0.0:8080 stubs/replay-server.php
 *   k6 run replay.js --env SITE_URL=http://localhost:8080
 */

const TRACE_COUNT = 100;

/**
 * Preload the corpus once per worker process.
 *
 * In the real harness this loads the captured traces (e.g. from disk into a
 * static array / APCu) so request handling is pure CPU + Redis. Here we just
 * fabricate TRACE_COUNT deterministic fake traces so each `id` is stable.
 */
function load_corpus(): array
{
    static $corpus = null;

    if ($corpus !== null) {
        return $corpus;
    }

    $corpus = [];

    for ($i = 0; $i < TRACE_COUNT; $i++) {
        $corpus[$i] = [
            'commands' => 20 + ($i % 30),          // pretend N Redis commands
            'compute_us' => 500 + ($i % 50) * 10,  // pretend PHP compute time (µs)
            'hit_ratio' => ($i % 100) / 100.0,
        ];
    }

    return $corpus;
}

$corpus = load_corpus();

$id = isset($_GET['id']) ? (int) $_GET['id'] : -1;

if (! isset($corpus[$id])) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'unknown trace id', 'id' => $id]);
    return;
}

$trace = $corpus[$id];

/**
 * Simulate the request. Replace this block with the real payload executor:
 * sleep for the captured compute time, then fire the captured Redis commands
 * (preserving pipelining/MULTI batches and TTLs) and consume the responses.
 */
$start = hrtime(true);
usleep((int) $trace['compute_us']);          // fake PHP compute / think time
$redisStart = hrtime(true);
usleep((int) ($trace['commands'] * 5));      // fake Redis round-trip time
$redisEnd = hrtime(true);

$hits = (int) round($trace['commands'] * $trace['hit_ratio']);
$misses = $trace['commands'] - $hits;
$ratio = $trace['commands'] > 0 ? round($hits / ($trace['commands'] / 100), 1) : 100;
$totalMs = round(($redisEnd - $start) / 1e6, 2);

$client = isset($_GET['client']) ? preg_replace('/[^a-z]/', '', strtolower($_GET['client'])) : 'relay';
$client = $client !== '' ? $client : 'relay';

// Fake Object Cache Pro footnote — same format the real mu-plugin prints
// (see k6-metrics.php) and that lib/metrics.js parses out of the response body.
$footnote = sprintf(
    '<!-- plugin=object-cache-pro client=%s '
        . 'metric#hits=%d metric#misses=%d metric#hit-ratio=%s metric#bytes=%d '
        . 'metric#sql-queries=%d metric#ms-total=%s sample#sys-load=%.2f '
        . 'sample#redis-hits=%d sample#redis-hit-ratio=%s sample#redis-ops-per-sec=%d '
        . 'sample#redis-used-memory=%d sample#redis-keys=%d '
        . 'sample#relay-hit-ratio=%s sample#relay-ops-per-sec=%d sample#relay-keys=%d '
        . 'sample#relay-memory-active=%d sample#relay-memory-ratio=%s -->',
    $client,
    $hits,
    $misses,
    $ratio,
    1024 * $trace['commands'],
    $trace['commands'],
    $totalMs,
    sys_getloadavg()[0] ?? 0,
    $hits * 3,
    $ratio,
    50000 + $id * 10,
    8 * 1024 * 1024 + $id * 1024,
    1000 + $id,
    min(100, $ratio + 5),
    60000 + $id * 10,
    900 + $id,
    32 * 1024 * 1024,
    42.5
);

// Mimic a real WordPress page: HTML body with the footnote near the end.
header('Content-Type: text/html; charset=UTF-8');
echo "<!DOCTYPE html>\n<html><head><title>Trace {$id}</title></head><body>\n";
echo "<p>Replayed trace {$id} ({$trace['commands']} commands)</p>\n";
echo $footnote . "\n";
echo "</body></html>\n";
