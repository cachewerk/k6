import http from 'k6/http'
import exec from 'k6/execution'
import { check } from 'k6'
import { Rate } from 'k6/metrics'

import Metrics from './lib/metrics.js'
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
 *   k6 run replay.js --env SITE_URL=http://localhost:8080
 *   k6 run replay.js --env SITE_URL=http://localhost:8080 --env SCENARIO=ramping
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
const TOTAL = Number(__ENV.TOTAL || 10000)        // total requests to fire (count-based scenarios)
const VUS = Number(__ENV.VUS || 100)              // concurrency
const SCENARIO = __ENV.SCENARIO || 'fixed'        // fixed | ramping | constant | shared
const REPLAY_PATH = __ENV.REPLAY_PATH || '/render'

export const options = {
    summaryTimeUnit: 'ms',
    scenarios: {
        replay: buildScenario(),
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

/**
 * Pick the load profile via `--env SCENARIO=...`:
 *
 *   fixed    (default) per-vu-iterations — exactly TOTAL requests, each trace
 *                      run an equal number of times. Most reproducible; best
 *                      for apples-to-apples capability comparison.
 *   ramping            ramping-vus — ramp concurrency up/down over STAGES to
 *                      find the saturation point. Duration-based.
 *   constant           constant-vus — hold VUS for DURATION.
 *   shared             shared-iterations — TOTAL requests pulled from a shared
 *                      pool as fast as possible (set is fixed, per-trace count
 *                      is best-effort rather than exact).
 *
 * Tunables: VUS, TOTAL, DURATION, START_VUS, STAGES, MAX_DURATION.
 * STAGES format: "30s:50,1m:200,2m:200,30s:0" (duration:targetVUs, ...)
 */
function buildScenario () {
    switch (SCENARIO) {
        case 'ramping':
            return {
                executor: 'ramping-vus',
                startVUs: Number(__ENV.START_VUS || 0),
                stages: parseStages(__ENV.STAGES) || [
                    { duration: '30s', target: 50 },
                    { duration: '1m', target: 200 },
                    { duration: '2m', target: 200 },
                    { duration: '30s', target: 0 },
                ],
                gracefulRampDown: __ENV.GRACEFUL_RAMP_DOWN || '10s',
            }

        case 'constant':
            return {
                executor: 'constant-vus',
                vus: VUS,
                duration: __ENV.DURATION || '2m',
            }

        case 'shared':
            return {
                executor: 'shared-iterations',
                vus: VUS,
                iterations: TOTAL,
                maxDuration: __ENV.MAX_DURATION || '30m',
            }

        case 'fixed':
        default:
            return {
                executor: 'per-vu-iterations',
                vus: VUS,
                iterations: Math.ceil(TOTAL / VUS), // total = VUS * iterations
                maxDuration: __ENV.MAX_DURATION || '30m',
            }
    }
}

function parseStages (spec) {
    if (! spec) {
        return null
    }

    return spec.split(',').map(stage => {
        const [duration, target] = stage.split(':')

        return { duration: duration.trim(), target: Number(target) }
    })
}

const errorRate = new Rate('errors')

// Object cache metrics are parsed from the Object Cache Pro footnote comment in
// the response body — the same shared `Metrics` class the other scripts use.
// For the real SUT, enable OCP's `analytics.footnote`; the dummy endpoint
// prints a fake footnote so this works for local testing.
const metrics = new Metrics()

export function setup () {
    validateSiteUrl(__ENV.SITE_URL)

    console.info(`Replaying ${TRACES} traces using "${SCENARIO}" scenario`)
}

export default function () {
    // Deterministic, segment-safe trace selection. Each VU walks the trace
    // corpus in order, offset by its global VU id so concurrent VUs work on
    // different traces at any instant (no artificial lockstep on trace 0).
    // `idInTest` is stable across VUs and execution segments, and this mapping
    // needs no shared state, so it is identical every run and never overlaps
    // between load-generator machines — regardless of the chosen scenario.
    const traceId = (exec.vu.iterationInScenario + (exec.vu.idInTest - 1)) % TRACES

    const response = http.get(`${__ENV.SITE_URL}${REPLAY_PATH}?id=${traceId}`, {
        tags: { trace: String(traceId) },
    })

    check(response, {
        'response code is 200': response => response.status === 200,
    })

    errorRate.add(response.status >= 400)
    metrics.addResponseMetrics(response)
}
