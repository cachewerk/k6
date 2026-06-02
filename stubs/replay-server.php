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
 *   - it returns the JSON metric shape `replay.js` knows how to parse
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

header('Content-Type: application/json');
echo json_encode([
    'id' => $id,
    'total_ms' => ($redisEnd - $start) / 1e6,
    'redis_ms' => ($redisEnd - $redisStart) / 1e6,
    'cmd_count' => $trace['commands'],
    'hits' => $hits,
    'misses' => $misses,
]);
