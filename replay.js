import http from 'k6/http'
import exec from 'k6/execution'
import { check } from 'k6'
import { Trend, Counter } from 'k6/metrics'

import { validateSiteUrl } from './lib/helpers.js'

/**
 * Deterministic trace replay.
 *
 * Instead of driving a real WordPress site, this fires a fixed corpus of
 * captured requests ("traces") against a stateless replay endpoint. Each run
 * executes the *identical* workload so results are comparable across runs and
 * across horizontally scaled load generators.
 *
 * The server is dumb: k6 owns which trace runs and in which order, the server
 * just executes whatever `id` it is handed. All determinism lives here.
 *
 * Workload: TRACES unique traces, each repeated until TOTAL requests are fired
 * (default: 100 traces x 100 repeats = 10,000 requests), run as fast as the
 * SUT allows (closed-loop, no think time).
 *
 *   k6 run replay.js --env SITE_URL=http://localhost:8080
 *   k6 run replay.js --env SITE_URL=http://localhost:8080 --env VUS=200
 *
 * Scale across N load-generator machines with execution segments (the trace
 * mapping below is segment-safe, so coverage stays complete and reproducible):
 *
 *   # machine 1 of 4
 *   k6 run replay.js --env SITE_URL=http://lb \
 *     --execution-segment "0:1/4" \
 *     --execution-segment-sequence "0,1/4,2/4,3/4,1"
 */

const TRACES = Number(__ENV.TRACES || 100)        // number of unique captured traces
const TOTAL = Number(__ENV.TOTAL || 10000)        // total requests to fire
const VUS = Number(__ENV.VUS || 100)              // concurrency (closed-loop)
const REPLAY_PATH = __ENV.REPLAY_PATH || '/render'

// per-vu-iterations: total = VUS * ITER_PER_VU
const ITER_PER_VU = Math.ceil(TOTAL / VUS)

export const options = {
    summaryTimeUnit: 'ms',
    scenarios: {
        replay: {
            executor: 'per-vu-iterations',
            vus: VUS,
            iterations: ITER_PER_VU,
            maxDuration: __ENV.MAX_DURATION || '30m',
        },
    },
    thresholds: {
        http_req_failed: ['rate<0.01'],
    },
    ext: {
        loadimpact: {
            name: 'Deterministic trace replay',
            note: 'Replay a fixed corpus of captured requests as an identical, reproducible workload.',
            projectID: __ENV.PROJECT_ID || null,
        },
    },
}

// Metrics reported back by the replay endpoint (parsed defensively below, so
// the script still works if the endpoint returns no body / a different shape).
const redisMs = new Trend('replay_redis_ms', true)
const totalMs = new Trend('replay_total_ms', true)
const cmdCount = new Trend('replay_cmd_count')
const cacheHits = new Counter('replay_cache_hits')
const cacheMisses = new Counter('replay_cache_misses')

export function setup () {
    validateSiteUrl(__ENV.SITE_URL)

    console.info(`Replaying ${TRACES} traces x ${Math.round(TOTAL / TRACES)} = ${VUS * ITER_PER_VU} requests across ${VUS} VUs`)
}

export default function () {
    // Globally-unique, segment-safe iteration index (0-based). `idInTest` is
    // stable across VUs and execution segments, so this mapping is identical
    // every run and never overlaps between load-generator machines.
    const globalIter = (exec.vu.idInTest - 1) * ITER_PER_VU + exec.vu.iterationInScenario

    // Map to one of TRACES traces, offset by VU so concurrent VUs work on
    // different traces at any instant (avoids artificial lockstep on trace 0)
    // while keeping each trace's total execution count fixed and deterministic.
    const traceId = (globalIter + (exec.vu.idInTest - 1)) % TRACES

    const response = http.get(`${__ENV.SITE_URL}${REPLAY_PATH}?id=${traceId}`, {
        tags: { trace: String(traceId) },
    })

    check(response, {
        'response code is 200': response => response.status === 200,
    })

    addReplayMetrics(response)
}

function addReplayMetrics (response) {
    let metrics

    try {
        metrics = response.json()
    } catch (e) {
        return // non-JSON body (e.g. the real SUT) — nothing to record here
    }

    if (! metrics || typeof metrics !== 'object') {
        return
    }

    if (metrics.redis_ms !== undefined) redisMs.add(Number(metrics.redis_ms))
    if (metrics.total_ms !== undefined) totalMs.add(Number(metrics.total_ms))
    if (metrics.cmd_count !== undefined) cmdCount.add(Number(metrics.cmd_count))
    if (metrics.hits !== undefined) cacheHits.add(Number(metrics.hits))
    if (metrics.misses !== undefined) cacheMisses.add(Number(metrics.misses))
}
